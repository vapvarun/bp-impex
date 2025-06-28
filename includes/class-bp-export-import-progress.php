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
     * Progress transient expiration time (1 hour)
     */
    const PROGRESS_EXPIRATION = 3600;

    /**
     * Constructor
     */
    public function __construct() {
        // No initialization needed
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
        $progress_data = array(
            'operation_id' => $operation_id,
            'type' => $type,
            'status' => 'running',
            'total_records' => $total_records,
            'processed_records' => 0,
            'error_count' => 0,
            'errors' => array(),
            'start_time' => current_time('timestamp'),
            'current_step' => '',
            'settings' => $settings,
            'user_id' => get_current_user_id(),
        );

        return $this->save_progress($operation_id, $progress_data);
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

        $progress_data['processed_records'] = $processed_records;
        $progress_data['error_count'] = count($errors);
        $progress_data['errors'] = array_merge($progress_data['errors'], $errors);
        $progress_data['last_update'] = current_time('timestamp');
        
        if (!empty($current_step)) {
            $progress_data['current_step'] = $current_step;
        }

        // Calculate percentage
        if ($progress_data['total_records'] > 0) {
            $progress_data['percentage'] = min(100, round(($processed_records / $progress_data['total_records']) * 100, 2));
        } else {
            $progress_data['percentage'] = 0;
        }

        // Calculate estimated time remaining
        $progress_data['eta'] = $this->calculate_eta($progress_data);

        return $this->save_progress($operation_id, $progress_data);
    }

    /**
     * Complete an operation
     *
     * @param string $operation_id Operation ID
     * @param string $status Final status (completed/failed/cancelled)
     * @param array $final_errors Final error array
     * @return bool
     */
    public function complete_operation($operation_id, $status = 'completed', $final_errors = array()) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data) {
            return false;
        }

        $progress_data['status'] = $status;
        $progress_data['end_time'] = current_time('timestamp');
        $progress_data['duration'] = $progress_data['end_time'] - $progress_data['start_time'];
        $progress_data['percentage'] = ($status === 'completed') ? 100 : $progress_data['percentage'];
        
        if (!empty($final_errors)) {
            $progress_data['errors'] = array_merge($progress_data['errors'], $final_errors);
            $progress_data['error_count'] = count($progress_data['errors']);
        }

        // Save to database for historical tracking
        $this->save_to_database($progress_data);

        return $this->save_progress($operation_id, $progress_data);
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
     * Get export progress via AJAX
     */
    public function get_export_progress() {
        $operation_id = isset($_POST['operation_id']) ? sanitize_text_field($_POST['operation_id']) : '';
        
        if (empty($operation_id)) {
            wp_send_json_error(__('Invalid operation ID.', 'bp-export-import'));
        }

        $progress = $this->get_progress($operation_id);
        
        if (!$progress) {
            wp_send_json_error(__('Operation not found.', 'bp-export-import'));
        }

        // Security check - ensure user owns this operation
        if ($progress['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        wp_send_json_success($this->format_progress_response($progress));
    }

    /**
     * Get import progress via AJAX
     */
    public function get_import_progress() {
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
        $records_per_second = $progress_data['processed_records'] / max(1, $elapsed_time);
        $remaining_records = $progress_data['total_records'] - $progress_data['processed_records'];
        $eta_seconds = $remaining_records / max(1, $records_per_second);

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
            $hours = round($seconds / 3600);
            return sprintf(__('%d hours', 'bp-export-import'), $hours);
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
            'status' => $progress['status'],
            'percentage' => $progress['percentage'] ?? 0,
            'processed' => $progress['processed_records'],
            'total' => $progress['total_records'],
            'errors' => $progress['error_count'],
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
            if (isset($progress['duration'])) {
                $response['total_duration'] = $this->format_duration($progress['duration']);
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

        return $response;
    }

    /**
     * Save operation to database for historical tracking
     *
     * @param array $progress_data Progress data
     * @return int|false Database ID or false on failure
     */
    private function save_to_database($progress_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bp_export_import_operations';

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

        if (isset($progress_data['end_time'])) {
            $data['completed_at'] = date('Y-m-d H:i:s', $progress_data['end_time']);
        }

        $result = $wpdb->insert($table_name, $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get operation history from database
     *
     * @param int $user_id User ID (0 for all users)
     * @param int $limit Number of records to retrieve
     * @param int $offset Offset for pagination
     * @return array Array of operation records
     */
    public function get_operation_history($user_id = 0, $limit = 20, $offset = 0) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        
        $where_clause = '';
        $where_values = array();

        if ($user_id > 0) {
            $where_clause = ' WHERE user_id = %d';
            $where_values[] = $user_id;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name}{$where_clause} ORDER BY started_at DESC LIMIT %d OFFSET %d",
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
     * Delete old operation records from database
     *
     * @param int $days_old Delete records older than this many days
     * @return int Number of deleted records
     */
    public function cleanup_old_operations($days_old = 30) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE started_at < %s",
            $cutoff_date
        ));

        return $result;
    }

    /**
     * Get active operations for current user
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
                $progress_data['user_id'] == $user_id &&
                isset($progress_data['status']) &&
                $progress_data['status'] === 'running') {
                
                $operation_id = str_replace('_transient_' . self::PROGRESS_PREFIX, '', $transient->option_name);
                $active_operations[] = $operation_id;
            }
        }

        return $active_operations;
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

        // Calculate processing speed
        if (isset($stats['elapsed_time']) && $stats['elapsed_time'] > 0) {
            $stats['records_per_second'] = round($progress['processed_records'] / $stats['elapsed_time'], 2);
        } elseif (isset($stats['duration']) && $stats['duration'] > 0) {
            $stats['records_per_second'] = round($progress['processed_records'] / $stats['duration'], 2);
        }

        return $stats;
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
     * Update step progress
     *
     * @param string $operation_id Operation ID
     * @param string $step_name Step name
     * @param int $step_progress Step progress (0-100)
     * @return bool
     */
    public function update_step($operation_id, $step_name, $step_progress) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data || !isset($progress_data['steps'])) {
            return false;
        }

        // Find and update the step
        foreach ($progress_data['steps'] as &$step) {
            if ($step['name'] === $step_name) {
                $step['progress'] = $step_progress;
                $step['last_update'] = current_time('timestamp');
                break;
            }
        }

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
     * Pause an operation
     *
     * @param string $operation_id Operation ID
     * @return bool
     */
    public function pause_operation($operation_id) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data || $progress_data['status'] !== 'running') {
            return false;
        }

        $progress_data['status'] = 'paused';
        $progress_data['paused_at'] = current_time('timestamp');

        return $this->save_progress($operation_id, $progress_data);
    }

    /**
     * Resume a paused operation
     *
     * @param string $operation_id Operation ID
     * @return bool
     */
    public function resume_operation($operation_id) {
        $progress_data = $this->get_progress($operation_id);
        
        if (!$progress_data || $progress_data['status'] !== 'paused') {
            return false;
        }

        $progress_data['status'] = 'running';
        
        // Adjust start time to account for pause duration
        if (isset($progress_data['paused_at'])) {
            $pause_duration = current_time('timestamp') - $progress_data['paused_at'];
            $progress_data['start_time'] += $pause_duration;
            unset($progress_data['paused_at']);
        }

        return $this->save_progress($operation_id, $progress_data);
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
        $table_name = $wpdb->prefix . 'bp_export_import_operations';

        // Total operations for user
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));
        $summary['total_operations'] = intval($total);

        // Completed today
        $today = date('Y-m-d');
        $completed_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND DATE(completed_at) = %s AND status = 'completed'",
            $user_id,
            $today
        ));
        $summary['completed_today'] = intval($completed_today);

        // Success rate (last 30 days)
        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        $recent_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
             FROM {$table_name} 
             WHERE user_id = %d AND started_at >= %s",
            $user_id,
            $thirty_days_ago
        ));

        if ($recent_stats && $recent_stats->total > 0) {
            $summary['success_rate'] = round(($recent_stats->completed / $recent_stats->total) * 100, 1);
        }

        return $summary;
    }
}