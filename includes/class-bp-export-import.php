<?php
/**
 * BP Export Import Main Class - LAZY LOADING VERSION
 *
 * Main plugin class that initializes and manages all components
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import {

    /**
     * Single instance of the plugin
     *
     * @var BP_Export_Import|null
     */
    private static $instance = null;

    /**
     * Plugin components
     *
     * @var array
     */
    private $components = array();

    /**
     * Components loaded flag
     *
     * @var bool
     */
    private $components_loaded = false;

    /**
     * Plugin version
     *
     * @var string
     */
    const VERSION = '1.0.0';

    /**
     * Get plugin instance
     *
     * @return BP_Export_Import
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // MINIMAL initialization - only register hooks, no component loading
        $this->init_basic_hooks();
    }

    /**
     * Initialize only essential hooks
     */
    private function init_basic_hooks() {
        // Text domain loading
        add_action('init', array($this, 'load_textdomain'));
        
        // Admin hooks - only when needed
        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        }
        
        // Frontend hooks - only when needed  
        if (!is_admin()) {
            add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
        }
        
        // AJAX hooks - ONLY when doing AJAX
        if (wp_doing_ajax()) {
            $this->init_ajax_hooks();
        }
    }

    /**
     * Initialize AJAX hooks separately
     */
    private function init_ajax_hooks() {
        add_action('wp_ajax_bp_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_bp_cancel_operation', array($this, 'ajax_cancel_operation'));
        add_action('wp_ajax_bp_get_dashboard_stats', array($this, 'ajax_get_dashboard_stats'));
        add_action('wp_ajax_bp_get_operations_status', array($this, 'ajax_get_operations_status'));
    }

    /**
     * DO NOT auto-load components - only when explicitly requested
     */
    private function maybe_load_components() {
        // DON'T auto-load anything - wait for explicit requests
        return;
    }

    /**
     * Initialize plugin components only when explicitly needed
     */
    private function init_components() {
        // Only create logger - it's lightweight
        if (!isset($this->components['logger'])) {
            $this->components['logger'] = new BP_Export_Import_Logger();
        }
        
        $this->components_loaded = true;
    }

    /**
     * Get component instance with strict lazy loading
     *
     * @param string $component Component name.
     * @return mixed Component instance or null if not found.
     */
    public function get_component($component) {
        // Only load if we're in the right context
        if (!$this->should_load_component($component)) {
            return null;
        }
        
        // Load specific component if not already loaded
        if (!isset($this->components[$component])) {
            $this->load_specific_component($component);
        }
        
        return isset($this->components[$component]) ? $this->components[$component] : null;
    }

    /**
     * Check if we should load a component based on context
     */
    private function should_load_component($component) {
        // Never load on regular page loads
        if (!wp_doing_ajax() && !is_admin()) {
            return false;
        }
        
        // Only load during actual operations
        if (wp_doing_ajax()) {
            $action = isset($_POST['action']) ? $_POST['action'] : '';
            $allowed_actions = array(
                'bp_get_progress',
                'bp_cancel_operation', 
                'bp_export_users',
                'bp_import_users',
                'bp_file_preview'
            );
            
            if (!in_array($action, $allowed_actions)) {
                return false;
            }
        }
        
        // For admin pages, only load on plugin pages
        if (is_admin()) {
            $screen = get_current_screen();
            if (!$screen || strpos($screen->id, 'bp-export-import') === false) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Load a specific component on demand with strict checks
     *
     * @param string $component Component name.
     */
    private function load_specific_component($component) {
        // Don't load anything if logger doesn't exist yet
        if (!isset($this->components['logger'])) {
            $this->components['logger'] = new BP_Export_Import_Logger();
        }
        
        try {
            switch ($component) {
                case 'validator':
                    if (!isset($this->components['validator'])) {
                        $this->components['validator'] = new BP_Export_Import_Validator();
                    }
                    break;
                    
                case 'progress':
                    if (!isset($this->components['progress'])) {
                        $this->components['progress'] = new BP_Export_Import_Progress();
                    }
                    break;
                    
                case 'field_mapping':
                    if (!isset($this->components['field_mapping'])) {
                        $this->components['field_mapping'] = new BP_Export_Import_Field_Mapping();
                    }
                    break;
                    
                case 'export':
                    if (!isset($this->components['export'])) {
                        $this->components['export'] = new BP_Export_Import_Export();
                    }
                    break;
                    
                case 'import':
                    if (!isset($this->components['import'])) {
                        $this->components['import'] = new BP_Export_Import_Import();
                    }
                    break;
                    
                case 'background_process':
                    if (!isset($this->components['background_process'])) {
                        $this->components['background_process'] = new BP_Export_Import_Background_Process();
                    }
                    break;
            }
        } catch (Exception $e) {
            if (isset($this->components['logger'])) {
                $this->components['logger']->log_error('Failed to load component: ' . $component . ' - ' . $e->getMessage());
            }
        }
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bp-export-import',
            false,
            dirname(plugin_basename(BP_EXPORT_IMPORT_PLUGIN_DIR)) . '/languages/'
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page hook.
     */
    public function admin_scripts($hook) {
        // Only load on plugin pages
        if (strpos($hook, 'bp-export-import') === false) {
            return;
        }

        wp_enqueue_script(
            'bp-export-import-admin',
            BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_enqueue_style(
            'bp-export-import-admin',
            BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            self::VERSION
        );

        // Localize script for AJAX
        wp_localize_script('bp-export-import-admin', 'bp_export_import_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bp_export_import_ajax'),
            'strings' => array(
                'processing' => __('Processing...', 'bp-export-import'),
                'completed' => __('Completed', 'bp-export-import'),
                'failed' => __('Failed', 'bp-export-import'),
                'cancelled' => __('Cancelled', 'bp-export-import'),
                'confirm_cancel' => __('Are you sure you want to cancel this operation?', 'bp-export-import'),
            )
        ));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function frontend_scripts() {
        // Only load on BuddyPress pages
        if (!function_exists('bp_is_user') || !bp_is_user()) {
            return;
        }

        wp_enqueue_script(
            'bp-export-import-frontend',
            BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_enqueue_style(
            'bp-export-import-frontend',
            BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            self::VERSION
        );

        // Localize script for AJAX
        wp_localize_script('bp-export-import-frontend', 'bp_export_import_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bp_export_import_ajax'),
            'strings' => array(
                'export_started' => __('Export started successfully!', 'bp-export-import'),
                'export_failed' => __('Export failed. Please try again.', 'bp-export-import'),
                'processing' => __('Processing...', 'bp-export-import'),
            )
        ));
    }

    /**
     * AJAX handler for getting progress
     */
    public function ajax_get_progress() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');

        $operation_id = isset($_POST['operation_id']) ? sanitize_text_field($_POST['operation_id']) : '';
        
        if (empty($operation_id)) {
            wp_send_json_error(__('Invalid operation ID.', 'bp-export-import'));
        }

        $progress = $this->get_component('progress');
        if (!$progress) {
            wp_send_json_error(__('Progress component not available.', 'bp-export-import'));
        }

        $progress_data = $progress->get_progress($operation_id);
        
        if (!$progress_data) {
            wp_send_json_error(__('Operation not found.', 'bp-export-import'));
        }

        // Security check
        if ($progress_data['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        wp_send_json_success($progress_data);
    }

    /**
     * AJAX handler for cancelling operations
     */
    public function ajax_cancel_operation() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');

        $operation_id = isset($_POST['operation_id']) ? sanitize_text_field($_POST['operation_id']) : '';
        
        if (empty($operation_id)) {
            wp_send_json_error(__('Invalid operation ID.', 'bp-export-import'));
        }

        $progress = $this->get_component('progress');
        if (!$progress) {
            wp_send_json_error(__('Progress component not available.', 'bp-export-import'));
        }

        $progress_data = $progress->get_progress($operation_id);
        
        if (!$progress_data) {
            wp_send_json_error(__('Operation not found.', 'bp-export-import'));
        }

        // Security check
        if ($progress_data['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        if ($progress->cancel_operation($operation_id)) {
            wp_send_json_success(__('Operation cancelled successfully.', 'bp-export-import'));
        } else {
            wp_send_json_error(__('Failed to cancel operation.', 'bp-export-import'));
        }
    }

    /**
     * AJAX handler for getting dashboard stats
     */
    public function ajax_get_dashboard_stats() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        $progress = $this->get_component('progress');
        if (!$progress) {
            wp_send_json_error(__('Progress component not available.', 'bp-export-import'));
        }
        
        $user_id = current_user_can('manage_options') ? 0 : get_current_user_id();
        $stats = $progress->get_progress_summary($user_id);
        
        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for real-time operation updates
     */
    public function ajax_get_operations_status() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        $progress = $this->get_component('progress');
        if (!$progress) {
            wp_send_json_error(__('Progress component not available.', 'bp-export-import'));
        }
        
        $user_id = current_user_can('manage_options') ? 0 : get_current_user_id();
        $active_operations = $progress->get_active_operations($user_id);
        
        $operations_data = array();
        foreach ($active_operations as $operation_id) {
            $operation_progress = $progress->get_progress($operation_id);
            if ($operation_progress) {
                $operations_data[$operation_id] = $operation_progress;
            }
        }
        
        wp_send_json_success(array(
            'active_operations' => $operations_data,
            'count' => count($active_operations)
        ));
    }

    /**
     * Get plugin information
     *
     * @return array Plugin information.
     */
    public function get_plugin_info() {
        return array(
            'version' => self::VERSION,
            'name' => 'BP Export Import',
            'description' => __('A BuddyPress addon for exporting and importing user data with field mapping and WP CLI support.', 'bp-export-import'),
            'components' => array_keys($this->components),
        );
    }

    /**
     * Check plugin requirements
     *
     * @return bool|WP_Error True if requirements met, WP_Error otherwise.
     */
    public function check_requirements() {
        // Check if BuddyPress is active
        if (!class_exists('BuddyPress')) {
            return new WP_Error(
                'buddypress_required',
                __('BP Export Import requires BuddyPress to be active.', 'bp-export-import')
            );
        }

        // Check PHP version
        if (version_compare(PHP_VERSION, '7.0', '<')) {
            return new WP_Error(
                'php_version',
                __('BP Export Import requires PHP 7.0 or higher.', 'bp-export-import')
            );
        }

        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            return new WP_Error(
                'wp_version',
                __('BP Export Import requires WordPress 5.0 or higher.', 'bp-export-import')
            );
        }

        // Check for required PHP extensions
        $required_extensions = array('json', 'mbstring', 'simplexml');
        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                return new WP_Error(
                    'missing_extension',
                    sprintf(__('BP Export Import requires the %s PHP extension.', 'bp-export-import'), $extension)
                );
            }
        }

        return true;
    }

    /**
     * Create required database tables
     */
    public function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            operation_type varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            total_records int(11) DEFAULT 0,
            processed_records int(11) DEFAULT 0,
            error_count int(11) DEFAULT 0,
            started_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            settings longtext,
            errors longtext,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Update database version
        update_option('bp_export_import_db_version', self::VERSION);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check requirements
        $requirements_check = $this->check_requirements();
        if (is_wp_error($requirements_check)) {
            deactivate_plugins(plugin_basename(BP_EXPORT_IMPORT_PLUGIN_DIR . 'bp-export-import.php'));
            wp_die($requirements_check->get_error_message());
        }

        // Create database tables
        $this->create_tables();

        // Create upload directories
        $this->create_upload_directories();

        // Set default options
        $this->set_default_options();

        // Schedule cleanup cron job
        if (!wp_next_scheduled('bp_export_import_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bp_export_import_cleanup');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('bp_export_import_cleanup');
        wp_clear_scheduled_hook('bp_export_import_process_queue');

        // Only clean up if components are loaded
        if ($this->components_loaded && isset($this->components['progress'])) {
            $active_operations = $this->components['progress']->get_active_operations();
            foreach ($active_operations as $operation_id) {
                $this->components['progress']->cancel_operation($operation_id);
            }
        }
    }

    /**
     * Create required upload directories
     */
    private function create_upload_directories() {
        $upload_dir = wp_upload_dir();
        
        $directories = array(
            'bp-export-import-temp',
            'bp-export-import-downloads',
            'bp-export-import-logs'
        );

        foreach ($directories as $dir) {
            $full_path = $upload_dir['basedir'] . '/' . $dir;
            
            if (!is_dir($full_path)) {
                wp_mkdir_p($full_path);
                
                // Create .htaccess to prevent direct access
                $htaccess_content = "Options -Indexes\nDeny from all\n";
                file_put_contents($full_path . '/.htaccess', $htaccess_content);
            }
        }
    }

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_options = array(
            'bp_export_import_enable_logging' => true,
            'bp_export_import_enable_frontend_export' => true,
            'bp_export_import_max_file_size' => 52428800, // 50MB
            'bp_export_import_allowed_file_types' => array('csv', 'json', 'xml'),
            'bp_export_import_export_batch_size' => 100, // Reduced from 500
            'bp_export_import_import_batch_size' => 50,  // Reduced from 100
            'bp_export_import_cleanup_days' => 7,
            'bp_export_import_progress_retention_hours' => 24,
        );

        foreach ($default_options as $option => $value) {
            if (false === get_option($option)) {
                add_option($option, $value);
            }
        }
        
        // Schedule stuck operation checker
        if (!wp_next_scheduled('bp_export_import_check_stuck_operations')) {
            wp_schedule_event(time(), 'hourly', 'bp_export_import_check_stuck_operations');
        }
        
        add_action('bp_export_import_check_stuck_operations', array($this, 'check_stuck_operations'));
    }

    /**
     * Check if upload directories are writable
     *
     * @return bool
     */
    private function check_upload_dirs_writable() {
        $upload_dir = wp_upload_dir();
        
        $directories = array(
            'bp-export-import-temp',
            'bp-export-import-downloads',
            'bp-export-import-logs'
        );

        foreach ($directories as $dir) {
            $full_path = $upload_dir['basedir'] . '/' . $dir;
            if (!is_writable($full_path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get plugin status for dashboard
     *
     * @return array Plugin status information.
     */
    public function get_status() {
        return array(
            'version' => self::VERSION,
            'database_version' => get_option('bp_export_import_db_version', '0'),
            'requirements_met' => !is_wp_error($this->check_requirements()),
            'components_loaded' => $this->components_loaded,
            'upload_dirs_writable' => $this->check_upload_dirs_writable(),
        );
    }

    /**
     * Cleanup transient for completed operations
     *
     * @param string $operation_id Operation ID.
     */
    public function cleanup_transient($operation_id) {
        if ($this->components_loaded && isset($this->components['progress'])) {
            $this->components['progress']->cleanup_progress($operation_id);
        }
    }

    /**
     * Check for stuck operations and mark them as failed
     * Called via cron job
     */
    public function check_stuck_operations() {
        // Only run if components are loaded
        if (!$this->components_loaded) {
            return;
        }
        
        $progress = $this->get_component('progress');
        if (!$progress) {
            return;
        }

        // Get all active operations
        $active_operations = $progress->get_active_operations(0); // All users
        
        foreach ($active_operations as $operation_id) {
            $operation_data = $progress->get_progress($operation_id);
            
            if (!$operation_data) {
                continue;
            }
            
            // Check if operation hasn't been updated in the last 10 minutes
            $last_update = $operation_data['last_update'] ?? $operation_data['start_time'];
            $time_since_update = current_time('timestamp') - $last_update;
            
            if ($time_since_update > 600) { // 10 minutes
                $progress->complete_operation(
                    $operation_id, 
                    'failed', 
                    array(__('Operation timed out - no activity for 10 minutes', 'bp-export-import'))
                );
                
                $logger = $this->get_component('logger');
                if ($logger) {
                    $logger->log_warning(
                        "Operation {$operation_id} marked as failed due to inactivity"
                    );
                }
            }
        }
    }
}