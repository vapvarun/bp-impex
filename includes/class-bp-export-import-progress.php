<?php
/**
 * BP Export Import Progress Class - OPTIMIZED VERSION
 *
 * Handles progress tracking for import/export operations
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Progress {

    /**
     * Progress transient prefix
     *
     * @var string
     */
    const PROGRESS_PREFIX = 'bp_export_import_progress_';

    /**
     * Progress transient expiration time (2 hours)
     *
     * @var int
     */
    const PROGRESS_EXPIRATION = 7200;

    /**
     * Database table name for operations
     *
     * @var string
     */
    private $table_name;

    /**
     * Logger instance
     *
     * @var BP_Export_Import_Logger|null
     */
    private $logger;

    /**
     * AJAX hooks registered flag
     *
     * @var bool
     */
    private $ajax_hooks_registered = false;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bp_export_import_operations';
        
        // Only register AJAX hooks if doing AJAX
        if (wp_doing_ajax() && !$this->ajax_hooks_registered) {
            $this->setup_ajax_hooks();
            $this->ajax_hooks_registered = true;
        }
        
        // Non-AJAX hooks
        add_action('bp_export_import_cleanup', array($this, 'cleanup_old_operations'));
    }

    /**
     * Setup AJAX hooks only when needed
     */
    private function setup_ajax_hooks() {
        add_action('wp_ajax_bp_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_bp_cancel_operation', array($this, 'ajax_cancel_operation'));
        add_action('wp_ajax_bp_get_operation_stats', array($this, 'ajax_get_operation_stats'));
    }

    /**
     * Get logger instance (lazy loading)
     *
     * @return BP_Export_Import_Logger|null
     */
    private function get_logger() {
        if (!$this->logger && function_exists('bp_export_import')) {
            $this->logger = bp_export_import()->get_component('logger');
        }
        return $this->logger;
    }

    /**
     * Start tracking an operation
     *
     * @param string $operation_id    Unique operation ID.
     * @param string $type           Operation type (export/import).
     * @param int    $total_records  Total number of records to process.
     * @param array  $settings       Operation settings.
     * @return bool
     */
    public function start_operation($operation_id, $type, $total_records, $settings = array()) {
        $user_id = get_current_user_id();
        $current_time = current_time('timestamp');
        
        $progress_data = array(
            'operation_id' => $operation_id,
            'type' => $type,
            'status' => 'running',
            'total_records' => intval($total_records),
            'processed_records' => 0,
            'error_count' => 0,
            'errors' => array(),
            'start_time' => $current_time,
            'last_update' => $current_time,
            'current_step' => __('Initializing...', 'bp-export-import'),
            'settings' => $settings,
            'user_id' => $user_id,
            'percentage' => 0,
        );

        // Save to transient for real-time access
        $result = $this->save_progress($operation_id, $progress_data);
        
        // Log operation start
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log_info("Operation started: {$type}", array(
                'operation_id' => $operation_id,
                'total_records' => $total_records,
                'user_id' => $user_id
            ));
        }
        
        // Also save to database for history (minimal data)
        $this->save_operation_to_db($progress_data);
        
        return $result;
    }

    /**
     * Update operation progress
     *
     * @param string $operation_id      Operation ID.
     * @param int    $processed_records Number of processed records.
     * @param array  $errors           Array of error messages.
     * @param string $current_step     Current step description.
     * @return bool
     */
    public function update_progress($operation_id, $processed_records, $errors = array(), $current_step = '') {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data) {
            return false;
        }

        $current_time = current_time('timestamp');
        $processed_records = intval($processed_records);
        
        // Update basic progress data
        $progress_data['processed_records'] = $processed_records;
        $progress_data['last_update'] = $current_time;
        
        // Add new errors (limit to prevent memory issues)
        if (!empty($errors)) {
            $progress_data['errors'] = array_merge($progress_data['errors'], $errors);
            // Keep only last 20 errors to prevent memory bloat
            if (count($progress_data['errors']) > 20) {
                $progress_data['errors'] = array_slice($progress_data['errors'], -20);
            }
            $progress_data['error_count'] = count($progress_data['errors']);
        }
        
        // Update current step
        if (!empty($current_step)) {
            $progress_data['current_step'] = $current_step;
        }

        // Calculate percentage
        if ($progress_data['total_records'] > 0) {
            $progress_data['percentage'] = min(100, round(($processed_records / $progress_data['total_records']) * 100, 2));
        } else {
            $progress_data['percentage'] = 0;
        }

        return $this->save_progress($operation_id, $progress_data);
    }

    /**
     * Complete an operation
     *
     * @param string $operation_id    Operation ID.
     * @param string $status         Final status (completed/failed/cancelled).
     * @param array  $final_errors   Final error array.
     * @param array  $additional_data Additional completion data.
     * @return bool
     */
    public function complete_operation($operation_id, $status = 'completed', $final_errors = array(), $additional_data = array()) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data) {
            return false;
        }

        $current_time = current_time('timestamp');
        
        // Update final status
        $progress_data['status'] = $status;
        $progress_data['end_time'] = $current_time;
        $progress_data['duration'] = $current_time - $progress_data['start_time'];
        $progress_data['last_update'] = $current_time;
        
        // Set percentage based on status
        if ($status === 'completed') {
            $progress_data['percentage'] = 100;
            $progress_data['current_step'] = __('Completed successfully', 'bp-export-import');
        } elseif ($status === 'failed') {
            $progress_data['current_step'] = __('Operation failed', 'bp-export-import');
        } elseif ($status === 'cancelled') {
            $progress_data['current_step'] = __('Operation cancelled', 'bp-export-import');
        }
        
        // Add final errors (limited)
        if (!empty($final_errors)) {
            $progress_data['errors'] = array_merge($progress_data['errors'], $final_errors);
            // Keep only last 20 errors
            if (count($progress_data['errors']) > 20) {
                $progress_data['errors'] = array_slice($progress_data['errors'], -20);
            }
            $progress_data['error_count'] = count($progress_data['errors']);
        }

        // Log completion
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log_info("Operation completed: {$progress_data['type']}", array(
                'operation_id' => $operation_id,
                'status' => $status,
                'processed_records' => $progress_data['processed_records'],
                'error_count' => $progress_data['error_count'],
                'duration' => $progress_data['duration']
            ));
        }

        // Update database with final status
        $this->complete_operation_in_db($operation_id, $progress_data);

        // Save final progress state
        $result = $this->save_progress($operation_id, $progress_data);
        
        // Schedule cleanup of transient after some time
        wp_schedule_single_event(time() + 3600, 'bp_export_import_cleanup_transient', array($operation_id));
        
        return $result;
    }

    /**
     * Get operation progress
     *
     * @param string $operation_id Operation ID.
     * @return array|false Progress data or false if not found.
     */
    public function get_progress($operation_id) {
        return get_transient(self::PROGRESS_PREFIX . $operation_id);
    }

    /**
     * Cancel an operation
     *
     * @param string $operation_id Operation ID.
     * @return bool
     */
    public function cancel_operation($operation_id) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data || $progress_data['status'] !== 'running') {
            return false;
        }
        
        // Set cancellation flag
        $progress_data['status'] = 'cancelling';
        $progress_data['current_step'] = __('Cancelling operation...', 'bp-export-import');
        $this->save_progress($operation_id, $progress_data);
        
        // Complete with cancelled status
        return $this->complete_operation($operation_id, 'cancelled');
    }

    /**
     * Clean up progress data
     *
     * @param string $operation_id Operation ID.
     * @return bool
     */
    public function cleanup_progress($operation_id) {
        return delete_transient(self::PROGRESS_PREFIX . $operation_id);
    }

    /**
     * Get active operations for a user (OPTIMIZED)
     *
     * @param int $user_id User ID (0 for current user).
     * @return array Array of active operation IDs.
     */
    public function get_active_operations($user_id = 0) {
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }

        global $wpdb;
        
        // CRITICAL FIX: Use more efficient query with proper limits
        $prefix = self::PROGRESS_PREFIX;
        $transient_names = $wpdb->get_col($wpdb->prepare(
            "SELECT SUBSTRING(option_name, %d) as operation_id
             FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s
             LIMIT 5", // Very small limit
            strlen('_transient_' . $prefix) + 1,
            '_transient_' . $prefix . '%',
            '%_timeout'
        ));

        $active_operations = array();
        
        // Process maximum 5 operations to prevent memory issues
        foreach (array_slice($transient_names, 0, 5) as $operation_id) {
            $progress_data = get_transient($prefix . $operation_id);
            
            if (is_array($progress_data) && 
                isset($progress_data['user_id']) && 
                isset($progress_data['status']) &&
                $progress_data['status'] === 'running') {
                
                // Check user permission
                $has_access = ($progress_data['user_id'] == $user_id) || 
                             current_user_can('manage_options');
                
                if ($has_access) {
                    $active_operations[] = $operation_id;
                }
            }
        }

        return $active_operations;
    }

    /**
     * Get operation history from database (OPTIMIZED)
     *
     * @param int $user_id User ID (0 for all users if admin).
     * @param int $limit   Number of records to retrieve.
     * @param int $offset  Offset for pagination.
     * @return array Array of operation records.
     */
    public function get_operation_history($user_id = 0, $limit = 10, $offset = 0) {
        global $wpdb;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            return array();
        }
        
        // Limit to prevent memory issues
        $limit = min($limit, 20);
        
        $where_clause = '';
        $where_values = array();

        if ($user_id > 0) {
            $where_clause = ' WHERE user_id = %d';
            $where_values[] = $user_id;
        } elseif (!current_user_can('manage_options')) {
            // Non-admin users can only see their own operations
            $where_clause = ' WHERE user_id = %d';
            $where_values[] = get_current_user_id();
        }

        $sql = $wpdb->prepare(
            "SELECT id, operation_type, status, user_id, total_records, processed_records, error_count, started_at, completed_at 
             FROM {$this->table_name}{$where_clause} 
             ORDER BY started_at DESC 
             LIMIT %d OFFSET %d",
            array_merge($where_values, array($limit, $offset))
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Generate unique operation ID
     *
     * @param string $type Operation type (export/import).
     * @return string Unique operation ID.
     */
    public function generate_operation_id($type = 'operation') {
        return $type . '_' . uniqid() . '_' . time();
    }

    /**
     * Check if operation is still running
     *
     * @param string $operation_id Operation ID.
     * @return bool True if running, false otherwise.
     */
    public function is_operation_running($operation_id) {
        $progress = $this->get_progress($operation_id);
        return $progress && isset($progress['status']) && $progress['status'] === 'running';
    }

    /**
     * Get progress summary for dashboard (OPTIMIZED)
     *
     * @param int $user_id User ID (0 for current user).
     * @return array Progress summary.
     */
    public function get_progress_summary($user_id = 0) {
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }

        $summary = array(
            'active_operations' => 0,
            'completed_today' => 0,
            'total_operations' => 0,
            'success_rate' => 0,
            'active_operation_ids' => array(),
        );

        // Get active operations (limited)
        $active_operations = $this->get_active_operations($user_id);
        $summary['active_operations'] = count($active_operations);
        $summary['active_operation_ids'] = $active_operations;

        return $summary;
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

        $progress = $this->get_progress($operation_id);
        
        if (!$progress) {
            wp_send_json_error(__('Operation not found.', 'bp-export-import'));
        }

        // Security check
        if ($progress['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        wp_send_json_success($progress);
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

        $progress = $this->get_progress($operation_id);
        
        if (!$progress) {
            wp_send_json_error(__('Operation not found.', 'bp-export-import'));
        }

        // Security check
        if ($progress['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        if ($this->cancel_operation($operation_id)) {
            wp_send_json_success(__('Operation cancelled successfully.', 'bp-export-import'));
        } else {
            wp_send_json_error(__('Failed to cancel operation.', 'bp-export-import'));
        }
    }

    /**
     * AJAX handler for getting operation statistics
     */
    public function ajax_get_operation_stats() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');

        $user_id = current_user_can('manage_options') ? 0 : get_current_user_id();
        $summary = $this->get_progress_summary($user_id);
        
        wp_send_json_success($summary);
    }

    /**
     * Delete old operation records from database
     *
     * @param int $days_old Delete records older than this many days.
     * @return int Number of deleted records.
     */
    public function cleanup_old_operations($days_old = 30) {
        global $wpdb;

        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            return 0;
        }

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE started_at < %s",
            $cutoff_date
        ));

        return $result;
    }

    /**
     * Save progress data to transient
     *
     * @param string $operation_id  Operation ID.
     * @param array  $progress_data Progress data.
     * @return bool
     */
    private function save_progress($operation_id, $progress_data) {
        return set_transient(
            self::PROGRESS_PREFIX . $operation_id,
            $progress_data,
            self::PROGRESS_EXPIRATION
        );
    }

    /**
     * Save operation to database for historical tracking (MINIMAL DATA)
     *
     * @param array $progress_data Progress data.
     * @return int|false Database ID or false on failure.
     */
    private function save_operation_to_db($progress_data) {
        global $wpdb;

        $data = array(
            'operation_type' => $progress_data['type'],
            'status' => $progress_data['status'],
            'user_id' => $progress_data['user_id'],
            'total_records' => $progress_data['total_records'],
            'processed_records' => $progress_data['processed_records'],
            'error_count' => 0,
            'started_at' => date('Y-m-d H:i:s', $progress_data['start_time']),
            'settings' => '', // Don't store settings to save space
            'errors' => '',   // Don't store errors to save space
        );

        $result = $wpdb->insert($this->table_name, $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Complete operation in database
     *
     * @param string $operation_id  Operation ID.
     * @param array  $progress_data Progress data.
     */
    private function complete_operation_in_db($operation_id, $progress_data) {
        global $wpdb;

        $data = array(
            'status' => $progress_data['status'],
            'processed_records' => $progress_data['processed_records'],
            'error_count' => $progress_data['error_count'],
            'completed_at' => date('Y-m-d H:i:s', $progress_data['end_time']),
        );

        $wpdb->update(
            $this->table_name,
            $data,
            array('user_id' => $progress_data['user_id'], 'started_at' => date('Y-m-d H:i:s', $progress_data['start_time'])),
            array('%s', '%d', '%d', '%s'),
            array('%d', '%s')
        );
    }
}