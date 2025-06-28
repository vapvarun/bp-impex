<?php
/**
 * BP Export Import Export Class
 *
 * Handles the export functionality for the BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Export {

    /**
     * Export users as XML with streaming
     *
     * @param string $operation_id Operation ID for progress tracking.
     */
    private function export_as_xml_stream($operation_id) {
        $filename = 'bp-users-export-' . date('Y-m-d-H-i-s') . '.xml';
        
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<users>' . "\n";
        
        $page = 1;
        $processed = 0;
        
        do {
            $users = $this->get_users_batch($page, $_POST);
            
            if (empty($users)) {
                break;
            }
            
            foreach ($users as $user) {
                $user_data = $this->prepare_user_data($user, $_POST);
                
                echo '  <user>' . "\n";
                foreach ($user_data as $key => $value) {
                    $safe_key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                    $safe_value = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                    echo "    <{$safe_key}>{$safe_value}</{$safe_key}>\n";
                }
                echo '  </user>' . "\n";
                
                // Clear user data immediately
                unset($user_data);
                
                $processed++;
                
                // Update progress periodically
                if ($processed % 50 === 0) {
                    $progress = $this->get_progress();
                    if ($progress) {
                        $progress->update_progress($operation_id, $processed);
                    }
                }
                
                // Flush output
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
            
            // Clear users array
            unset($users);
            $page++;
            
        } while ($page <= 1000); // Safety limit
        
        // Final progress update
        $progress = $this->get_progress();
        if ($progress) {
            $progress->update_progress($operation_id, $processed);
        }
        
        echo '</users>';
        exit;
    }

    /**
     * Prepare user data for export
     *
     * @param WP_User $user     User object.
     * @param array   $settings Export settings.
     * @return array Prepared user data.
     */
    public function prepare_user_data($user, $settings = array()) {
        // Start with minimal base data
        $data = array(
            'user_id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
        );

        // Only add fields that are actually selected
        if (!empty($settings['include_display_name'])) {
            $data['display_name'] = $user->display_name;
        }
        
        if (!empty($settings['include_names'])) {
            $data['first_name'] = get_user_meta($user->ID, 'first_name', true);
            $data['last_name'] = get_user_meta($user->ID, 'last_name', true);
        }
        
        if (!empty($settings['include_description'])) {
            $data['description'] = get_user_meta($user->ID, 'description', true);
        }
        
        if (!empty($settings['include_url'])) {
            $data['user_url'] = $user->user_url;
        }
        
        if (!empty($settings['include_dates'])) {
            $data['registered'] = $user->user_registered;
        }
        
        if (!empty($settings['include_role'])) {
            $data['role'] = implode(',', $user->roles);
        }
        
        if (!empty($settings['include_status'])) {
            $data['status'] = $user->user_status;
        }

        // Add selected XProfile fields ONLY if specifically requested
        $selected_xprofile_fields = isset($settings['xprofile_fields']) ? 
            array_map('sanitize_text_field', $settings['xprofile_fields']) : array();
        
        if (!empty($selected_xprofile_fields)) {
            $profile_data = $this->get_user_profile_data($user->ID, $selected_xprofile_fields);
            foreach ($selected_xprofile_fields as $field_name) {
                $data['xprofile_' . sanitize_key($field_name)] = 
                    isset($profile_data[$field_name]) ? $profile_data[$field_name] : '';
            }
        }

        // Add selected user meta fields ONLY if specifically requested
        $selected_user_meta_keys = isset($settings['user_meta_keys']) ? 
            array_map('sanitize_text_field', $settings['user_meta_keys']) : array();
        
        if (!empty($selected_user_meta_keys)) {
            foreach ($selected_user_meta_keys as $meta_key) {
                $meta_value = get_user_meta($user->ID, $meta_key, true);
                
                // Handle complex data types efficiently
                if (is_array($meta_value) || is_object($meta_value)) {
                    $meta_value = wp_json_encode($meta_value);
                }
                
                $data['meta_' . sanitize_key($meta_key)] = $meta_value;
            }
        }

        return apply_filters('bp_export_import_export_user_data', $data, $user, $settings);
    }

    /**
     * Get user profile data (only get requested fields)
     *
     * @param int   $user_id         User ID.
     * @param array $requested_fields Specific fields to retrieve.
     * @return array Profile data.
     */
    private function get_user_profile_data($user_id, $requested_fields = array()) {
        $profile_data = array();

        if (!function_exists('bp_is_active') || !bp_is_active('xprofile') || empty($requested_fields)) {
            return $profile_data;
        }

        // Only get the specific fields requested instead of all fields
        foreach ($requested_fields as $field_name) {
            if (function_exists('xprofile_get_field_data')) {
                $field_id = $this->get_xprofile_field_id_by_name($field_name);
                if ($field_id) {
                    $field_value = xprofile_get_field_data($field_id, $user_id, 'comma');
                    $profile_data[$field_name] = $field_value;
                }
            }
        }

        return $profile_data;
    }

    /**
     * Get XProfile field ID by name (with caching)
     *
     * @param string $field_name Field name.
     * @return int|false Field ID or false if not found.
     */
    private function get_xprofile_field_id_by_name($field_name) {
        // Use static cache to avoid repeated DB queries
        if (isset($this->field_id_cache[$field_name])) {
            return $this->field_id_cache[$field_name];
        }
        
        global $wpdb;
        
        if (!function_exists('bp_is_active') || !bp_is_active('xprofile')) {
            return false;
        }

        $field_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->base_prefix}bp_xprofile_fields WHERE name = %s",
            $field_name
        ));

        $this->field_id_cache[$field_name] = $field_id ? intval($field_id) : false;
        return $this->field_id_cache[$field_name];
    }

    /**
     * Get available XProfile field names
     *
     * @return array Array of field names.
     */
    public function get_xprofile_field_names() {
        $field_names = array();

        if (!function_exists('bp_is_active') || !bp_is_active('xprofile')) {
            return $field_names;
        }

        if (function_exists('bp_xprofile_get_groups')) {
            $profile_groups = bp_xprofile_get_groups(array(
                'fetch_fields' => true,
            ));

            if (is_array($profile_groups)) {
                foreach ($profile_groups as $group) {
                    if (isset($group->fields) && is_array($group->fields)) {
                        foreach ($group->fields as $field) {
                            $field_names[] = $field->name;
                        }
                    }
                }
            }
        }

        return $field_names;
    }

    /**
     * Get sample user meta keys for selection
     *
     * @return array Array of meta keys.
     */
    public function get_user_meta_keys_sample() {
        global $wpdb;
        
        // Get unique meta keys from a sample of users, excluding WordPress internal keys
        $meta_keys = $wpdb->get_col("
            SELECT DISTINCT meta_key 
            FROM {$wpdb->usermeta} 
            WHERE meta_key NOT LIKE 'wp_%' 
            AND meta_key NOT LIKE 'session_tokens'
            AND meta_key NOT LIKE '_wp_%'
            AND meta_key NOT LIKE 'dismissed_%'
            AND meta_key NOT LIKE 'managenav%'
            AND meta_key NOT LIKE 'meta-box-%'
            ORDER BY meta_key
            LIMIT 50
        ");
        
        return $meta_keys ? $meta_keys : array();
    }

    /**
     * Get XProfile field groups with fields
     *
     * @return array Array of field groups.
     */
    public function get_xprofile_field_groups() {
        $field_groups = array();

        if (!function_exists('bp_is_active') || !bp_is_active('xprofile')) {
            return $field_groups;
        }

        if (function_exists('bp_xprofile_get_groups')) {
            $groups = bp_xprofile_get_groups(array('fetch_fields' => true));
            
            if (is_array($groups)) {
                foreach ($groups as $group) {
                    $group_data = array(
                        'id' => $group->id,
                        'name' => $group->name,
                        'description' => $group->description,
                        'fields' => array()
                    );
                    
                    if (isset($group->fields) && is_array($group->fields)) {
                        foreach ($group->fields as $field) {
                            $group_data['fields'][] = array(
                                'id' => $field->id,
                                'name' => $field->name,
                                'type' => $field->type,
                                'description' => $field->description,
                                'is_required' => $field->is_required
                            );
                        }
                    }
                    
                    $field_groups[] = $group_data;
                }
            }
        }

        return $field_groups;
    }

    /**
     * Get export statistics
     *
     * @return array Export statistics.
     */
    public function get_export_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Get total users count
        $stats['total_users'] = count_users();
        
        // Get users by role
        $stats['users_by_role'] = array();
        foreach (wp_roles()->get_names() as $role_key => $role_name) {
            $user_count = count_users()['avail_roles'][$role_key] ?? 0;
            $stats['users_by_role'][$role_key] = array(
                'name' => $role_name,
                'count' => $user_count
            );
        }
        
        // Get recent export operations
        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            $stats['recent_exports'] = $wpdb->get_results(
                "SELECT * FROM {$table_name} 
                 WHERE operation_type = 'export' 
                 ORDER BY started_at DESC 
                 LIMIT 5",
                ARRAY_A
            );
        }
        
        return $stats;
    }

    /**
     * Schedule background export
     *
     * @param array $settings Export settings.
     * @return string|false Operation ID or false on failure.
     */
    public function schedule_background_export($settings) {
        $background_process = bp_export_import()->get_component('background_process');
        
        if (!$background_process) {
            return false;
        }
        
        $progress = $this->get_progress();
        $operation_id = $progress->generate_operation_id('export');
        
        if ($background_process->queue_export($operation_id, $settings)) {
            return $operation_id;
        }
        
        return false;
    }
} batch size
     *
     * @var int
     */
    private $batch_size = 500;

    /**
     * Progress tracker
     *
     * @var BP_Export_Import_Progress|null
     */
    private $progress;

    /**
     * Logger instance
     *
     * @var BP_Export_Import_Logger|null
     */
    private $logger;

    /**
     * Validator instance
     *
     * @var BP_Export_Import_Validator|null
     */
    private $validator;

    /**
     * Field ID cache for XProfile fields
     *
     * @var array
     */
    private $field_id_cache = array();

    /**
     * Constructor - MINIMAL initialization
     */
    public function __construct() {
        $this->batch_size = get_option('bp_export_import_export_batch_size', 500);
        
        // DON'T auto-load components or register hooks
        // Only load when explicitly needed
        
        // DON'T call setup_hooks() - let the calling code decide when to register hooks
    }

    /**
     * Setup WordPress hooks - call this only when needed
     */
    public function setup_hooks() {
        add_action('admin_init', array($this, 'handle_export_request'));
        add_action('wp_ajax_bp_export_users', array($this, 'ajax_export_users'));
    }

    /**
     * Get components only when needed
     */
    private function get_progress() {
        if (!$this->progress) {
            $this->progress = bp_export_import()->get_component('progress');
        }
        return $this->progress;
    }

    private function get_logger() {
        if (!$this->logger) {
            $this->logger = bp_export_import()->get_component('logger');
        }
        return $this->logger;
    }

    private function get_validator() {
        if (!$this->validator) {
            $this->validator = bp_export_import()->get_component('validator');
        }
        return $this->validator;
    }

    /**
     * Handle export request from admin form
     */
    public function handle_export_request() {
        if (isset($_POST['bp_export_import_export']) && 
            check_admin_referer('bp_export_import_export_nonce', '_wpnonce_bp_export_import_export')) {
            
            // Check user permissions
            if (!current_user_can('export') && !current_user_can('manage_options')) {
                wp_die(__('You do not have permission to export data.', 'bp-export-import'));
            }
            
            // Validate export options
            $validation_result = $this->validate_export_options($_POST);
            if (is_wp_error($validation_result)) {
                wp_die($validation_result->get_error_message());
            }
            
            $this->export_users();
        }
    }

    /**
     * AJAX handler for export requests
     */
    public function ajax_export_users() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        if (!current_user_can('export') && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        // Get export settings from AJAX request
        $settings = isset($_POST['settings']) ? $_POST['settings'] : array();
        
        // Validate settings
        $validation_result = $this->validate_export_options($settings);
        if (is_wp_error($validation_result)) {
            wp_send_json_error($validation_result->get_error_message());
        }

        // Start export process
        $progress = $this->get_progress();
        $operation_id = $progress->generate_operation_id('export');
        $total_users = $this->get_total_user_count($settings);
        
        $progress->start_operation($operation_id, 'export', $total_users, $settings);
        
        wp_send_json_success(array(
            'operation_id' => $operation_id,
            'message' => __('Export started successfully.', 'bp-export-import')
        ));
    }

    /**
     * Validate export options
     *
     * @param array $options Export options.
     * @return true|WP_Error
     */
    private function validate_export_options($options) {
        // Validate format
        $format = isset($options['export_format']) ? sanitize_text_field($options['export_format']) : 'csv';
        if (!in_array($format, array('csv', 'json', 'xml'))) {
            return new WP_Error('invalid_format', __('Invalid export format.', 'bp-export-import'));
        }

        // Check if at least one field type is selected
        $has_xprofile = !empty($options['xprofile_fields']);
        $has_meta = !empty($options['user_meta_keys']);
        
        if (!$has_xprofile && !$has_meta) {
            return new WP_Error('no_fields', __('Please select at least one field to export.', 'bp-export-import'));
        }

        // Validate user roles if specified
        if (!empty($options['roles'])) {
            $valid_roles = wp_roles()->get_names();
            foreach ($options['roles'] as $role) {
                if (!array_key_exists($role, $valid_roles)) {
                    return new WP_Error('invalid_role', sprintf(
                        __('Invalid user role: %s', 'bp-export-import'),
                        $role
                    ));
                }
            }
        }

        return true;
    }

    /**
     * Main export function
     */
    public function export_users() {
        // Increase memory and time limits
        bp_export_import_increase_memory_limit('512M');
        bp_export_import_increase_time_limit(300);
        
        $format = isset($_POST['export_format']) ? sanitize_text_field($_POST['export_format']) : 'csv';
        $operation_id = 'export_' . time() . '_' . uniqid();
        
        // Get total user count for progress tracking
        $total_users = $this->get_total_user_count($_POST);
        
        // Start progress tracking
        $progress = $this->get_progress();
        if ($progress) {
            $progress->start_operation($operation_id, 'export', $total_users, $_POST);
        }
        
        // Log export start
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log_operation_start($operation_id, 'export', $_POST);
        }
        
        try {
            switch ($format) {
                case 'json':
                    $this->export_as_json_stream($operation_id);
                    break;
                case 'xml':
                    $this->export_as_xml_stream($operation_id);
                    break;
                case 'csv':
                default:
                    $this->export_as_csv_stream($operation_id);
                    break;
            }
            
            // Complete progress tracking
            if ($progress) {
                $progress->complete_operation($operation_id, 'completed');
            }
            
            // Log export completion
            if ($logger) {
                $logger->log_operation_complete($operation_id, 'export');
            }
            
        } catch (Exception $e) {
            // Handle export error
            if ($progress) {
                $progress->complete_operation($operation_id, 'failed', array($e->getMessage()));
            }
            
            if ($logger) {
                $logger->log_operation_error($operation_id, 'export', $e->getMessage());
            }
            
            wp_die(__('Export failed. Please check the error logs.', 'bp-export-import'));
        }
    }

    /**
     * Get total user count for export
     *
     * @param array $settings Export settings.
     * @return int Total user count.
     */
    private function get_total_user_count($settings = array()) {
        $args = array(
            'count_total' => true,
            'fields' => 'ID'
        );

        // Add role filter if specified
        if (!empty($settings['roles'])) {
            $args['role__in'] = array_map('sanitize_text_field', $settings['roles']);
        }

        // Add date filter if specified
        if (!empty($settings['date_from'])) {
            $args['date_query'] = array(
                array(
                    'after' => sanitize_text_field($settings['date_from']),
                    'inclusive' => true,
                ),
            );
        }

        if (!empty($settings['date_to'])) {
            if (!isset($args['date_query'])) {
                $args['date_query'] = array();
            }
            $args['date_query'][] = array(
                'before' => sanitize_text_field($settings['date_to']),
                'inclusive' => true,
            );
        }

        $user_query = new WP_User_Query($args);
        return $user_query->get_total();
    }

    /**
     * Get users batch for export
     *
     * @param int   $page     Page number.
     * @param array $settings Export settings.
     * @return array Array of user objects.
     */
    private function get_users_batch($page = 1, $settings = array()) {
        $args = array(
            'fields'   => 'all',
            'number'   => $this->batch_size,
            'paged'    => $page,
            'orderby'  => 'ID',
            'order'    => 'ASC'
        );

        // Add role filter if specified
        if (!empty($settings['roles'])) {
            $args['role__in'] = array_map('sanitize_text_field', $settings['roles']);
        }

        // Add date filter if specified
        if (!empty($settings['date_from'])) {
            $args['date_query'] = array(
                array(
                    'after' => sanitize_text_field($settings['date_from']),
                    'inclusive' => true,
                ),
            );
        }

        if (!empty($settings['date_to'])) {
            if (!isset($args['date_query'])) {
                $args['date_query'] = array();
            }
            $args['date_query'][] = array(
                'before' => sanitize_text_field($settings['date_to']),
                'inclusive' => true,
            );
        }

        $user_query = new WP_User_Query($args);
        return $user_query->get_results();
    }

    /**
     * Export users as CSV with streaming
     *
     * @param string $operation_id Operation ID for progress tracking.
     */
    private function export_as_csv_stream($operation_id) {
        $filename = 'bp-users-export-' . date('Y-m-d-H-i-s') . '.csv';
        
        // Set headers for file download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for proper UTF-8 encoding in Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        $page = 1;
        $processed = 0;
        $headers_written = false;
        
        do {
            $users = $this->get_users_batch($page, $_POST);
            
            if (empty($users)) {
                break;
            }
            
            foreach ($users as $user) {
                $row_data = $this->prepare_user_data($user, $_POST);
                
                // Write headers only once
                if (!$headers_written) {
                    fputcsv($output, array_keys($row_data));
                    $headers_written = true;
                }
                
                fputcsv($output, array_values($row_data));
                $processed++;
                
                // Clear row data immediately after use
                unset($row_data);
                
                // Update progress periodically
                if ($processed % 50 === 0) {
                    $progress = $this->get_progress();
                    if ($progress) {
                        $progress->update_progress($operation_id, $processed);
                    }
                }
            }
            
            // Clear users array after processing
            unset($users);
            
            // Flush output buffer to prevent memory issues
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            $page++;
            
        } while ($page <= 1000); // Safety limit to prevent infinite loops
        
        // Final progress update
        $progress = $this->get_progress();
        if ($progress) {
            $progress->update_progress($operation_id, $processed);
        }
        
        fclose($output);
        exit;
    }

    /**
     * Export users as JSON with streaming
     *
     * @param string $operation_id Operation ID for progress tracking.
     */
    private function export_as_json_stream($operation_id) {
        $filename = 'bp-users-export-' . date('Y-m-d-H-i-s') . '.json';
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        echo '{"users":[';
        
        $page = 1;
        $processed = 0;
        $first_user = true;
        
        do {
            $users = $this->get_users_batch($page, $_POST);
            
            if (empty($users)) {
                break;
            }
            
            foreach ($users as $user) {
                if (!$first_user) {
                    echo ',';
                }
                
                $user_data = $this->prepare_user_data($user, $_POST);
                echo wp_json_encode($user_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
                // Clear user data immediately
                unset($user_data);
                
                $first_user = false;
                $processed++;
                
                // Update progress periodically
                if ($processed % 50 === 0) {
                    $progress = $this->get_progress();
                    if ($progress) {
                        $progress->update_progress($operation_id, $processed);
                    }
                }
                
                // Flush output
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
            
            // Clear users array
            unset($users);
            $page++;
            
        } while ($page <= 1000); // Safety limit
        
        // Final progress update
        $progress = $this->get_progress();
        if ($progress) {
            $progress->update_progress($operation_id, $processed);
        }
        
        echo ']}';
        exit;
    }

    /**
     * Export