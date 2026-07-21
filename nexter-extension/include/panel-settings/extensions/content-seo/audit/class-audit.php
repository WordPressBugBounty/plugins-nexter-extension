<?php
/**
 * Nexter SEO – Site audit engine (real HTTP & options checks).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO\Audit
 */

namespace NexterSEO\Audit;

use Nexter_Content_SEO;
use Nexter_Content_SEO_Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Engine
 */
class Engine {

	const OPTION_LAST     = 'nexter_content_seo_audit_last';
	const OPTION_RUN_STATE = 'nexter_content_seo_audit_run_state';
	const OPTION_HISTORY  = 'nexter_content_seo_audit_history';
	const OPTION_SCHEDULE = 'nexter_content_seo_audit_schedule';

	const CRON_HOOK = 'nexter_seo_audit_cron';
	const HISTORY_LIMIT = 50;

	/** @var string|null */
	private $home_html_cache;

	/** @var array|null Cached published-content sample for this run. */
	private $content_sample;

	/** @var array|null Cached link-probe results for this run (shared by broken-link + redirect checks). */
	private $link_probe_results;

	/** Default minimum word count below which content is considered "thin". */
	const THIN_WORD_THRESHOLD = 300;

	/** Default ceiling on how many published items the content checks sample. */
	const CONTENT_SAMPLE_LIMIT = 400;

	/** Max links probed over the network per run (broken-link / redirect-chain checks). */
	const LINK_PROBE_LIMIT = 20;

	/** Per-request timeout (seconds) for link probing. */
	const LINK_PROBE_TIMEOUT = 5;

	/** Wall-clock budget (seconds) for the whole link-probe phase, so a run can never hang. */
	const LINK_PROBE_BUDGET = 25;

	/** Max redirect hops followed when probing a single link. */
	const REDIRECT_MAX_HOPS = 5;

	/**
	 * Cron callback: store last run without blocking admin.
	 */
	public static function cron_run() {
		if ( ! \apply_filters( 'nexter_seo_audit_cron_enabled', true ) ) {
			return;
		}
		$state = \get_option( self::OPTION_RUN_STATE, array() );
		if ( \is_array( $state ) && ! empty( $state['running'] ) ) {
			return;
		}
		\update_option(
			self::OPTION_RUN_STATE,
			array(
				'running'    => true,
				'started_at' => time(),
				'updated_at' => time(),
			),
			false
		);
		try {
			$engine = new self();
			$result = $engine->run( true, 'cron' );
			self::maybe_send_report_email( $result );
			\do_action( 'nexter_seo_audit_cron_completed', $result );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} finally {
			\update_option(
				self::OPTION_RUN_STATE,
				array(
					'running'    => false,
					'started_at' => 0,
					'updated_at' => time(),
				),
				false
			);
		}
	}

	/**
	 * Send the audit report by email after a scheduled run, when enabled. This was previously
	 * absent — the schedule stored email settings but nothing ever called wp_mail().
	 *
	 * @param array $result Run payload (score + checks).
	 */
	private static function maybe_send_report_email( $result ) {
		$schedule = self::get_schedule();
		if ( empty( $schedule['email_enabled'] ) ) {
			return;
		}
		$to = ! empty( $schedule['email_to'] ) ? $schedule['email_to'] : \get_option( 'admin_email' );
		$to = \sanitize_email( (string) $to );
		if ( ! \is_email( $to ) ) {
			return;
		}

		$score = is_array( $result ) && isset( $result['score'] ) ? (int) $result['score'] : 0;
		$site  = \wp_specialchars_decode( \get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: 1: site name, 2: score out of 100 */
		$subject = sprintf( \__( '[%1$s] SEO audit score: %2$d/100', 'nexter-extension' ), $site, $score );

		$checks   = ( is_array( $result ) && isset( $result['checks'] ) && is_array( $result['checks'] ) ) ? $result['checks'] : array();
		$problems = array_filter(
			$checks,
			static function ( $c ) {
				return isset( $c['status'] ) && in_array( $c['status'], array( 'critical', 'warning', 'suggestion' ), true );
			}
		);

		$lines   = array();
		/* translators: %s: site URL */
		$lines[] = sprintf( \__( 'SEO audit completed for %s', 'nexter-extension' ), \home_url( '/' ) );
		/* translators: %d: overall score out of 100 */
		$lines[] = sprintf( \__( 'Overall score: %d / 100', 'nexter-extension' ), $score );
		$lines[] = '';
		if ( empty( $problems ) ) {
			$lines[] = \__( 'No issues were detected.', 'nexter-extension' );
		} else {
			/* translators: %d: number of issues */
			$lines[] = sprintf( \__( '%d issue(s) found:', 'nexter-extension' ), count( $problems ) );
			foreach ( $problems as $c ) {
				$lines[] = sprintf( '- [%s] %s', strtoupper( (string) $c['status'] ), isset( $c['title'] ) ? (string) $c['title'] : '' );
			}
		}
		$lines[] = '';
		/* translators: %s: admin report URL */
		$lines[] = sprintf( \__( 'View the full report: %s', 'nexter-extension' ), \admin_url( 'admin.php?page=nxt_content_seo' ) );

		$body    = implode( "\n", $lines );
		$subject = \apply_filters( 'nexter_seo_audit_email_subject', $subject, $result, $schedule );
		$body    = \apply_filters( 'nexter_seo_audit_email_body', $body, $result, $schedule );

		\wp_mail( $to, $subject, $body );
	}

	/**
	 * Queue an async audit run and return quick status.
	 *
	 * @return array{scheduled: bool, running: bool}
	 */
	public static function request_async_run() {
		$state   = \get_option( self::OPTION_RUN_STATE, array() );
		$running = \is_array( $state ) && ! empty( $state['running'] );
		if ( $running ) {
			return array(
				'scheduled' => false,
				'running'   => true,
			);
		}
		$scheduled = \wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		return array(
			'scheduled' => (bool) $scheduled,
			'running'   => false,
		);
	}

	/**
	 * Run all checks and persist snapshot.
	 *
	 * @param bool $persist Whether to update OPTION_LAST.
	 * @return array{ run_at: int, checks: array<int, array<string, mixed>>, score: int }
	 */
	public function run( $persist = true, $run_type = 'manual' ) {
		$checks   = array();
		$checks[] = $this->check_www_redirect();
		$checks[] = $this->check_https();
		$checks[] = $this->check_robots_txt();
		$checks[] = $this->check_xml_sitemap();
		$checks[] = $this->check_canonical();
		$checks[] = $this->check_title_length();
		$checks[] = $this->check_meta_description();
		$checks[] = $this->check_h1_count();
		$checks[] = $this->check_image_alt();
		$checks[] = $this->check_tagline();
		$checks[] = $this->check_search_visibility();
		// Content sampling beyond the homepage (duplicate titles/descriptions, thin content,
		// unintended noindex) so a site full of problem posts cannot score "Excellent".
		$checks[] = $this->check_duplicate_titles();
		$checks[] = $this->check_duplicate_descriptions();
		$checks[] = $this->check_thin_content();
		$checks[] = $this->check_noindex_content();
		// Internal-linking + link-health sampling (orphan pages, broken links, redirect chains),
		// so a site with dead links or unlinked pages cannot score "Excellent".
		$checks[] = $this->check_orphan_pages();
		$checks[] = $this->check_broken_links();
		$checks[] = $this->check_redirect_chains();

		$score    = $this->compute_score( $checks );
		$run_type = in_array( $run_type, array( 'manual', 'cron', 'auto' ), true ) ? $run_type : 'manual';
		$prev     = self::get_last_result();
		$payload  = array(
			'run_at'         => time(),
			'checks'         => $checks,
			'score'          => $score,
			'run_type'       => $run_type,
			'previous_score' => is_array( $prev ) && isset( $prev['score'] ) ? (int) $prev['score'] : null,
		);

		if ( $persist ) {
			\update_option( self::OPTION_LAST, $payload, false );
			self::push_history( $payload );
		}

		return $payload;
	}

	/**
	 * Append a slim history record (run_at, score, run_type) capped at HISTORY_LIMIT.
	 *
	 * @param array $payload Latest run payload.
	 */
	private static function push_history( array $payload ) {
		$history = \get_option( self::OPTION_HISTORY, array() );
		if ( ! \is_array( $history ) ) {
			$history = array();
		}
		$history[] = array(
			'run_at'   => isset( $payload['run_at'] ) ? (int) $payload['run_at'] : time(),
			'score'    => isset( $payload['score'] ) ? (int) $payload['score'] : 0,
			'run_type' => isset( $payload['run_type'] ) ? (string) $payload['run_type'] : 'manual',
		);
		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}
		\update_option( self::OPTION_HISTORY, $history, false );
	}

	/**
	 * @return array History records.
	 */
	public static function get_history() {
		$h = \get_option( self::OPTION_HISTORY, array() );
		return \is_array( $h ) ? $h : array();
	}

	/**
	 * Default schedule (off; field defaults).
	 *
	 * @return array
	 */
	public static function default_schedule() {
		return array(
			'frequency'     => 'off',
			'time'          => '17:00',
			'day'           => 'sun',
			'month_day'     => 1,
			'email_enabled' => false,
			'email_to'      => '',
		);
	}

	/**
	 * @return array Stored schedule merged over defaults.
	 */
	public static function get_schedule() {
		$stored = \get_option( self::OPTION_SCHEDULE, array() );
		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_schedule(), $stored );
	}

	/**
	 * Persist schedule and reschedule cron event.
	 *
	 * @param array $input Raw input.
	 * @return array Saved schedule.
	 */
	public static function save_schedule( array $input ) {
		$defaults  = self::default_schedule();
		$frequency = isset( $input['frequency'] ) ? (string) $input['frequency'] : $defaults['frequency'];
		if ( ! in_array( $frequency, array( 'off', 'daily', 'weekly', 'monthly' ), true ) ) {
			$frequency = 'off';
		}
		$time = isset( $input['time'] ) ? (string) $input['time'] : $defaults['time'];
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			$time = $defaults['time'];
		}
		$day = isset( $input['day'] ) ? strtolower( (string) $input['day'] ) : $defaults['day'];
		if ( ! in_array( $day, array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ), true ) ) {
			$day = $defaults['day'];
		}
		// Day of month for the "monthly" frequency: 1–28 (avoids skipped short months) or 'last'.
		$month_day = isset( $input['month_day'] ) ? $input['month_day'] : $defaults['month_day'];
		if ( 'last' === $month_day ) {
			$month_day = 'last';
		} else {
			$month_day = (int) $month_day;
			if ( $month_day < 1 || $month_day > 28 ) {
				$month_day = 1;
			}
		}
		$schedule = array(
			'frequency'     => $frequency,
			'time'          => $time,
			'day'           => $day,
			'month_day'     => $month_day,
			'email_enabled' => ! empty( $input['email_enabled'] ),
			'email_to'      => isset( $input['email_to'] ) ? \sanitize_email( (string) $input['email_to'] ) : '',
		);
		\update_option( self::OPTION_SCHEDULE, $schedule, false );
		self::reschedule_cron( $schedule );
		return $schedule;
	}

	/**
	 * Compute next-run timestamp from a schedule, or null if Off.
	 *
	 * @param array|null $schedule Schedule array; defaults to stored.
	 * @return int|null
	 */
	public static function next_run_timestamp( $schedule = null ) {
		if ( $schedule === null ) {
			$schedule = self::get_schedule();
		}
		if ( empty( $schedule['frequency'] ) || $schedule['frequency'] === 'off' ) {
			return null;
		}
		$next = \wp_next_scheduled( self::CRON_HOOK );
		return $next ? (int) $next : null;
	}

	/**
	 * Clear and re-schedule cron based on current schedule option.
	 *
	 * @param array|null $schedule Schedule array; defaults to stored.
	 */
	public static function reschedule_cron( $schedule = null ) {
		if ( $schedule === null ) {
			$schedule = self::get_schedule();
		}
		\wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( $schedule['frequency'] === 'off' ) {
			return;
		}
		$first = self::compute_first_run( $schedule );
		$recurrence = self::recurrence_for_frequency( $schedule['frequency'] );
		\wp_schedule_event( $first, $recurrence, self::CRON_HOOK );
	}

	/**
	 * @param array $schedule Schedule array.
	 * @return int Unix ts of next firing.
	 */
	private static function compute_first_run( array $schedule ) {
		list( $hour, $minute ) = explode( ':', $schedule['time'] );
		$hour   = (int) $hour;
		$minute = (int) $minute;
		$tz     = \wp_timezone();
		$now    = new \DateTimeImmutable( 'now', $tz );
		$today  = $now->setTime( $hour, $minute, 0 );
		switch ( $schedule['frequency'] ) {
			case 'daily':
				$target = $today > $now ? $today : $today->modify( '+1 day' );
				break;
			case 'weekly':
				$dayMap = array( 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 );
				$want   = $dayMap[ $schedule['day'] ] ?? 0;
				$delta  = ( $want - (int) $today->format( 'w' ) + 7 ) % 7;
				$target = $today->modify( '+' . $delta . ' day' );
				if ( $target <= $now ) {
					$target = $target->modify( '+7 day' );
				}
				break;
			case 'monthly':
				$month_day = isset( $schedule['month_day'] ) ? $schedule['month_day'] : 1;
				if ( 'last' === $month_day ) {
					$target = $today->modify( 'last day of this month' )->setTime( $hour, $minute, 0 );
					if ( $target <= $now ) {
						$target = $today->modify( 'last day of next month' )->setTime( $hour, $minute, 0 );
					}
				} else {
					$dom    = max( 1, min( 28, (int) $month_day ) );
					$target = $today->setDate( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), $dom );
					if ( $target <= $now ) {
						$next   = $today->modify( 'first day of next month' );
						$target = $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $dom )->setTime( $hour, $minute, 0 );
					}
				}
				break;
			default:
				$target = $today->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	/**
	 * @param string $freq Frequency.
	 * @return string WP cron recurrence slug.
	 */
	private static function recurrence_for_frequency( $freq ) {
		switch ( $freq ) {
			case 'daily':
				return 'daily';
			case 'monthly':
				return 'nexter_seo_monthly';
			case 'weekly':
			default:
				return 'weekly';
		}
	}

	/**
	 * Register custom cron schedule(s).
	 *
	 * @param array $schedules Existing.
	 * @return array
	 */
	public static function filter_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['nexter_seo_monthly'] ) ) {
			$schedules['nexter_seo_monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly (Nexter SEO)', 'nexter-extension' ),
			);
		}
		return $schedules;
	}

	/**
	 * @return array|null
	 */
	public static function get_last_result() {
		$raw = \get_option( self::OPTION_LAST, null );
		return \is_array( $raw ) ? $raw : null;
	}

	/**
	 * @param array<int, array<string, mixed>> $checks Checks.
	 * @return int 0–100
	 */
	private function compute_score( array $checks ) {
		// Weighted health ratio: every scored check contributes fractional credit by its result
		// severity, so the score reflects the real proportion of healthy checks. Passing checks
		// ALWAYS count toward the score. The previous subtract-only model (100 − 25/critical −
		// 8/warning − 3/suggestion) went negative and floored at 0 the moment a few issues stacked
		// up (e.g. 2 critical + 7 warning + 2 suggestion → −12 → 0), which made a partially-healthy
		// site read as a broken "0 / 100" and hid the passing checks entirely.
		$weights = array(
			'passed'     => 1.0,
			'suggestion' => 0.6,
			'warning'    => 0.35,
			'critical'   => 0.0,
		);

		$critical = 0;
		$warning  = 0;
		$earned   = 0.0;
		$total    = 0;
		foreach ( $checks as $c ) {
			$st = isset( $c['status'] ) ? (string) $c['status'] : 'passed';
			// Informational / skipped rows carry no weight and must not dilute the score.
			if ( ! isset( $weights[ $st ] ) ) {
				continue;
			}
			++$total;
			$earned += $weights[ $st ];
			if ( 'critical' === $st ) {
				++$critical;
			} elseif ( 'warning' === $st ) {
				++$warning;
			}
		}

		if ( $total < 1 ) {
			return 100; // No scorable checks — nothing is wrong.
		}

		$score = (int) round( 100 * ( $earned / $total ) );

		// Severity band caps so the label can't lie: any Critical keeps the site out of the
		// "Good"/"Excellent" bands (scoreLabelFor: <50 = Poor), and any Warning keeps it out of
		// "Excellent" (>=75 = Good ceiling). A broken site can no longer read as 90/Excellent.
		if ( $critical > 0 ) {
			$score = \min( $score, 49 );
		} elseif ( $warning > 0 ) {
			$score = \min( $score, 74 );
		}

		return (int) \max( 0, \min( 100, $score ) );
	}

	/**
	 * @param string               $id          Issue id.
	 * @param string               $status      critical|warning|suggestion|passed.
	 * @param string               $title       Title.
	 * @param string               $message     Message.
	 * @param string               $recommendation Recommendation text.
	 * @param bool                 $fix_available Fix available.
	 * @param string               $fix_issue_id  ID for fix endpoint.
	 * @return array<string, mixed>
	 */
	private function item( $id, $status, $title, $message, $recommendation = '', $fix_available = false, $fix_issue_id = '', $count = null ) {
		$out = array(
			'id'              => $id,
			'status'          => $status,
			'title'           => $title,
			'message'         => $message,
			'recommendation'  => $recommendation,
			'fix_available'   => (bool) $fix_available,
			'fix_issue_id'    => $fix_issue_id ? (string) $fix_issue_id : '',
			'fix_callback'    => $fix_issue_id ? 'nexter_seo_audit_fix_' . $fix_issue_id : '',
		);
		// Optional numeric detail (e.g. how many items failed a check) consumed by the dashboard
		// module-status cards. Only emitted when a check supplies it, so existing callers are
		// unaffected.
		if ( null !== $count ) {
			$out['count'] = (int) $count;
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function is_local_site() {
		// Local/dev hosts where the server can't reliably reach its own public URL over HTTP
		// (loopback frequently can't resolve the public host from inside the container). The
		// HTTP-dependent audit checks skip on these hosts instead of emitting false-positive
		// warnings, matching the long-standing behaviour of the WWW-redirect check.
		$host = (string) \wp_parse_url( \home_url( '/' ), PHP_URL_HOST );
		return (bool) \preg_match( '/(^localhost$)|(^127\.0\.0\.1$)|(^::1$)|(\.local$)|(\.test$)|(\.localhost$)/i', $host );
	}

	/**
	 * Neutral "skipped on local dev" result for an HTTP-dependent check, so developers don't see
	 * a wall of false-positive warnings when the site can't be fetched over HTTP from the server.
	 *
	 * @param string $id    Check id.
	 * @param string $title Human-readable check title.
	 * @return array<string, mixed>
	 */
	private function local_skip_item( $id, $title ) {
		return $this->item(
			$id,
			'passed',
			$title,
			\__( 'Skipped on local development environment (the site is not reachable over HTTP from the server).', 'nexter-extension' ),
			'',
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_www_redirect() {
		$home = \home_url( '/' );
		$part = \wp_parse_url( $home );
		if ( empty( $part['host'] ) ) {
			return $this->item( 'www_redirect', 'passed', \__( 'WWW vs non-WWW', 'nexter-extension' ), \__( 'Could not parse site URL.', 'nexter-extension' ), '', false, '' );
		}
		if ( $this->is_local_site() ) {
			return $this->item( 'www_redirect', 'passed', \__( 'WWW vs non-WWW redirect', 'nexter-extension' ), \__( 'Skipped on local development host.', 'nexter-extension' ), '', false, '' );
		}
		$host = $part['host'];

		$scheme = ! empty( $part['scheme'] ) ? $part['scheme'] : 'https';
		$is_www = ( \stripos( $host, 'www.' ) === 0 );
		$alt_host = $is_www ? \substr( $host, 4 ) : 'www.' . $host;
		$primary  = $scheme . '://' . $host . '/';
		$alternate = $scheme . '://' . $alt_host . '/';

		$http_args = array(
			'timeout'     => 8,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array( 'User-Agent' => 'NexterSEO-Audit/1.0; ' . \home_url( '/' ) ),
		);

		$r1 = \wp_remote_get(
			$primary,
			$http_args
		);
		$r2 = \wp_remote_get(
			$alternate,
			$http_args
		);

		$c1 = \is_wp_error( $r1 ) ? 0 : (int) \wp_remote_retrieve_response_code( $r1 );
		$c2 = \is_wp_error( $r2 ) ? 0 : (int) \wp_remote_retrieve_response_code( $r2 );
		if ( \is_wp_error( $r1 ) && \is_wp_error( $r2 ) ) {
			return $this->item(
				'www_redirect',
				'suggestion',
				\__( 'WWW vs non-WWW redirect', 'nexter-extension' ),
				\__( 'Could not test host redirect behavior from this server environment (loopback/self-request may be blocked).', 'nexter-extension' ),
				\__( 'Verify redirect behavior with an external HTTP checker or browser dev tools.', 'nexter-extension' ),
				false,
				''
			);
		}

		if ( $c1 === 200 && $c2 === 200 ) {
			return $this->item(
				'www_redirect',
				'critical',
				\__( 'WWW vs non-WWW redirect', 'nexter-extension' ),
				\__( 'Both WWW and non-WWW URLs return HTTP 200 without redirecting to a single canonical host. Search engines may see duplicate content.', 'nexter-extension' ),
				\__( 'Choose one preferred domain in hosting or WordPress and issue a 301 redirect from the other.', 'nexter-extension' ),
				false,
				''
			);
		}

		if ( \in_array( $c1, array( 301, 302, 307, 308 ), true ) || \in_array( $c2, array( 301, 302, 307, 308 ), true ) ) {
			return $this->item( 'www_redirect', 'passed', \__( 'WWW vs non-WWW redirect', 'nexter-extension' ), \__( 'Alternate host responds with a redirect (good for canonical consistency).', 'nexter-extension' ), '', false, '' );
		}

		if ( $c1 === 200 xor $c2 === 200 ) {
			return $this->item( 'www_redirect', 'passed', \__( 'WWW vs non-WWW redirect', 'nexter-extension' ), \__( 'Only one host variant appears to return 200 for the homepage.', 'nexter-extension' ), '', false, '' );
		}

		return $this->item(
			'www_redirect',
			'warning',
			\__( 'WWW vs non-WWW redirect', 'nexter-extension' ),
			\__( 'Could not confirm redirect behaviour (requests failed or returned unexpected status codes).', 'nexter-extension' ),
			\__( 'Verify in a browser or server config that one hostname 301-redirects to the other.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_https() {
		$url = (string) \get_option( 'siteurl', '' );
		if ( $url && \strpos( $url, 'https://' ) === 0 ) {
			return $this->item( 'https', 'passed', \__( 'HTTPS', 'nexter-extension' ), \__( 'Site URL uses HTTPS.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'https',
			'critical',
			\__( 'HTTPS', 'nexter-extension' ),
			\__( 'WordPress site URL is not using HTTPS.', 'nexter-extension' ),
			\__( 'Install an SSL certificate and update WordPress Site Address to https:// in Settings → General.', 'nexter-extension' ),
			true,
			'https_upgrade'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_robots_txt() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'robots_txt', \__( 'robots.txt', 'nexter-extension' ) );
		}
		$robots = \home_url( '/robots.txt' );
		$r      = \wp_remote_get(
			$robots,
			array(
				'timeout'   => 12,
				'sslverify' => true,
				'headers'   => array( 'User-Agent' => 'NexterSEO-Audit/1.0' ),
			)
		);
		$code = \is_wp_error( $r ) ? 0 : (int) \wp_remote_retrieve_response_code( $r );
		if ( $code === 404 ) {
			return $this->item(
				'robots_txt',
				'warning',
				\__( 'robots.txt', 'nexter-extension' ),
				\__( 'robots.txt returned 404. Crawlers may lack crawl guidance.', 'nexter-extension' ),
				\__( 'Add a robots.txt file at the site root or use a plugin that provides one.', 'nexter-extension' ),
				false,
				''
			);
		}
		if ( $code < 200 || $code >= 400 ) {
			return $this->item(
				'robots_txt',
				'warning',
				\__( 'robots.txt', 'nexter-extension' ),
				sprintf(
					/* translators: %d: HTTP status */
					\__( 'robots.txt could not be read reliably (HTTP %d).', 'nexter-extension' ),
					$code
				),
				\__( 'Ensure /robots.txt is publicly reachable.', 'nexter-extension' ),
				false,
				''
			);
		}
		return $this->item( 'robots_txt', 'passed', \__( 'robots.txt', 'nexter-extension' ), \__( 'robots.txt responds successfully.', 'nexter-extension' ), '', false, '' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_xml_sitemap() {
		$opts = Nexter_Content_SEO::get_options();

		// On local/dev hosts the loopback HTTP probe to /sitemap.xml is unreliable (connection
		// refused / HTTP 0), so trust the stored setting instead of reporting a false-positive
		// "no sitemap found" warning.
		if ( $this->is_local_site() ) {
			if ( ! empty( $opts['enable_xml_sitemap'] ) ) {
				return $this->item( 'xml_sitemap', 'passed', \__( 'XML sitemap', 'nexter-extension' ), \__( 'XML sitemap is enabled in settings (HTTP verification skipped on local development environment).', 'nexter-extension' ), '', false, '' );
			}
			return $this->item(
				'xml_sitemap',
				'warning',
				\__( 'XML sitemap', 'nexter-extension' ),
				\__( 'The Nexter XML sitemap is disabled in settings.', 'nexter-extension' ),
				\__( 'Enable the Nexter XML sitemap under Technical → Sitemaps.', 'nexter-extension' ),
				true,
				'enable_nexter_sitemap'
			);
		}

		$urls = array();
		if ( ! empty( $opts['enable_xml_sitemap'] ) && \class_exists( 'Nexter_Content_SEO_Sitemap' ) ) {
			$urls[] = Nexter_Content_SEO_Sitemap::get_sitemap_url();
		}
		$urls[] = \home_url( '/sitemap.xml' );
		$urls[] = \home_url( '/wp-sitemap.xml' );

		$urls = \array_unique( \array_filter( $urls ) );

		foreach ( $urls as $u ) {
			$r = \wp_remote_get(
				$u,
				array(
					'timeout'     => 12,
					'sslverify'   => true,
					'redirection' => 3,
					'headers'     => array( 'User-Agent' => 'NexterSEO-Audit/1.0' ),
				)
			);
			if ( \is_wp_error( $r ) ) {
				continue;
			}
			$code = (int) \wp_remote_retrieve_response_code( $r );
			$body = (string) \wp_remote_retrieve_body( $r );
			if ( $code === 200 && ( \stripos( $body, '<urlset' ) !== false || \stripos( $body, 'sitemapindex' ) !== false || \stripos( $body, 'sitemap' ) !== false ) ) {
				return $this->item( 'xml_sitemap', 'passed', \__( 'XML sitemap', 'nexter-extension' ), \__( 'An XML sitemap is reachable.', 'nexter-extension' ), '', false, '' );
			}
		}

		$fix = ! empty( $opts['enable_xml_sitemap'] ) ? false : true;
		return $this->item(
			'xml_sitemap',
			'warning',
			\__( 'XML sitemap', 'nexter-extension' ),
			\__( 'No XML sitemap found at /sitemap.xml or /wp-sitemap.xml (or Nexter sitemap is disabled).', 'nexter-extension' ),
			\__( 'Enable the Nexter XML sitemap or ensure WordPress core sitemap is available.', 'nexter-extension' ),
			$fix,
			$fix ? 'enable_nexter_sitemap' : ''
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	private function fetch_homepage_html() {
		if ( null !== $this->home_html_cache ) {
			return $this->home_html_cache;
		}
		$url = \home_url( '/' );
		$r   = \wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'sslverify'   => true,
				'redirection' => 3,
				'headers'     => array( 'User-Agent' => 'NexterSEO-Audit/1.0' ),
			)
		);
		if ( \is_wp_error( $r ) ) {
			// Do NOT cache the error — a single transient network blip shouldn't poison every
			// subsequent homepage-based check in this run; let each one retry the fetch.
			return $r;
		}
		$body                    = (string) \wp_remote_retrieve_body( $r );
		$this->home_html_cache = $body;
		return $body;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_canonical() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'canonical', \__( 'Canonical tag', 'nexter-extension' ) );
		}
		$html = $this->fetch_homepage_html();
		if ( \is_wp_error( $html ) ) {
			return $this->item(
				'canonical',
				'warning',
				\__( 'Canonical tag', 'nexter-extension' ),
				\__( 'Could not load the homepage HTML to check the canonical link.', 'nexter-extension' ),
				\__( 'Ensure the front page loads and is not blocked.', 'nexter-extension' ),
				false,
				''
			);
		}
		if ( \preg_match( '/<link[^>]+rel\s*=\s*["\']canonical["\'][^>]*>/i', $html ) ) {
			return $this->item( 'canonical', 'passed', \__( 'Canonical tag', 'nexter-extension' ), \__( 'Homepage HTML includes a rel=canonical link.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'canonical',
			'warning',
			\__( 'Canonical tag', 'nexter-extension' ),
			\__( 'No rel=canonical link was found on the homepage HTML.', 'nexter-extension' ),
			\__( 'Add a canonical URL via your SEO plugin or theme to avoid duplicate URL issues.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_title_length() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'title_length', \__( 'Title tag length', 'nexter-extension' ) );
		}
		$html = $this->fetch_homepage_html();
		if ( \is_wp_error( $html ) ) {
			return $this->item( 'title_length', 'warning', \__( 'Title tag length', 'nexter-extension' ), \__( 'Could not read the homepage to measure the title.', 'nexter-extension' ), '', false, '' );
		}
		if ( ! \preg_match( '/<title[^>]*>([^<]+)<\/title>/is', $html, $m ) ) {
			return $this->item( 'title_length', 'warning', \__( 'Title tag length', 'nexter-extension' ), \__( 'No <title> tag was found on the homepage.', 'nexter-extension' ), \__( 'Set a descriptive title in WordPress or your SEO settings.', 'nexter-extension' ), false, '' );
		}
		$title = \trim( \html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$len   = \function_exists( 'mb_strlen' ) ? \mb_strlen( $title ) : \strlen( $title );
		if ( $len >= 50 && $len <= 60 ) {
			/* translators: %d: title length in characters */
			return $this->item( 'title_length', 'passed', \__( 'Title tag length', 'nexter-extension' ), sprintf( \__( 'Title length is %d characters (within the 50–60 range).', 'nexter-extension' ), $len ), '', false, '' );
		}
		return $this->item(
			'title_length',
			'warning',
			\__( 'Title tag length', 'nexter-extension' ),
			sprintf(
				/* translators: 1: character count */
				\__( 'Title length is %1$d characters. Aim for roughly 50–60 for best display in search results.', 'nexter-extension' ),
				$len
			),
			\__( 'Edit the site or homepage title template in On-Page → Meta Template.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_meta_description() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'meta_description', \__( 'Meta description', 'nexter-extension' ) );
		}
		$html = $this->fetch_homepage_html();
		if ( \is_wp_error( $html ) ) {
			return $this->item( 'meta_description', 'warning', \__( 'Meta description', 'nexter-extension' ), \__( 'Could not read the homepage to check meta description.', 'nexter-extension' ), '', false, '' );
		}
		if ( ! \preg_match( '/<meta\s+[^>]*name\s*=\s*["\']description["\'][^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>|<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']description["\'][^>]*>/is', $html, $m ) ) {
			return $this->item(
				'meta_description',
				'warning',
				\__( 'Meta description', 'nexter-extension' ),
				\__( 'No meta description tag was found on the homepage.', 'nexter-extension' ),
				\__( 'Add a unique meta description for the homepage (Nexter SEO → Home Page or meta templates).', 'nexter-extension' ),
				false,
				''
			);
		}
		$desc = \trim( \html_entity_decode( ! empty( $m[1] ) ? $m[1] : $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$len  = \function_exists( 'mb_strlen' ) ? \mb_strlen( $desc ) : \strlen( $desc );
		if ( $len >= 120 && $len <= 160 ) {
			/* translators: %d: meta description length in characters */
			return $this->item( 'meta_description', 'passed', \__( 'Meta description', 'nexter-extension' ), sprintf( \__( 'Meta description length is %d characters (within 120–160).', 'nexter-extension' ), $len ), '', false, '' );
		}
		return $this->item(
			'meta_description',
			'warning',
			\__( 'Meta description', 'nexter-extension' ),
			sprintf(
				/* translators: %1$d: meta description length in characters */
				\__( 'Meta description length is %1$d characters. Aim for about 120–160 characters.', 'nexter-extension' ),
				$len
			),
			\__( 'Refine the homepage description for clearer snippets in search results.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_h1_count() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'h1_count', \__( 'H1 heading count', 'nexter-extension' ) );
		}
		$html = $this->fetch_homepage_html();
		if ( \is_wp_error( $html ) ) {
			return $this->item( 'h1_count', 'warning', \__( 'H1 heading count', 'nexter-extension' ), \__( 'Could not count H1 tags on the homepage.', 'nexter-extension' ), '', false, '' );
		}
		$n = \preg_match_all( '/<h1\b[^>]*>/i', $html, $m );
		if ( false === $n ) {
			$n = 0;
		}
		if ( (int) $n === 1 ) {
			return $this->item( 'h1_count', 'passed', \__( 'H1 heading count', 'nexter-extension' ), \__( 'Homepage has exactly one H1.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'h1_count',
			'warning',
			\__( 'H1 heading count', 'nexter-extension' ),
			sprintf(
				/* translators: %d: number of H1 tags */
				\__( 'Homepage has %d H1 tags. A single H1 is recommended for clarity.', 'nexter-extension' ),
				(int) $n
			),
			\__( 'Adjust your theme or front page content so only one H1 is present.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_image_alt() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'image_alt', \__( 'Image ALT attributes', 'nexter-extension' ) );
		}
		$html = $this->fetch_homepage_html();
		if ( \is_wp_error( $html ) ) {
			return $this->item( 'image_alt', 'warning', \__( 'Image ALT attributes', 'nexter-extension' ), \__( 'Could not scan images on the homepage.', 'nexter-extension' ), '', false, '' );
		}
		if ( ! \preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) || empty( $tags[0] ) ) {
			return $this->item(
				'image_alt',
				'suggestion',
				\__( 'Image ALT attributes', 'nexter-extension' ),
				\__( 'No images found on the homepage HTML (neutral signal).', 'nexter-extension' ),
				\__( 'If relevant to your content, add meaningful images with descriptive ALT text.', 'nexter-extension' ),
				false,
				''
			);
		}
		$missing = 0;
		foreach ( $tags[0] as $tag ) {
			// Match alt in double-quoted, single-quoted, OR unquoted form — the old quoted-only
			// regex flagged a valid unquoted alt=foo as missing (false positive).
			if ( ! \preg_match( '/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $am ) ) {
				++$missing;
				continue;
			}
			$val = '';
			if ( isset( $am[1] ) && '' !== $am[1] ) {
				$val = $am[1];
			} elseif ( isset( $am[2] ) && '' !== $am[2] ) {
				$val = $am[2];
			} elseif ( isset( $am[3] ) ) {
				$val = $am[3];
			}
			if ( '' === \trim( $val ) ) {
				++$missing;
			}
		}
		if ( 0 === $missing ) {
			return $this->item( 'image_alt', 'passed', \__( 'Image ALT attributes', 'nexter-extension' ), \__( 'All homepage images include non-empty ALT text.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'image_alt',
			'warning',
			\__( 'Image ALT attributes', 'nexter-extension' ),
			sprintf(
				/* translators: %d: count */
				\__( '%d image(s) on the homepage are missing usable ALT text.', 'nexter-extension' ),
				$missing
			),
			\__( 'Add descriptive ALT attributes for accessibility and image SEO.', 'nexter-extension' ),
			false,
			'',
			$missing
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_tagline() {
		$d = (string) \get_option( 'blogdescription', '' );
		if ( '' !== \trim( $d ) ) {
			return $this->item( 'tagline', 'passed', \__( 'Site tagline', 'nexter-extension' ), \__( 'Tagline is set in WordPress settings.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'tagline',
			'warning',
			\__( 'Site tagline', 'nexter-extension' ),
			\__( 'WordPress tagline (blog description) is empty.', 'nexter-extension' ),
			\__( 'Set a short tagline under Settings → General.', 'nexter-extension' ),
			true,
			'tagline_wp'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_search_visibility() {
		if ( (int) \get_option( 'blog_public', 1 ) === 1 ) {
			return $this->item( 'search_visibility', 'passed', \__( 'Search engine visibility', 'nexter-extension' ), \__( 'Site is not set to discourage search engines.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'search_visibility',
			'critical',
			\__( 'Search engine visibility', 'nexter-extension' ),
			\__( 'WordPress is configured to discourage search engines from indexing the site.', 'nexter-extension' ),
			\__( 'Uncheck “Discourage search engines…” in Settings → Reading unless you intentionally block indexing.', 'nexter-extension' ),
			true,
			'search_visibility'
		);
	}

	/**
	 * Sample published content once per run (single post query, primed meta cache).
	 *
	 * @return array{posts: array<int, array<string, mixed>>, total: int, limited: bool}
	 */
	private function get_content_sample() {
		if ( null !== $this->content_sample ) {
			return $this->content_sample;
		}

		$limit = (int) \apply_filters( 'nexter_seo_audit_content_sample_limit', self::CONTENT_SAMPLE_LIMIT );
		$limit = \max( 1, \min( 2000, $limit ) );

		$types = \get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		if ( empty( $types ) ) {
			$this->content_sample = array(
				'posts'   => array(),
				'total'   => 0,
				'limited' => false,
			);
			return $this->content_sample;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => \array_values( $types ),
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'lazy_load_term_meta'    => false,
			)
		);

		// Title template context, resolved once, so the sample can carry the *rendered* <title>
		// each page actually emits (not just the stored post/SEO title). This is what exposes
		// template-driven site-wide duplication (e.g. a template missing %post_title%).
		$opts             = \class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$global_title_tpl = ! empty( $opts['search_title_template'] ) ? (string) $opts['search_title_template'] : '%post_title% - %site_name%';
		$blogname         = (string) \get_bloginfo( 'name' );
		$tagline          = (string) \get_bloginfo( 'description' );

		list( $home_origin, $home_host ) = $this->home_origin_parts();

		$posts     = array();
		$link_pool = array();
		foreach ( $query->posts as $p ) {
			if ( ! $p instanceof \WP_Post ) {
				continue;
			}
			$seo_title  = \trim( (string) \get_post_meta( $p->ID, '_nxt_seo_title', true ) );
			$post_title = \trim( \wp_strip_all_tags( (string) $p->post_title ) );
			$noindex    = \strtolower( (string) \get_post_meta( $p->ID, '_nxt_seo_noindex', true ) );

			// The per-post SEO title (if set) is itself a template; otherwise the global template
			// applies. Resolve it against this post's tokens to get the rendered <title>.
			$title_template = '' !== $seo_title ? $seo_title : $global_title_tpl;
			$rendered_title = $this->resolve_effective_title( $title_template, $post_title, $blogname, $tagline );

			$permalink = (string) \get_permalink( $p->ID );
			$self_path = $this->to_internal_path( $permalink, $home_host );

			// Extract links (href/src) from content ONCE. Internal targets (minus self-links) feed
			// the orphan-page check; the deduped absolute-URL pool feeds the bounded network probe
			// used by the broken-link and redirect-chain checks.
			$abs_links        = $this->extract_links( (string) $p->post_content, $home_origin );
			$internal_targets = array();
			foreach ( $abs_links as $lnk ) {
				$link_pool[ $lnk ] = true;
				$ipath = $this->to_internal_path( $lnk, $home_host );
				if ( null !== $ipath && $ipath !== $self_path ) {
					$internal_targets[ $ipath ] = true;
				}
			}

			$posts[] = array(
				'id'               => (int) $p->ID,
				'type'             => (string) $p->post_type,
				'title'            => '' !== $seo_title ? $seo_title : $post_title,
				'rendered_title'   => $rendered_title,
				'desc'             => \trim( (string) \get_post_meta( $p->ID, '_nxt_seo_description', true ) ),
				'words'            => $this->count_words( (string) $p->post_content ),
				'noindex'          => \in_array( $noindex, array( '1', 'yes', 'true', 'on' ), true ),
				'path'             => $self_path,
				'internal_targets' => \array_keys( $internal_targets ),
			);
		}

		$this->content_sample = array(
			'posts'     => $posts,
			'total'     => \count( $posts ),
			'limited'   => \count( $posts ) >= $limit,
			'link_pool' => \array_slice( \array_keys( $link_pool ), 0, 200 ),
		);
		return $this->content_sample;
	}

	/**
	 * Resolve a title template to the rendered <title> for a given post, substituting the content
	 * and site tokens the title module uses. Approximation good enough to detect duplication: if a
	 * template omits the per-item token (%post_title%/%term_title%), every post resolves to the
	 * SAME string here — which is exactly the site-wide duplicate-title symptom to flag.
	 *
	 * @param string $template   Title template (per-post SEO title or the global template).
	 * @param string $post_title Raw post title.
	 * @param string $blogname   Site name.
	 * @param string $tagline    Site tagline.
	 * @return string
	 */
	private function resolve_effective_title( $template, $post_title, $blogname, $tagline ) {
		$template = (string) $template;
		if ( '' === \trim( $template ) ) {
			$template = '%post_title%';
		}
		$sep  = '-';
		$repl = array(
			'%post_title%'       => $post_title,
			'%post.title%'       => $post_title,
			'%title%'            => $post_title,
			'%term_title%'       => $post_title,
			'%site_name%'        => $blogname,
			'%site.title%'       => $blogname,
			'%sitename%'         => $blogname,
			'%tagline%'          => $tagline,
			'%site.description%' => $tagline,
			'%sep%'              => $sep,
			'%separator%'        => $sep,
			'%page%'             => '',
			'%current_year%'     => \gmdate( 'Y' ),
		);
		$out = \strtr( $template, $repl );
		// Drop any remaining unresolved %tokens% and collapse whitespace/dangling separators.
		$out = \preg_replace( '/%[a-z0-9_.]+%/i', '', (string) $out );
		$out = \preg_replace( '/\s{2,}/', ' ', (string) $out );
		return \trim( (string) $out );
	}

	/**
	 * Multibyte-aware word count (shortcodes and tags stripped).
	 *
	 * @param string $content Raw post content.
	 * @return int
	 */
	private function count_words( $content ) {
		$text = \wp_strip_all_tags( \strip_shortcodes( (string) $content ) );
		$text = \preg_replace( '/\s+/u', ' ', (string) $text );
		$text = \trim( (string) $text );
		if ( '' === $text ) {
			return 0;
		}
		$parts = \preg_split( '/\s+/u', $text );
		return \is_array( $parts ) ? \count( $parts ) : 0;
	}

	/**
	 * Append a "sampled N items" honesty note when the content scan was capped.
	 *
	 * @param array  $sample  Sample payload.
	 * @param string $message Base message.
	 * @return string
	 */
	private function sample_suffix( array $sample, $message ) {
		if ( ! empty( $sample['limited'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of items analyzed */
				\__( '(Analysis limited to the %d most recently modified items.)', 'nexter-extension' ),
				(int) $sample['total']
			);
		}
		return $message;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_duplicate_titles() {
		$sample = $this->get_content_sample();
		if ( empty( $sample['posts'] ) ) {
			return $this->item( 'duplicate_titles', 'passed', \__( 'Duplicate titles', 'nexter-extension' ), \__( 'No published content to analyze for duplicate titles.', 'nexter-extension' ), '', false, '' );
		}
		// Compare the RENDERED <title> each page emits (template resolved per post), not the stored
		// post/SEO title — otherwise template-driven duplication (a title template with no
		// %post_title% making every page identical) is invisible.
		$counts = array();
		foreach ( $sample['posts'] as $p ) {
			$rendered = isset( $p['rendered_title'] ) ? (string) $p['rendered_title'] : (string) $p['title'];
			$key      = \strtolower( \trim( $rendered ) );
			if ( '' === $key ) {
				continue;
			}
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		$dupes = 0;
		foreach ( $counts as $c ) {
			if ( $c > 1 ) {
				$dupes += $c;
			}
		}
		if ( 0 === $dupes ) {
			return $this->item( 'duplicate_titles', 'passed', \__( 'Duplicate titles', 'nexter-extension' ), $this->sample_suffix( $sample, \__( 'All sampled pages render a unique title.', 'nexter-extension' ) ), '', false, '' );
		}

		// Root-cause hint: if the global title template carries no per-item token, EVERY page
		// renders the same <title>. Surface that instead of a generic "revise post titles".
		$opts         = \class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$title_tpl    = ! empty( $opts['search_title_template'] ) ? (string) $opts['search_title_template'] : '';
		$has_item_tok = (bool) \preg_match( '/%(post_title|post\.title|title|term_title)%/i', $title_tpl );
		$recommendation = $has_item_tok
			? \__( 'Give each page a unique SEO title (Nexter SEO meta box) or revise duplicated post titles.', 'nexter-extension' )
			: \__( 'Your title template has no per-page token, so every page renders the same title. Add %post_title% (and %term_title% for archives) to the title template under On-Page → Meta Template.', 'nexter-extension' );

		return $this->item(
			'duplicate_titles',
			'critical',
			\__( 'Duplicate titles', 'nexter-extension' ),
			$this->sample_suffix(
				$sample,
				sprintf(
					/* translators: %d: number of items */
					\__( '%d published pages render the same <title> as another page. Duplicate titles confuse search engines and split ranking signals.', 'nexter-extension' ),
					$dupes
				)
			),
			$recommendation,
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_duplicate_descriptions() {
		$sample = $this->get_content_sample();
		$counts = array();
		foreach ( $sample['posts'] as $p ) {
			$key = \strtolower( $p['desc'] );
			if ( '' === $key ) {
				continue; // No explicit description: resolved from templates, not a duplicate.
			}
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		$dupes = 0;
		foreach ( $counts as $c ) {
			if ( $c > 1 ) {
				$dupes += $c;
			}
		}
		if ( 0 === $dupes ) {
			return $this->item( 'duplicate_descriptions', 'passed', \__( 'Duplicate meta descriptions', 'nexter-extension' ), \__( 'No duplicate custom meta descriptions detected.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'duplicate_descriptions',
			'warning',
			\__( 'Duplicate meta descriptions', 'nexter-extension' ),
			$this->sample_suffix(
				$sample,
				sprintf(
					/* translators: %d: number of items */
					\__( '%d items share an identical custom meta description.', 'nexter-extension' ),
					$dupes
				)
			),
			\__( 'Write a unique meta description per page for distinct search snippets.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_thin_content() {
		$sample = $this->get_content_sample();
		if ( empty( $sample['posts'] ) ) {
			return $this->item( 'thin_content', 'passed', \__( 'Thin content', 'nexter-extension' ), \__( 'No published content to analyze.', 'nexter-extension' ), '', false, '' );
		}
		$threshold = (int) \apply_filters( 'nexter_seo_audit_thin_word_threshold', self::THIN_WORD_THRESHOLD );
		$threshold = \max( 50, $threshold );
		$thin = 0;
		foreach ( $sample['posts'] as $p ) {
			if ( (int) $p['words'] < $threshold ) {
				++$thin;
			}
		}
		if ( 0 === $thin ) {
			/* translators: %d: minimum word count threshold */
			return $this->item( 'thin_content', 'passed', \__( 'Thin content', 'nexter-extension' ), $this->sample_suffix( $sample, sprintf( \__( 'All sampled items have at least %d words.', 'nexter-extension' ), $threshold ) ), '', false, '' );
		}
		$ratio  = $thin / \max( 1, \count( $sample['posts'] ) );
		$status = $ratio >= 0.5 ? 'warning' : 'suggestion';
		return $this->item(
			'thin_content',
			$status,
			\__( 'Thin content', 'nexter-extension' ),
			$this->sample_suffix(
				$sample,
				sprintf(
					/* translators: 1: count of thin items, 2: word threshold */
					\__( '%1$d sampled item(s) have fewer than %2$d words.', 'nexter-extension' ),
					$thin,
					$threshold
				)
			),
			\__( 'Expand short pages with useful, original content, or consolidate/noindex them.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function check_noindex_content() {
		$sample  = $this->get_content_sample();
		$noindex = 0;
		foreach ( $sample['posts'] as $p ) {
			if ( ! empty( $p['noindex'] ) ) {
				++$noindex;
			}
		}
		if ( 0 === $noindex ) {
			return $this->item( 'noindex_content', 'passed', \__( 'Indexable content', 'nexter-extension' ), \__( 'No published items are individually set to noindex.', 'nexter-extension' ), '', false, '' );
		}
		return $this->item(
			'noindex_content',
			'suggestion',
			\__( 'Noindex content', 'nexter-extension' ),
			$this->sample_suffix(
				$sample,
				sprintf(
					/* translators: %d: number of items */
					\__( '%d published item(s) are set to noindex and will be excluded from search results.', 'nexter-extension' ),
					$noindex
				)
			),
			\__( 'Confirm these pages are meant to be hidden from search; remove the noindex flag if not.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * Home origin ("scheme://host[:port]") and bare lowercase host, computed once.
	 *
	 * @return array{0:string,1:string} [ origin, host ]
	 */
	private function home_origin_parts() {
		$parts  = \wp_parse_url( \home_url( '/' ) );
		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = ! empty( $parts['host'] ) ? $parts['host'] : '';
		$origin = $host ? $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) : '';
		return array( $origin, \strtolower( $host ) );
	}

	/**
	 * Extract absolute http(s) links from href/src attributes in content. Protocol-relative and
	 * root-relative URLs are absolutized against the site origin; anchors, non-http schemes
	 * (mailto/tel/data/javascript) and ambiguous post-relative paths are skipped.
	 *
	 * @param string $content     Raw post content.
	 * @param string $home_origin scheme://host[:port].
	 * @return array<int,string> Absolute URLs (deduped, order-preserved).
	 */
	private function extract_links( $content, $home_origin ) {
		$content = (string) $content;
		if ( '' === \trim( $content ) ) {
			return array();
		}
		if ( ! \preg_match_all( '/(?:href|src)\s*=\s*["\\\']([^"\\\']+)["\\\']/i', $content, $m ) ) {
			return array();
		}
		$scheme = ( 0 === \strpos( $home_origin, 'http://' ) ) ? 'http' : 'https';
		$out    = array();
		foreach ( $m[1] as $raw ) {
			$u = \trim( $raw );
			if ( '' === $u || \preg_match( '/^(#|mailto:|tel:|javascript:|data:)/i', $u ) ) {
				continue;
			}
			if ( \preg_match( '#^https?://#i', $u ) ) {
				$abs = $u;
			} elseif ( 0 === \strpos( $u, '//' ) ) {
				$abs = $scheme . ':' . $u;
			} elseif ( 0 === \strpos( $u, '/' ) && '' !== $home_origin ) {
				$abs = $home_origin . $u;
			} else {
				continue; // Relative to the post path — too ambiguous to probe reliably.
			}
			$out[ $abs ] = true;
		}
		return \array_keys( $out );
	}

	/**
	 * Reduce an absolute URL to its site-relative path when it points at this site, else null.
	 *
	 * @param string $url       Absolute URL.
	 * @param string $home_host Bare host (lowercase).
	 * @return string|null Normalized path (no trailing slash; "/" for root) or null if external/invalid.
	 */
	private function to_internal_path( $url, $home_host ) {
		$url = (string) $url;
		if ( '' === $url || '' === $home_host ) {
			return null;
		}
		$parts = \wp_parse_url( $url );
		if ( empty( $parts['host'] ) || \strtolower( $parts['host'] ) !== $home_host ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? \untrailingslashit( $parts['path'] ) : '';
		return '' === $path ? '/' : $path;
	}

	/**
	 * Resolve a (possibly relative) Location header against the URL it was served from.
	 *
	 * @param string $location Location header value.
	 * @param string $base     URL the redirect came from.
	 * @return string Absolute URL (best effort).
	 */
	private function resolve_redirect_url( $location, $base ) {
		$location = \trim( (string) $location );
		if ( '' === $location || \preg_match( '#^https?://#i', $location ) ) {
			return '' === $location ? $base : $location;
		}
		$bp = \wp_parse_url( $base );
		if ( empty( $bp['scheme'] ) || empty( $bp['host'] ) ) {
			return $location;
		}
		$origin = $bp['scheme'] . '://' . $bp['host'] . ( isset( $bp['port'] ) ? ':' . (int) $bp['port'] : '' );
		if ( 0 === \strpos( $location, '//' ) ) {
			return $bp['scheme'] . ':' . $location;
		}
		if ( 0 === \strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$dir = isset( $bp['path'] ) ? \preg_replace( '#/[^/]*$#', '/', $bp['path'] ) : '/';
		return $origin . $dir . $location;
	}

	/**
	 * Probe a bounded, deduped set of links found in sampled content. Shared by the broken-link
	 * and redirect-chain checks (single memoized network pass). Hard-capped by BOTH a count and a
	 * wall-clock budget so a site full of slow/dead links can never make the audit hang.
	 *
	 * @return array{results: array<string,array<string,mixed>>, probed:int, available:int, budget_hit:bool}
	 */
	private function probe_links() {
		if ( null !== $this->link_probe_results ) {
			return $this->link_probe_results;
		}
		$sample    = $this->get_content_sample();
		$pool      = isset( $sample['link_pool'] ) ? (array) $sample['link_pool'] : array();
		$available = \count( $pool );

		$limit   = (int) \apply_filters( 'nexter_seo_audit_link_probe_limit', self::LINK_PROBE_LIMIT );
		$limit   = \max( 0, \min( 100, $limit ) );
		$timeout = (int) \apply_filters( 'nexter_seo_audit_link_probe_timeout', self::LINK_PROBE_TIMEOUT );
		$timeout = \max( 2, \min( 15, $timeout ) );
		$budget  = (float) \apply_filters( 'nexter_seo_audit_link_probe_budget', self::LINK_PROBE_BUDGET );

		$pool       = \array_slice( $pool, 0, $limit );
		$deadline   = \microtime( true ) + \max( 5, $budget );
		$results    = array();
		$budget_hit = false;
		foreach ( $pool as $url ) {
			if ( \microtime( true ) >= $deadline ) {
				$budget_hit = true;
				break;
			}
			$results[ $url ] = $this->probe_single_url( $url, $timeout );
		}

		$this->link_probe_results = array(
			'results'    => $results,
			'probed'     => \count( $results ),
			'available'  => $available,
			'budget_hit' => $budget_hit,
		);
		return $this->link_probe_results;
	}

	/**
	 * Follow a single URL through redirects (HEAD, GET fallback), counting hops. Never exceeds
	 * REDIRECT_MAX_HOPS and detects loops.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Per-request timeout (seconds).
	 * @return array{ok:bool,status:int,hops:int}
	 */
	private function probe_single_url( $url, $timeout ) {
		$current = $url;
		$hops    = 0;
		$seen    = array();
		$args    = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => false,
			'user-agent'  => 'Nexter-SEO-Audit/1.0',
		);

		while ( $hops <= self::REDIRECT_MAX_HOPS ) {
			if ( isset( $seen[ $current ] ) ) {
				return array( 'ok' => false, 'status' => 0, 'hops' => $hops, 'loop' => true );
			}
			$seen[ $current ] = true;

			$resp = \wp_remote_head( $current, $args );
			if ( \is_wp_error( $resp ) ) {
				$resp = \wp_remote_get( $current, $args ); // Some servers reject HEAD.
			}
			if ( \is_wp_error( $resp ) ) {
				return array( 'ok' => false, 'status' => 0, 'hops' => $hops, 'error' => $resp->get_error_message() );
			}
			$code = (int) \wp_remote_retrieve_response_code( $resp );
			if ( $code >= 300 && $code < 400 ) {
				$loc = \wp_remote_retrieve_header( $resp, 'location' );
				if ( \is_array( $loc ) ) {
					$loc = \end( $loc );
				}
				if ( '' === (string) $loc ) {
					return array( 'ok' => true, 'status' => $code, 'hops' => $hops );
				}
				$current = $this->resolve_redirect_url( (string) $loc, $current );
				++$hops;
				continue;
			}
			return array( 'ok' => ( $code >= 200 && $code < 400 ), 'status' => $code, 'hops' => $hops );
		}
		return array( 'ok' => false, 'status' => 0, 'hops' => $hops, 'chain_exceeded' => true );
	}

	/**
	 * "Checked X of Y links" honesty note for the network probe.
	 *
	 * @param array $probe probe_links() result.
	 * @return string
	 */
	private function probe_suffix( array $probe ) {
		$msg = sprintf(
			/* translators: 1: links checked, 2: links found */
			\__( 'Checked %1$d of %2$d link(s) found in sampled content.', 'nexter-extension' ),
			(int) $probe['probed'],
			(int) $probe['available']
		);
		if ( ! empty( $probe['budget_hit'] ) ) {
			$msg .= ' ' . \__( '(stopped early at the time limit)', 'nexter-extension' );
		}
		return $msg;
	}

	/**
	 * Orphan pages: sampled published items that no OTHER sampled item links to internally.
	 * Network-free (uses links already extracted from content). Honest about sampling scope.
	 *
	 * @return array<string, mixed>
	 */
	private function check_orphan_pages() {
		$sample = $this->get_content_sample();
		$posts  = $sample['posts'];
		if ( \count( $posts ) < 2 ) {
			return $this->item( 'orphan_pages', 'passed', \__( 'Orphan pages', 'nexter-extension' ), \__( 'Not enough published content to evaluate internal linking.', 'nexter-extension' ), '', false, '' );
		}
		$targets = array();
		foreach ( $posts as $p ) {
			foreach ( (array) $p['internal_targets'] as $t ) {
				$targets[ $t ] = true;
			}
		}
		$orphans = 0;
		foreach ( $posts as $p ) {
			$path = isset( $p['path'] ) ? (string) $p['path'] : '';
			if ( '' === $path || '/' === $path ) {
				continue; // Skip the homepage and unresolved permalinks.
			}
			if ( empty( $targets[ $path ] ) ) {
				++$orphans;
			}
		}
		if ( 0 === $orphans ) {
			return $this->item( 'orphan_pages', 'passed', \__( 'Orphan pages', 'nexter-extension' ), $this->sample_suffix( $sample, \__( 'Every sampled page is linked from other content.', 'nexter-extension' ) ), '', false, '' );
		}
		return $this->item(
			'orphan_pages',
			'suggestion',
			\__( 'Orphan pages', 'nexter-extension' ),
			$this->sample_suffix(
				$sample,
				sprintf(
					/* translators: %d: number of orphan pages */
					\__( '%d sampled page(s) are not linked from any other sampled content (orphaned).', 'nexter-extension' ),
					$orphans
				)
			),
			\__( 'Add internal links from related posts/pages so these pages are discoverable and receive link equity.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * Broken links: bounded network probe of links found in sampled content.
	 *
	 * @return array<string, mixed>
	 */
	private function check_broken_links() {
		if ( $this->is_local_site() ) {
			return $this->local_skip_item( 'broken_links', \__( 'Broken links', 'nexter-extension' ) );
		}
		$probe = $this->probe_links();
		if ( 0 === $probe['available'] ) {
			return $this->item( 'broken_links', 'passed', \__( 'Broken links', 'nexter-extension' ), \__( 'No links were found in the sampled content to check.', 'nexter-extension' ), '', false, '' );
		}
		if ( 0 === $probe['probed'] ) {
			return $this->item( 'broken_links', 'suggestion', \__( 'Broken links', 'nexter-extension' ), \__( 'Link checking was skipped because the time budget was reached before any link could be probed.', 'nexter-extension' ), \__( 'Reduce the sampled link count or increase the probe budget, then re-run.', 'nexter-extension' ), false, '' );
		}
		$broken = 0;
		foreach ( $probe['results'] as $r ) {
			if ( empty( $r['ok'] ) ) {
				++$broken;
			}
		}
		$note = $this->probe_suffix( $probe );
		if ( 0 === $broken ) {
			return $this->item( 'broken_links', 'passed', \__( 'Broken links', 'nexter-extension' ), $note, '', false, '' );
		}
		return $this->item(
			'broken_links',
			'warning',
			\__( 'Broken links', 'nexter-extension' ),
			sprintf(
				/* translators: 1: number of broken links, 2: probe summary */
				\__( '%1$d link(s) returned a connection error or a 4xx/5xx status. %2$s', 'nexter-extension' ),
				$broken,
				$note
			),
			\__( 'Fix or remove the broken links/images so visitors and crawlers do not hit dead ends.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * Redirect chains: links that reach their destination through 2 or more redirects.
	 *
	 * @return array<string, mixed>
	 */
	private function check_redirect_chains() {
		$probe = $this->probe_links();
		if ( 0 === $probe['probed'] ) {
			return $this->item( 'redirect_chains', 'passed', \__( 'Redirect chains', 'nexter-extension' ), \__( 'No links were probed for redirect chains.', 'nexter-extension' ), '', false, '' );
		}
		$chains = 0;
		foreach ( $probe['results'] as $r ) {
			if ( ! empty( $r['chain_exceeded'] ) || ( isset( $r['hops'] ) && (int) $r['hops'] >= 2 ) ) {
				++$chains;
			}
		}
		$note = $this->probe_suffix( $probe );
		if ( 0 === $chains ) {
			return $this->item( 'redirect_chains', 'passed', \__( 'Redirect chains', 'nexter-extension' ), $note, '', false, '' );
		}
		return $this->item(
			'redirect_chains',
			'suggestion',
			\__( 'Redirect chains', 'nexter-extension' ),
			sprintf(
				/* translators: 1: number of chained links, 2: probe summary */
				\__( '%1$d link(s) reach their destination through 2 or more redirects. %2$s', 'nexter-extension' ),
				$chains,
				$note
			),
			\__( 'Point links directly at the final URL to remove intermediate redirect hops.', 'nexter-extension' ),
			false,
			''
		);
	}

	/**
	 * Apply automated fix for a known issue id.
	 *
	 * @param string $issue_id Sanitized id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply_fix( $issue_id ) {
		$issue_id = \sanitize_key( $issue_id );

		switch ( $issue_id ) {
			case 'search_visibility':
				\update_option( 'blog_public', 1 );
				return array(
					'applied' => true,
					'message' => \__( 'Search visibility updated: search engines are no longer discouraged.', 'nexter-extension' ),
				);

			case 'enable_nexter_sitemap':
				$merged = Nexter_Content_SEO::get_options();
				$merged['enable_xml_sitemap'] = true;
				$merged = Nexter_Content_SEO::sanitize_sitemap_settings( $merged );
				\update_option( Nexter_Content_SEO::OPTION_NAME, $merged );
				\flush_rewrite_rules( false );
				return array(
					'applied' => true,
					'message' => \__( 'Nexter XML sitemap has been enabled. Flush permalinks if the sitemap URL still 404s.', 'nexter-extension' ),
				);

			case 'tagline_wp':
				return array(
					'redirect' => \admin_url( 'options-general.php' ),
					'message'  => \__( 'Open WordPress General settings to set the tagline.', 'nexter-extension' ),
				);

			case 'https_upgrade':
				$home = (string) \get_option( 'home', '' );
				$site = (string) \get_option( 'siteurl', '' );

				// DESTRUCTIVE: flipping home/siteurl to HTTPS on a site whose TLS isn't actually
				// serving will white-screen the front end and lock out wp-admin. Probe the HTTPS
				// endpoint with certificate verification FIRST and refuse (no mutation) if it isn't
				// reachably valid — this fix is offered exactly when HTTPS is failing.
				$https_home = $home ? \preg_replace( '#^http://#i', 'https://', $home ) : \preg_replace( '#^http://#i', 'https://', \home_url( '/' ) );
				$probe      = \wp_remote_get(
					$https_home,
					array(
						'timeout'     => 10,
						'redirection' => 2,
						'sslverify'   => true, // Reject invalid/self-signed certs — that's the failure we're guarding against.
					)
				);
				if ( \is_wp_error( $probe ) ) {
					return new \WP_Error(
						'https_unreachable',
						sprintf(
							/* translators: %s: connection error message */
							\__( 'HTTPS is not reachable yet (%s). Install or repair your SSL certificate first — the URLs were NOT changed, to avoid locking you out.', 'nexter-extension' ),
							$probe->get_error_message()
						),
						array( 'status' => 409 )
					);
				}
				$code = (int) \wp_remote_retrieve_response_code( $probe );
				if ( $code < 200 || $code >= 400 ) {
					return new \WP_Error(
						'https_unreachable',
						sprintf(
							/* translators: %d: HTTP status code */
							\__( 'HTTPS responded with HTTP %d, so it is not serving your site correctly. Fix SSL first — the URLs were NOT changed.', 'nexter-extension' ),
							$code
						),
						array( 'status' => 409 )
					);
				}

				// HTTPS verified reachable — safe to switch.
				if ( $home && \strpos( $home, 'http://' ) === 0 ) {
					\update_option( 'home', \preg_replace( '#^http://#i', 'https://', $home ) );
				}
				if ( $site && \strpos( $site, 'http://' ) === 0 ) {
					\update_option( 'siteurl', \preg_replace( '#^http://#i', 'https://', $site ) );
				}
				return array(
					'applied' => true,
					'message' => \__( 'HTTPS verified reachable. Site URL and Home URL were updated to HTTPS; add a server-level http→https redirect to finish.', 'nexter-extension' ),
				);

			default:
				return new \WP_Error( 'invalid_fix', \__( 'No automated fix is available for this issue.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
	}
}
