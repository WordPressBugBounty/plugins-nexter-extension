<?php
/**
 * Nexter Builder Shortcode
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

if (!class_exists('Nexter_Builder_Shortcode')) {

	class Nexter_Builder_Shortcode
	{

		const NXT_SHORTCODE = 'nexter-builder';

		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 *  Initiator
		 */
		public static function get_instance()
		{
			if (!isset(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 *  Constructor
		 */
		public function __construct()
		{
			$this->add_actions_shortcode();
		}

		private function add_actions_shortcode()
		{
			if (is_admin()) {
				add_action('manage_' . NXT_BUILD_POST . '_posts_columns', [$this, 'admin_columns_shortcode'], 15);
				add_action('manage_' . NXT_BUILD_POST . '_posts_custom_column', [$this, 'admin_columns_shortcode_content'], 15, 2);
			}

			add_shortcode(self::NXT_SHORTCODE, [$this, 'create_shortcode']);
		}

		public function admin_columns_shortcode($columns)
		{
			$columns['nxt_shortcode'] = __('Shortcode', 'nexter-extension');

			return $columns;
		}

		public function admin_columns_shortcode_content($column, $post_id)
		{
			if ('nxt_shortcode' === $column) {
				//translator %s = shortcode, %d = post_id
				$shortcode = esc_attr(sprintf('[%s id="%d"]', self::NXT_SHORTCODE, $post_id));
				printf('<div class="nxt-shortcode-wrap">
        					<input type="text" class="nexter1-input-box my-ccs-class" onfocus="this.select()" value="%s" readonly />
							<button id="clear" class="nxt-shortcode-copy-btn">
								<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 9 9" fill="none" class="nexter-input-box-button-svg active">
									<path d="M1.63096 8.25001H5.26925C5.96865 8.25001 6.54213 7.72529 6.63181 7.05001H6.86925C7.63061 7.05001 8.25013 6.43049 8.25013 5.66917V1.63084C8.25013 0.86952 7.63061 0.25 6.86929 0.25H3.23096C2.46964 0.25 1.85012 0.86952 1.85012 1.63084V1.85H1.63096C0.869643 1.85 0.250122 2.46952 0.250122 3.23084V6.86913C0.250122 7.63049 0.869643 8.25001 1.63096 8.25001ZM2.65012 1.63084C2.65012 1.31056 2.91068 1.05 3.23096 1.05H6.86925C7.18957 1.05 7.45013 1.31056 7.45013 1.63084V5.66913C7.45013 5.98945 7.18957 6.25001 6.86929 6.25001H6.65013V3.23084C6.65013 2.46952 6.03061 1.85 5.26929 1.85H2.65012V1.63084ZM1.05012 3.23084C1.05012 2.91056 1.31068 2.65 1.63096 2.65H5.26925C5.58957 2.65 5.85013 2.91056 5.85013 3.23084V6.86913C5.85013 7.18945 5.58957 7.45001 5.26929 7.45001H1.63096C1.31068 7.45001 1.05012 7.18945 1.05012 6.86917V3.23084Z" fill="white"/>
								</svg>
								<svg xmlns="http://www.w3.org/2000/svg" class="nexter-input-box-button-svg" width="13" height="10" viewBox="0 0 13 10" fill="none">
									<path d="M1.24976 6L4.24976 9L12.2498 1" stroke="white" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</button>
						</div>', esc_attr($shortcode));
			}
		}

		public function create_shortcode($option = [])
		{
			if (empty($option['id'])) {
				return '';
			}
			if (class_exists('Nexter_Gutenberg_Editor')) {
				$load_css = new Nexter_Gutenberg_Editor();
				$load_css->enqueue_scripts($option['id']);
			}
			ob_start();
			Nexter_Builder_Sections_Conditional::get_instance()->get_action_content($option['id']);
			return ob_get_clean();
		}
	}
}

Nexter_Builder_Shortcode::get_instance();