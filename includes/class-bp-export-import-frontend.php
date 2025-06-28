<?php
/**
 * BP Export Import Frontend Class
 *
 * Handles frontend user export functionality
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Frontend export handling
        add_action('wp_ajax_bp_frontend_export', array($this, 'handle_frontend_export'));
        add_action('wp_ajax_nopriv_bp_frontend_export', array($this, 'handle_frontend_export'));
        
        // Add export tab to BuddyPress profile
        add_action('bp_setup_nav', array($this, 'setup_nav'), 100);
    }

    /**
     * Setup BuddyPress navigation
     */
    public function setup_nav() {
        if (!bp_is_user()) {
            return;
        }

        bp_core_new_subnav_item(array(
            'name' => __('Export Data', 'bp-export-import'),
            'slug' => 'export-data',
            'parent_url' => bp_displayed_user_domain() . 'settings/',
            'parent_slug' => 'settings',
            'screen_function' => array($this, 'export_screen'),
            'position' => 100,
            'user_has_access' => bp_is_my_profile()
        ));
    }

    /**
     * Export screen callback
     */
    public function export_screen() {
        add_action('bp_template_content', array($this, 'export_content'));
        bp_core_load_template('members/single/plugins');
    }

    /**
     * Export content
     */
    public function export_content() {
        if (file_exists(BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/frontend/export-profile.php')) {
            include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/frontend/export-profile.php';
        } else {
            echo '<p>' . __('Export template not found.', 'bp-export-import') . '</p>';
        }
    }

    /**
     * Handle frontend export AJAX request
     */
    public function handle_frontend_export() {
        // Basic implementation - can be expanded
        wp_send_json_error(__('Frontend export not yet implemented.', 'bp-export-import'));
    }

    /**
     * Get user export statistics
     *
     * @param int $user_id User ID
     * @return array Export statistics
     */
    public function get_user_export_stats($user_id) {
        // Basic implementation - return empty stats for now
        return array(
            'total_exports' => 0,
            'successful_exports' => 0,
            'last_export' => null
        );
    }
}