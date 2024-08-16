<?php

/**
 * Plugin Name: BP Export Import
 * Description: A BuddyPress addon for exporting and importing user data with field mapping and WP CLI support.
 * Version: 1.0.0
 * Author: BuddyPress
 * Text Domain: bp-export-import
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define('BP_EXPORT_IMPORT_VERSION', '1.0.0');
define('BP_EXPORT_IMPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BP_EXPORT_IMPORT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/functions.php';
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-export.php';
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-import.php';
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-field-mapping.php';
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-cli.php';
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-logger.php';
require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-optimizer.php';
// Include the frontend export functionalities
include_once plugin_dir_path(__FILE__) . 'templates/frontend/bp-export-import-frontend.php';

// Activation hook
function bp_export_import_activate()
{
    if (! class_exists('BuddyPress')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('BP Export Import requires BuddyPress to be active. Please activate BuddyPress first.', 'bp-export-import'));
    }
}
register_activation_hook(__FILE__, 'bp_export_import_activate');

// Deactivation hook
function bp_export_import_deactivate()
{
    // Perform any necessary cleanup during deactivation, such as clearing scheduled tasks.
}
register_deactivation_hook(__FILE__, 'bp_export_import_deactivate');

// Initialization hook
function bp_export_import_init()
{
    if (! class_exists('BuddyPress')) {
        return;
    }
}
add_action('bp_include', 'bp_export_import_init');

// Enqueue the plugin's admin styles and scripts
function bp_export_import_enqueue_admin_assets($hook)
{
    if (strpos($hook, 'bp_export_import') !== false) {
        wp_enqueue_style('bp-export-import-admin-style', BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/css/style.css');
        wp_enqueue_script('bp-export-import-admin-script', BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/js/scripts.js', ['jquery'], BP_EXPORT_IMPORT_VERSION, true);
    }
}
add_action('admin_enqueue_scripts', 'bp_export_import_enqueue_admin_assets');

// Add admin menu
function bp_export_import_admin_menu()
{
    add_management_page(
        __('BP Export Users', 'bp-export-import'),
        __('BP Export/Import', 'bp-export-import'),
        'manage_options',
        'bp-export-import',
        'bp_export_import_export_page'
    );

    add_submenu_page(
        'bp-export-import',
        __('Import Users', 'bp-export-import'),
        __('Import Users', 'bp-export-import'),
        'manage_options',
        'bp-export-import-import',
        'bp_export_import_import_page'
    );
}
add_action('admin_menu', 'bp_export_import_admin_menu');

// Render the export page
function bp_export_import_export_page()
{
    include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/export-options.php';
}

// Render the import page
function bp_export_import_import_page()
{
    include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/import-options.php';
}
