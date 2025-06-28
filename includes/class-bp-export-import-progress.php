<?php
/**
 * BP Export Import Progress Class
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
     */
    const PROGRESS_PREFIX = 'bp_export_import_progress_';

    /**
     * Progress transient expiration time (2 hours)
     */
    const PROGRESS_EXPIRATION = 7200;

    /**
     * Database table name for operations
     */
    private $table_name;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bp_export_import_operations';
        
        // Get logger instance if available
        if (function_exists('bp_export_import')) {
            $this->logger = bp_export_import()->get_component('logger');
        }
        
        $this->setup_hooks();
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // AJAX hooks for progress tracking
        add_action('wp_ajax_bp_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_bp_cancel_operation', array($this, 'ajax_cancel_operation'));
        add_action('wp_ajax_bp_get_operation_stats', array($this, 'ajax_get_operation_stats'));
        
        // Cleanup hook
        add_action('bp_export_import_cleanup', array($this, 'cleanup_old_operations'));
    }

    /**
     * Start tracking an operation
     *
     * @param string $operation_id Unique operation ID
     * @param string $type Operation type (export/import)
     * @param int $total_records Total number of records to process
     * @param array $settings Operation settings
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
            'warnings' => array(),
            'start_time' => $current_time,
            'last_update' => $current_time,
            'current_step' => __('Initializing...', 'bp-export-import'),
            'settings' => $settings,
            'user_id' => $user_id,
            'percentage' => 0,
            'steps' => array(),
            'events' => array(),
            'performance_data' => array(
                'records_per_second' => 0,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            )
        );

        // Save to transient for real-time access
        $result = $this->save_progress($operation_id, $progress_data);
        
        // Log operation start
        if ($this->logger) {
            $this->logger->log_info("Operation started: {$type}", array(
                'operation_id' => $operation_id,
                'total_records' => $total_records,
                'user_id' => $user_id
            ));
        }
        
        // Also save to database for history
        $this->save_operation_to_db($progress_data);
        
        return $result;
    }

    /**
     * Update operation progress
     *
     * @param string $operation_id Operation ID
     * @param int $processed_records Number of processed records
     * @param array $errors Array of error messages
     * @param string $current_step Current step description
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
        
        // Add new errors
        if (!empty($errors)) {
            $progress_data['errors'] = array_merge($progress_data['errors'], $errors);
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

        // Calculate performance metrics
        $elapsed_time = max(1, $current_time - $progress_data['start_time']);
        $progress_data['performance_data']['records_per_second'] = round($processed_records / $elapsed_time, 2);
        $progress_data['performance_data']['memory_usage'] = memory_get_usage(true);
        $progress_data['performance_data']['peak_memory'] = memory_get_peak_usage(true);
        
        // Calculate ETA
        $progress_data['eta'] = $this->calculate_eta($progress_data);
        $progress_data['elapsed_time'] = $elapsed_time;
        $progress_data['elapsed_time_formatted'] = $this->format_duration($elapsed_time);

        // Update database record
        $this->update_operation_in_db($operation_id, $progress_data);

        return $this->save_progress($operation_id, $progress_data);
    }

    /**
     * Complete an operation
     *
     * @param string $operation_id Operation ID
     * @param string $status Final status (completed/failed/cancelled)
     * @param array $final_errors Final error array
     * @param array $additional_data Additional completion data
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
        $progress_data['duration_formatted'] = $this->format_duration($progress_data['duration']);
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
        
        // Add final errors
        if (!empty($final_errors)) {
            $progress_data['errors'] = array_merge($progress_data['errors'], $final_errors);
            $progress_data['error_count'] = count($progress_data['errors']);
        }
        
        // Add any additional completion data
        if (!empty($additional_data)) {
            $progress_data = array_merge($progress_data, $additional_data);
        }
        
        // Calculate final performance metrics
        if ($progress_data['duration'] > 0) {
            $progress_data['performance_data']['avg_records_per_second'] = round(
                $progress_data['processed_records'] / $progress_data['duration'], 2
            );
        }

        // Log completion
        if ($this->logger) {
            $this->logger->log_info("Operation completed: {$progress_data['type']}", array(
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
     * @param string $operation_id Operation ID
     * @return array|false Progress data or false if not found
     */
    public function get_progress($operation_id) {
        return get_transient(self::PROGRESS_PREFIX . $operation_id);
    }

    /**
     * Cancel an operation
     *
     * @param string $operation_id Operation ID
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
     * @param string $operation_id Operation ID
     * @return bool
     */
    public function cleanup_progress($operation_id) {
        return delete_transient(self::PROGRESS_PREFIX . $operation_id);
    }

    /**
     * Get active operations for a user
     *
     * @param int $user_id User ID (0 for current user)
     * @return array Array of active operation IDs
     */
    public function get_active_operations($user_id = 0) {
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }

        $active_operations = array();
        
        // Get all transients that match our progress pattern
        global $wpdb;
        
        $transient_pattern = '_transient_' . self::PROGRESS_PREFIX . '%';
        $transients = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $transient_pattern
        ));

        foreach ($transients as $transient) {
            $progress_data = maybe_unserialize($transient->option_value);
            
            if (is_array($progress_data) && 
                isset($progress_data['user_id']) && 
                isset($progress_data['status'])) {
                
                // Check user permission
                $has_access = ($progress_data['user_id'] == $user_id) || 
                             ($user_id === 0 && current_user_can('manage_options'));
                
                if ($has_access && $progress_data['status'] === 'running') {
                    $operation_id = str_replace('_transient_' . self::PROGRESS_PREFIX, '', $transient->option_name);
                    $active_operations[] = $operation_id;
                }
            }
        }

        return $active_operations;
    }

    /**
     * Get operation history from database
     *
     * @param int $user_id User ID (0 for all users if admin)
     * @param int $limit Number of records to retrieve
     * @param int $offset Offset for pagination
     * @return array Array of operation records
     */
    public function get_operation_history($user_id = 0, $limit = 20, $offset = 0) {
        global $wpdb;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            return array();
        }
        
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
            "SELECT * FROM {$this->table_name}{$where_clause} ORDER BY started_at DESC LIMIT %d OFFSET %d",
            array_merge($where_values, array($limit, $offset))
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Unserialize data
        foreach ($results as &$result) {
            $result['settings'] = maybe_unserialize($result['settings']);
            $result['errors'] = maybe_unserialize($result['errors']);
        }

        return $results;
    }

    /**
     * Generate unique operation ID
     *
     * @param string $type Operation type (export/import)
     * @return string Unique operation ID
     */
    public function generate_operation_id($type = 'operation') {
        return $type . '_' . uniqid() . '_' . time();
    }

    /**
     * Check if operation is still running
     *
     * @param string $operation_id Operation ID
     * @return bool True if running, false otherwise
     */
    public function is_operation_running($operation_id) {
        $progress = $this->get_progress($operation_id);
        return $progress && isset($progress['status']) && $progress['status'] === 'running';
    }

    /**
     * Add step to operation progress
     *
     * @param string $operation_id Operation ID
     * @param string $step_name Step name
     * @param string $step_description Step description
     * @param int $step_progress Step progress (0-100)
     * @return bool
     */
    public function add_step($operation_id, $step_name, $step_description = '', $step_progress = 0) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data) {
            return false;
        }

        if (!isset($progress_data['steps'])) {
            $progress_data['steps'] = array();
        }

        $progress_data['steps'][] = array(
            'name' => $step_name,
            'description' => $step_description,
            'progress' => $step_progress,
            'timestamp' => current_time('timestamp'),
        );

        $progress_data['current_step'] = $step_description ?: $step_name;

        return $this->save_progress($operation_id, $progress_data);
    }

    /**
     * Log operation event
     *
     * @param string $operation_id Operation ID
     * @param string $event Event message
     * @param string $level Event level (info, warning, error)
     * @return bool
     */
    public function log_event($operation_id, $event, $level = 'info') {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data) {
            return false;
        }

        if (!isset($progress_data['events'])) {
            $progress_data['events'] = array();
        }

        $progress_data['events'][] = array(
            'message' => $event,
            'level' => $level,
            'timestamp' => current_time('timestamp'),
        );

        // Keep only last 50 events to prevent memory issues
        if (count($progress_data['events']) > 50) {
            $progress_data['events'] = array_slice($progress_data['events'], -50);
        }

        return $this->save_progress($operation_id, $progress_data);
    }

    /**
     * Get operation statistics
     *
     * @param string $operation_id Operation ID
     * @return array|false Operation statistics or false if not found
     */
    public function get_operation_stats($operation_id) {
        $progress = $this->get_progress($operation_id);
        
        if (!$progress) {
            return false;
        }

        $stats = array(
            'operation_id' => $operation_id,
            'type' => $progress['type'],
            'status' => $progress['status'],
            'percentage' => $progress['percentage'] ?? 0,
            'total_records' => $progress['total_records'],
            'processed_records' => $progress['processed_records'],
            'success_rate' => 0,
            'error_count' => $progress['error_count'],
            'start_time' => $progress['start_time'],
            'performance_data' => $progress['performance_data'] ?? array()
        );

        // Calculate success rate
        if ($progress['processed_records'] > 0) {
            $successful_records = $progress['processed_records'] - $progress['error_count'];
            $stats['success_rate'] = round(($successful_records / $progress['processed_records']) * 100, 2);
        }

        // Add timing information
        if (isset($progress['end_time'])) {
            $stats['end_time'] = $progress['end_time'];
            $stats['duration'] = $progress['end_time'] - $progress['start_time'];
            $stats['duration_formatted'] = $this->format_duration($stats['duration']);
        } else {
            $stats['elapsed_time'] = current_time('timestamp') - $progress['start_time'];
            $stats['elapsed_time_formatted'] = $this->format_duration($stats['elapsed_time']);
        }

        return $stats;
    }

    /**
     * Get progress summary for dashboard
     *
     * @param int $user_id User ID (0 for current user)
     * @return array Progress summary
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

        // Get active operations
        $active_operations = $this->get_active_operations($user_id);
        $summary['active_operations'] = count($active_operations);
        $summary['active_operation_ids'] = $active_operations;

        // Get database statistics
        global $wpdb;

        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            return $summary;
        }

        $user_filter = '';
        if (!current_user_can('manage_options')) {
            $user_filter = $wpdb->prepare(' AND user_id = %d', $user_id);
        }

        // Total operations for user
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE 1=1{$user_filter}"
        );
        $summary['total_operations'] = intval($total);

        // Completed today
        $today = date('Y-m-d');
        $completed_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE DATE(completed_at) = %s AND status = 'completed'{$user_filter}",
            $today
        ));
        $summary['completed_today'] = intval($completed_today);

        // Success rate (last 30 days)
        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        $recent_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
             FROM {$this->table_name} 
             WHERE started_at >= %s{$user_filter}",
            $thirty_days_ago
        ));

        if ($recent_stats && $recent_stats->total > 0) {
            $summary['success_rate'] = round(($recent_stats->completed / $recent_stats->total) * 100, 1);
        }

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

        wp_send_json_success($this->format_progress_response($progress));
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
     * @param int $days_old Delete records older than this many days
     * @return int Number of deleted records
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
     * @param string $operation_id Operation ID
     * @param array $progress_data Progress data
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
     * Calculate estimated time of arrival
     *
     * @param array $progress_data Progress data
     * @return string Formatted ETA or empty string
     */
    private function calculate_eta($progress_data) {
        if ($progress_data['processed_records'] <= 0 || $progress_data['total_records'] <= 0) {
            return '';
        }

        $elapsed_time = current_time('timestamp') - $progress_data['start_time'];
        if ($elapsed_time <= 0) {
            return '';
        }

        $records_per_second = $progress_data['processed_records'] / $elapsed_time;
        $remaining_records = $progress_data['total_records'] - $progress_data['processed_records'];
        
        if ($records_per_second <= 0) {
            return '';
        }

        $eta_seconds = $remaining_records / $records_per_second;

        return $this->format_duration($eta_seconds);
    }

    /**
     * Format duration in human readable format
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return sprintf(__('%d seconds', 'bp-export-import'), round($seconds));
        } elseif ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return sprintf(__('%d minutes', 'bp-export-import'), $minutes);
        } else {
            $hours = round($seconds / 3600, 1);
            return sprintf(__('%s hours', 'bp-export-import'), $hours);
        }
    }

    /**
     * Format progress response for AJAX
     *
     * @param array $progress Progress data
     * @return array Formatted response
     */
    private function format_progress_response($progress) {
        $response = array(
            'operation_id' => $progress['operation_id'],
            'type' => $progress['type'],
            'status' => $progress['status'],
            'percentage' => $progress['percentage'] ?? 0,
            'processed_records' => $progress['processed_records'],
            'total_records' => $progress['total_records'],
            'error_count' => $progress['error_count'],
            'current_step' => $progress['current_step'] ?? '',
        );

        // Add timing information if available
        if (isset($progress['start_time'])) {
            $response['elapsed_time'] = $this->format_duration(
                current_time('timestamp') - $progress['start_time']
            );
        }

        if (isset($progress['eta']) && !empty($progress['eta'])) {
            $response['eta'] = $progress['eta'];
        }

        // Add completion information for finished operations
        if (in_array($progress['status'], array('completed', 'failed', 'cancelled'))) {
            if (isset($progress['duration_formatted'])) {
                $response['total_duration'] = $progress['duration_formatted'];
            }
            
            if (isset($progress['end_time'])) {
                $response['completed_at'] = date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $progress['end_time']
                );
            }
        }

        // Add recent errors (limit to last 5)
        if (!empty($progress['errors'])) {
            $response['recent_errors'] = array_slice($progress['errors'], -5);
        }

        // Add performance data
        if (isset($progress['performance_data'])) {
            $response['performance'] = $progress['performance_data'];
        }

        return $response;
    }

    /**
     * Save operation to database for historical tracking
     *
     * @param array $progress_data Progress data
     * @return int|false Database ID or false on failure
     */
    private function save_operation_to_db($progress_data) {
        global $wpdb;

        $data = array(
            'operation_type' => $progress_data['type'],
            'status' => $progress_data['status'],
            'user_id' => $progress_data['user_id'],
            'total_records' => $progress_data['total_records'],
            'processed_records' => $progress_data['processed_records'],
            'error_count' => $progress_data['error_count'],
            'started_at' => date('Y-m-d H:i:s', $progress_data['start_time']),
            'settings' => maybe_serialize($progress_data['settings']),
            'errors' => maybe_serialize($progress_data['errors']),
        );

        $result = $wpdb->insert($this->table_name, $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update operation in database
     *
     * @param string $operation_id Operation ID
     * @param array $progress_data Progress data
     */
    private function update_operation_in_db($operation_id, $progress_data) {
        global $wpdb;

        $data = array(
            'processed_records' => $progress_data['processed_records'],
            'error_count' => $progress_data['error_count'],
            'errors' => maybe_serialize($progress_data['errors']),
        );

        $wpdb->update(
            $this->table_name,
            $data,
            array('user_id' => $progress_data['user_id'], 'started_at' => date('Y-m-d H:i:s', $progress_data['start_time'])),
            array('%d', '%d', '%s'),
            array('%d', '%s')
        );
    }

    /**
     * Complete operation in database
     *
     * @param string $operation_id Operation ID
     * @param array $progress_data Progress data
     */
    private function complete_operation_in_db($operation_id, $progress_data) {
        global $wpdb;

        $data = array(
            'status' => $progress_data['status'],
            'processed_records' => $progress_data['processed_records'],
            'error_count' => $progress_data['error_count'],
            'completed_at' => date('Y-m-d H:i:s', $progress_data['end_time']),
            'errors' => maybe_serialize($progress_data['errors']),
        );

        $wpdb->update(
            $this->table_name,
            $data,
            array('user_id' => $progress_data['user_id'], 'started_at' => date('Y-m-d H:i:s', $progress_data['start_time'])),
            array('%s', '%d', '%d', '%s', '%s'),
            array('%d', '%s')
        );
    }
}