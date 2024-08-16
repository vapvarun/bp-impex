<?php
/**
 * Plugin Name: BP Export Import
 * Description: A BuddyPress addon for exporting and importing user data with field mapping and WP CLI support.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: bp-export-import
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include necessary files.
require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bp-export-import-export.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bp-export-import-import.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bp-export-import-field-mapping.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bp-export-import-cli.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bp-export-import-logger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bp-export-import-optimizer.php';

// Initialization hook.
function bp_export_import_init() {
    // Initialize components here.
}
add_action( 'bp_include', 'bp_export_import_init' );
