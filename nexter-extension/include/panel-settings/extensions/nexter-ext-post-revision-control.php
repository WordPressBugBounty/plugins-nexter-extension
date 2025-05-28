<?php 
/*
 * Post Revision Control Extension
 * @since 4.2.0
 */
defined('ABSPATH') || exit;

final class Nexter_Ext_Post_Revision_Control {

    public static $revision_settings = [];

    public function __construct() {
        self::load_revision_settings();
        add_filter('wp_revisions_to_keep', [$this, 'limit_post_revisions'], 10, 2);
    }

    /**
     * Load revision control settings from the WordPress options.
     */
    private static function load_revision_settings(): void {
        if (!empty(self::$revision_settings)) {
            return;
        }

        $options = get_option('nexter_site_performance', []);

        if (
            !empty($options['post-revision-control']['switch']) &&
            !empty($options['post-revision-control']['values'])
        ) {
            self::$revision_settings = $options['post-revision-control']['values'];
        }
    }

    /**
     * Filter: Limit the number of revisions saved per post type.
     */
    public function limit_post_revisions(int $num, WP_Post $post): int {
        $max_revisions = isset(self::$revision_settings->revision) ? self::$revision_settings->revision : 10;
        $post_types = isset(self::$revision_settings->posts) ? self::$revision_settings->posts : [];
		
        if (!is_array($post_types)) {
            return $num;
        }

		if (!empty($post->post_type) && !empty($post_types) && in_array($post->post_type, $post_types)) {
			return (int) $max_revisions;
		}

        return $num;
    }
}

new Nexter_Ext_Post_Revision_Control();