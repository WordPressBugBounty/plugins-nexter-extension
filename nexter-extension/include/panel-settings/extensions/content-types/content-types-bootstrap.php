<?php
/**
 * Bootstrap for Custom Content Types: CPT registration, conditional CPT/Taxonomy loader, Ajax.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$content_types_dir = __DIR__;

require_once $content_types_dir . '/class-nxt-content-types-fields.php';
require_once $content_types_dir . '/class-nxt-content-types-field-groups-fields.php';
require_once $content_types_dir . '/class-nxt-content-types-cpt.php';
require_once $content_types_dir . '/class-nxt-content-types-definitions-repository.php';
require_once $content_types_dir . '/class-nxt-content-types-register.php';
require_once $content_types_dir . '/class-nxt-content-types-admin-logic.php';
require_once $content_types_dir . '/class-nxt-content-types-rest-field-groups.php';
require_once $content_types_dir . '/class-nxt-content-types-rest-bootstrap.php';
require_once $content_types_dir . '/class-nxt-content-types-field-groups-location.php';
require_once $content_types_dir . '/class-nxt-content-types-field-groups-render.php';
require_once $content_types_dir . '/nxt-field-api.php';
// After Nexter Pro's init-9 loader, so Pro's real repeater functions win.
add_action(
	'init',
	static function () use ( $content_types_dir ) {
		require_once $content_types_dir . '/nxt-field-api-pro-stubs.php';
	},
	10
);

// File-first model layer.
require_once $content_types_dir . '/model/class-nxt-content-types-model-paths.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-store.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-loader.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-identity.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-rows.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-writer.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-validator.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-cache.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-sync.php';
require_once $content_types_dir . '/model/class-nxt-content-types-model-rest.php';

// Third-party importer (ACF / Meta Box / Pods).
require_once $content_types_dir . '/import/class-nxt-content-types-import-source.php';
require_once $content_types_dir . '/import/class-nxt-content-types-import-acf.php';
require_once $content_types_dir . '/import/class-nxt-content-types-import-metabox.php';
require_once $content_types_dir . '/import/class-nxt-content-types-import-pods.php';
require_once $content_types_dir . '/import/class-nxt-content-types-import-jetengine.php';
require_once $content_types_dir . '/import/class-nxt-content-types-import-manager.php';
require_once $content_types_dir . '/import/class-nxt-content-types-import-rest.php';

// The mirror listens for definition meta writes, so it has to be attached before any of the
// instances below can perform one.
Nxt_Content_Types_Model_Sync::init();

Nxt_Content_Types_CPT::get_instance();
Nxt_Content_Types_Definitions_Repository::get_instance();
Nxt_Content_Types_Register::get_instance();
Nxt_Content_Types_REST_Field_Groups::get_instance();
Nxt_Content_Types_REST_Bootstrap::get_instance();
Nxt_Content_Types_Import_REST::get_instance();
Nxt_Content_Types_Model_REST::get_instance();
// Instantiate in all contexts: the render class binds its persist hooks unconditionally, so REST and frontend saves work.
Nxt_Content_Types_Field_Groups_Render::get_instance();
