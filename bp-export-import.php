<?php
/**
 * Plugin Name: BP Export Import
 * Description: A BuddyPress addon for exporting and importing user data with field mapping and WP CLI support.
 * Version: 1.0.0
 * Author: BuddyPress
 * Text Domain: bp-export-import
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BP_EXPORT_IMPORT_VERSION', '1.0.0');
define('BP_EXPORT_IMPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BP_EXPORT_IMPORT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BP_EXPORT_IMPORT_PLUGIN_FILE', __FILE__);
define('BP_EXPORT_IMPORT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin initialization class
 */
class BP_Export_Import_Loader {

    /**
     * Instance of main plugin class
     *
     * @var BP_Export_Import_Loader|null
     */
    private static $instance = null;

    /**
     * Plugin initialization flag
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Get loader instance
     *
     * @return BP_Export_Import_Loader
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
        $this->init_hooks();
        $this->include_files();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Init hooks
        add_action('plugins_loaded', array($this, 'plugins_loaded'), 10);
        add_action('bp_include', array($this, 'bp_include'), 10);
        
        // Admin hooks
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Load text domain
        add_action('init', array($this, 'load_textdomain'));
        
        // Cron hooks
        add_action('bp_export_import_cleanup', array($this, 'handle_cleanup'));
        add_action('bp_export_import_check_stuck_operations', array($this, 'check_stuck_operations'));
    }

    /**
     * Include required files
     */
    private function include_files() {
        // Core functions first
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/functions.php';
        
        // Core classes in dependency order
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-logger.php';
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-validator.php';
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-progress.php';
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-field-mapping.php';
        
        // Functional classes
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-export.php';
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-import.php';
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-background-process.php';
        
        // Main plugin class
        require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import.php';
        
        // REMOVE the problematic frontend include - it doesn't exist and causes errors
        // require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-frontend.php';
        
        // CLI integration (only if WP CLI is available)
        if (defined('WP_CLI') && WP_CLI) {
            require_once BP_EXPORT_IMPORT_PLUGIN_DIR . 'includes/class-bp-export-import-cli.php';
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check if BuddyPress is active
        if (!class_exists('BuddyPress')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('BP Export Import requires BuddyPress to be active. Please activate BuddyPress first.', 'bp-export-import'),
                'Plugin dependency check',
                array('back_link' => true)
            );
        }

        // Check minimum requirements
        $requirements_check = $this->check_requirements();
        if (is_wp_error($requirements_check)) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die($requirements_check->get_error_message());
        }

        // Initialize main plugin class for activation tasks
        if (class_exists('BP_Export_Import')) {
            $plugin = BP_Export_Import::instance();
            $plugin->activate();
        }

        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set activation flag for admin notice
        set_transient('bp_export_import_activated', true, 30);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Initialize main plugin class for deactivation tasks
        if (class_exists('BP_Export_Import')) {
            $plugin = BP_Export_Import::instance();
            $plugin->deactivate();
        }

        // Clear scheduled events
        wp_clear_scheduled_hook('bp_export_import_cleanup');
        wp_clear_scheduled_hook('bp_export_import_check_stuck_operations');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugins loaded hook
     */
    public function plugins_loaded() {
        // Check if BuddyPress is available
        if (!class_exists('BuddyPress')) {
            add_action('admin_notices', array($this, 'buddypress_required_notice'));
            return;
        }

        // Check minimum requirements
        if (!$this->check_requirements()) {
            return;
        }
        
        // Load text domain
        $this->load_textdomain();
    }

    /**
     * BuddyPress include hook
     */
    public function bp_include() {
        if (!class_exists('BuddyPress') || $this->initialized) {
            return;
        }

        // Initialize main plugin class
        BP_Export_Import::instance();
        $this->initialized = true;
        
        // Hook into BuddyPress
        add_action('bp_ready', array($this, 'bp_ready'));
    }

    /**
     * BuddyPress ready hook
     */
    public function bp_ready() {
        // Plugin is fully loaded and BuddyPress is ready
        do_action('bp_export_import_ready');
    }

    /**
     * Admin init hook
     */
    public function admin_init() {
        // Check if we need to run any admin initialization
        $this->maybe_upgrade_database();
        
        // Handle admin actions
        $this->handle_admin_actions();
    }

    /**
     * Add admin menu
     */
    public function admin_menu() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Main menu page
        $main_page = add_management_page(
            __('BP Export/Import', 'bp-export-import'),
            __('BP Export/Import', 'bp-export-import'),
            'manage_options',
            'bp-export-import',
            array($this, 'admin_page_export')
        );

        // Import submenu
        $import_page = add_submenu_page(
            'bp-export-import',
            __('Import Users', 'bp-export-import'),
            __('Import Users', 'bp-export-import'),
            'manage_options',
            'bp-export-import-import',
            array($this, 'admin_page_import')
        );

        // Progress Tracker submenu
        $progress_page = add_submenu_page(
            'bp-export-import',
            __('Progress Tracker', 'bp-export-import'),
            __('Progress Tracker', 'bp-export-import'),
            'manage_options',
            'bp-export-import-progress',
            array($this, 'admin_page_progress')
        );

        // Status & Logs submenu
        $status_page = add_submenu_page(
            'bp-export-import',
            __('Status & Logs', 'bp-export-import'),
            __('Status & Logs', 'bp-export-import'),
            'manage_options',
            'bp-export-import-status',
            array($this, 'admin_page_status')
        );

        // Add help tabs for each page
        add_action("load-{$main_page}", array($this, 'add_export_help_tab'));
        add_action("load-{$import_page}", array($this, 'add_import_help_tab'));
        add_action("load-{$progress_page}", array($this, 'add_progress_help_tab'));
        add_action("load-{$status_page}", array($this, 'add_status_help_tab'));
    }

    /**
     * Export admin page
     */
    public function admin_page_export() {
        if (!file_exists(BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/export-options.php')) {
            wp_die(__('Export template not found.', 'bp-export-import'));
        }
        include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/export-options.php';
    }

    /**
     * Import admin page
     */
    public function admin_page_import() {
        if (!file_exists(BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/import-options.php')) {
            wp_die(__('Import template not found.', 'bp-export-import'));
        }
        include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/import-options.php';
    }

    /**
     * Progress tracker admin page
     */
    public function admin_page_progress() {
        if (!file_exists(BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/progress-tracker.php')) {
            wp_die(__('Progress tracker template not found.', 'bp-export-import'));
        }
        include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/progress-tracker.php';
    }

    /**
     * Status admin page
     */
    public function admin_page_status() {
        if (!file_exists(BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/status-logs.php')) {
            wp_die(__('Status template not found.', 'bp-export-import'));
        }
        include BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/admin/status-logs.php';
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bp-export-import',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Admin notices
     */
    public function admin_notices() {
        // Show activation notice
        if (get_transient('bp_export_import_activated')) {
            delete_transient('bp_export_import_activated');
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php printf(
                        __('%s has been activated successfully! <a href="%s">Get started</a>', 'bp-export-import'),
                        '<strong>BP Export Import</strong>',
                        admin_url('tools.php?page=bp-export-import')
                    ); ?>
                </p>
            </div>
            <?php
        }

        // Show requirements notices
        if (!class_exists('BuddyPress')) {
            $this->buddypress_required_notice();
        } elseif (!$this->check_requirements()) {
            $this->requirements_notice();
        }
    }

    /**
     * Handle admin actions
     */
    private function handle_admin_actions() {
        // Handle manual cleanup
        if (isset($_POST['bp_export_import_manual_cleanup']) && 
            check_admin_referer('bp_export_import_cleanup', '_wpnonce_bp_export_import_cleanup')) {
            
            if (current_user_can('manage_options')) {
                $this->handle_cleanup();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         __('Cleanup completed successfully.', 'bp-export-import') . '</p></div>';
                });
            }
        }
    }

    /**
     * Check minimum requirements
     *
     * @return bool|WP_Error
     */
    private function check_requirements() {
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

        // Check required PHP extensions
        $required_extensions = array('json', 'mbstring');
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
     * Maybe upgrade database
     */
    private function maybe_upgrade_database() {
        $current_version = get_option('bp_export_import_db_version', '0');
        
        if (version_compare($current_version, BP_EXPORT_IMPORT_VERSION, '<')) {
            // Run upgrade if needed
            if (class_exists('BP_Export_Import')) {
                $plugin = BP_Export_Import::instance();
                $plugin->create_tables();
            }
        }
    }

    /**
     * Handle cleanup cron job
     */
    public function handle_cleanup() {
        bp_export_import_handle_cleanup();
    }

    /**
     * Check for stuck operations
     */
    public function check_stuck_operations() {
        if (class_exists('BP_Export_Import')) {
            $plugin = BP_Export_Import::instance();
            $plugin->check_stuck_operations();
        }
    }

    /**
     * BuddyPress required notice
     */
    public function buddypress_required_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    /* translators: %s: Plugin name */
                    __('%s requires BuddyPress to be installed and activated.', 'bp-export-import'),
                    '<strong>BP Export Import</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Requirements notice
     */
    private function requirements_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    /* translators: %s: Plugin name */
                    __('%s requires PHP 7.0+ and WordPress 5.0+.', 'bp-export-import'),
                    '<strong>BP Export Import</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Add help tabs for export page
     */
    public function add_export_help_tab() {
        $screen = get_current_screen();
        
        $screen->add_help_tab(array(
            'id' => 'bp-export-help-overview',
            'title' => __('Overview', 'bp-export-import'),
            'content' => '<p>' . __('Use this page to export BuddyPress user data in various formats.', 'bp-export-import') . '</p>'
        ));
        
        $screen->add_help_tab(array(
            'id' => 'bp-export-help-formats',
            'title' => __('File Formats', 'bp-export-import'),
            'content' => '<p>' . __('CSV: Best for Excel and spreadsheets<br>JSON: Best for web applications<br>XML: Best for data exchange', 'bp-export-import') . '</p>'
        ));
    }

    /**
     * Add help tabs for import page
     */
    public function add_import_help_tab() {
        $screen = get_current_screen();
        
        $screen->add_help_tab(array(
            'id' => 'bp-import-help-overview',
            'title' => __('Overview', 'bp-export-import'),
            'content' => '<p>' . __('Use this page to import user data from CSV, JSON, or XML files.', 'bp-export-import') . '</p>'
        ));
        
        $screen->add_help_tab(array(
            'id' => 'bp-import-help-mapping',
            'title' => __('Field Mapping', 'bp-export-import'),
            'content' => '<p>' . __('Map fields from your import file to BuddyPress fields for accurate data import.', 'bp-export-import') . '</p>'
        ));
    }

    /**
     * Add help tabs for progress page
     */
    public function add_progress_help_tab() {
        $screen = get_current_screen();
        
        $screen->add_help_tab(array(
            'id' => 'bp-progress-help-overview',
            'title' => __('Overview', 'bp-export-import'),
            'content' => '<p>' . __('Monitor the progress of your import and export operations in real-time.', 'bp-export-import') . '</p>'
        ));
    }

    /**
     * Add help tabs for status page
     */
    public function add_status_help_tab() {
        $screen = get_current_screen();
        
        $screen->add_help_tab(array(
            'id' => 'bp-status-help-overview',
            'title' => __('Overview', 'bp-export-import'),
            'content' => '<p>' . __('View plugin status, operation history, and manage log files.', 'bp-export-import') . '</p>'
        ));
    }
}

// Initialize the plugin loader
BP_Export_Import_Loader::instance();

/**
 * Main function to get plugin instance
 * 
 * @return BP_Export_Import|null
 */
function bp_export_import() {
    if (class_exists('BP_Export_Import')) {
        return BP_Export_Import::instance();
    }
    return null;
}

/**
 * Get plugin version
 * 
 * @return string
 */
function bp_export_import_version() {
    return BP_EXPORT_IMPORT_VERSION;
}

/**
 * Check if plugin is ready
 * 
 * @return bool
 */
function bp_export_import_is_ready() {
    return class_exists('BP_Export_Import') && class_exists('BuddyPress');
}

/**
 * Enqueue plugin assets
 *
 * @param string $hook The current admin page hook.
 */
function bp_export_import_enqueue_admin_assets($hook) {
    // Only load on plugin pages
    if (strpos($hook, 'bp-export-import') === false) {
        return;
    }

    // Enqueue styles
    wp_enqueue_style(
        'bp-export-import-admin-style',
        BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        BP_EXPORT_IMPORT_VERSION
    );

    // Enqueue scripts
    wp_enqueue_script(
        'bp-export-import-admin-script',
        BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/js/admin.js',
        array('jquery'),
        BP_EXPORT_IMPORT_VERSION,
        true
    );

    // Localize script for AJAX
    wp_localize_script('bp-export-import-admin-script', 'bp_export_import_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('bp_export_import_ajax'),
        'strings' => array(
            'processing' => __('Processing...', 'bp-export-import'),
            'completed' => __('Completed', 'bp-export-import'),
            'failed' => __('Failed', 'bp-export-import'),
            'cancelled' => __('Cancelled', 'bp-export-import'),
            'confirm_cancel' => __('Are you sure you want to cancel this operation?', 'bp-export-import'),
            'operation_not_found' => __('Operation not found.', 'bp-export-import'),
            'permission_denied' => __('Permission denied.', 'bp-export-import'),
            'error_occurred' => __('An error occurred. Please try again.', 'bp-export-import'),
        )
    ));
}
add_action('admin_enqueue_scripts', 'bp_export_import_enqueue_admin_assets');

/**
 * Enqueue frontend assets
 */
function bp_export_import_enqueue_frontend_assets() {
    // Only load on BuddyPress pages
    if (!function_exists('bp_is_user') || !bp_is_user()) {
        return;
    }

    wp_enqueue_style(
        'bp-export-import-frontend-style',
        BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/css/frontend.css',
        array(),
        BP_EXPORT_IMPORT_VERSION
    );

    wp_enqueue_script(
        'bp-export-import-frontend-script',
        BP_EXPORT_IMPORT_PLUGIN_URL . 'assets/js/frontend.js',
        array('jquery'),
        BP_EXPORT_IMPORT_VERSION,
        true
    );

    // Localize script for AJAX
    wp_localize_script('bp-export-import-frontend-script', 'bp_export_import_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('bp_export_import_ajax'),
        'strings' => array(
            'export_started' => __('Export started successfully!', 'bp-export-import'),
            'export_failed' => __('Export failed. Please try again.', 'bp-export-import'),
            'processing' => __('Processing...', 'bp-export-import'),
            'completed' => __('Completed', 'bp-export-import'),
        )
    ));
}
add_action('wp_enqueue_scripts', 'bp_export_import_enqueue_frontend_assets');

/**
 * Add action links to plugin page
 *
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function bp_export_import_plugin_action_links($links) {
    $action_links = array(
        'export' => '<a href="' . admin_url('tools.php?page=bp-export-import') . '">' . __('Export', 'bp-export-import') . '</a>',
        'import' => '<a href="' . admin_url('tools.php?page=bp-export-import-import') . '">' . __('Import', 'bp-export-import') . '</a>',
        'status' => '<a href="' . admin_url('tools.php?page=bp-export-import-status') . '">' . __('Status', 'bp-export-import') . '</a>',
    );

    return array_merge($action_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bp_export_import_plugin_action_links');

/**
 * Add plugin meta links
 *
 * @param array  $links Plugin row meta links.
 * @param string $file  Plugin base file name.
 * @return array Modified plugin row meta links.
 */
function bp_export_import_plugin_row_meta($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $row_meta = array(
            'docs' => '<a href="https://github.com/buddypress/bp-export-import" target="_blank">' . __('Documentation', 'bp-export-import') . '</a>',
            'support' => '<a href="https://buddypress.org/support/" target="_blank">' . __('Support', 'bp-export-import') . '</a>',
        );

        return array_merge($links, $row_meta);
    }

    return $links;
}
add_filter('plugin_row_meta', 'bp_export_import_plugin_row_meta', 10, 2);