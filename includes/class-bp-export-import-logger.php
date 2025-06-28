<?php
/**
 * BP Export Import Logger Class
 *
 * Handles logging for the BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Logger {

    /**
     * Log file path
     */
    private $log_file;

    /**
     * Whether logging is enabled
     */
    private $logging_enabled;

    /**
     * Maximum log file size (5MB)
     */
    const MAX_LOG_SIZE = 5242880;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logging_enabled = get_option('bp_export_import_enable_logging', true);
        $this->setup_log_file();
    }

    /**
     * Setup log file path
     */
    private function setup_log_file() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/bp-export-import-logs/';
        
        // Create log directory if it doesn't exist
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Create .htaccess to prevent direct access
            $htaccess_content = "Options -Indexes\nDeny from all\n";
            file_put_contents($log_dir . '.htaccess', $htaccess_content);
        }
        
        $this->log_file = $log_dir . 'bp-export-import.log';
    }

    /**
     * Log an info message
     *
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function log_info($message, $context = array()) {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function log_warning($message, $context = array()) {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log an error message
     *
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function log_error($message, $context = array()) {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log a debug message
     *
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function log_debug($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log('DEBUG', $message, $context);
        }
    }

    /**
     * Log a message with specified level
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private function log($level, $message, $context = array()) {
        if (!$this->logging_enabled) {
            return;
        }

        // Check log file size and rotate if necessary
        $this->rotate_log_if_needed();

        // Prepare log entry
        $timestamp = current_time('Y-m-d H:i:s');
        $user_id = get_current_user_id();
        $user_info = $user_id ? "User:{$user_id}" : 'System';
        
        $log_entry = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $level,
            $user_info,
            $message
        );

        // Add context if provided
        if (!empty($context)) {
            $log_entry .= ' | Context: ' . json_encode($context);
        }

        $log_entry .= "\n";

        // Write to log file
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);

        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[BP Export Import] ' . $level . ': ' . $message);
        }
    }

    /**
     * Rotate log file if it's too large
     */
    private function rotate_log_if_needed() {
        if (!file_exists($this->log_file)) {
            return;
        }

        if (filesize($this->log_file) > self::MAX_LOG_SIZE) {
            // Create backup
            $backup_file = str_replace('.log', '-' . date('Y-m-d-H-i-s') . '.log', $this->log_file);
            rename($this->log_file, $backup_file);

            // Clean up old backups (keep only last 5)
            $this->cleanup_old_logs();
        }
    }

    /**
     * Clean up old log files
     */
    private function cleanup_old_logs() {
        $log_dir = dirname($this->log_file);
        $pattern = $log_dir . '/bp-export-import-*.log';
        $files = glob($pattern);

        if (count($files) > 5) {
            // Sort by modification time (oldest first)
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            // Remove oldest files, keep only 5
            $files_to_remove = array_slice($files, 0, count($files) - 5);
            foreach ($files_to_remove as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Get log file contents
     *
     * @param int $lines Number of lines to retrieve (0 for all)
     * @return string Log contents
     */
    public function get_log_contents($lines = 100) {
        if (!file_exists($this->log_file)) {
            return '';
        }

        if ($lines === 0) {
            return file_get_contents($this->log_file);
        }

        // Get last N lines
        $file = file($this->log_file);
        if (count($file) <= $lines) {
            return implode('', $file);
        }

        return implode('', array_slice($file, -$lines));
    }

    /**
     * Clear log file
     *
     * @return bool Success status
     */
    public function clear_log() {
        if (file_exists($this->log_file)) {
            return unlink($this->log_file);
        }
        return true;
    }

    /**
     * Get log file size
     *
     * @return int File size in bytes
     */
    public function get_log_size() {
        if (file_exists($this->log_file)) {
            return filesize($this->log_file);
        }
        return 0;
    }

    /**
     * Check if logging is enabled
     *
     * @return bool
     */
    public function is_logging_enabled() {
        return $this->logging_enabled;
    }

    /**
     * Enable logging
     */
    public function enable_logging() {
        $this->logging_enabled = true;
        update_option('bp_export_import_enable_logging', true);
    }

    /**
     * Disable logging
     */
    public function disable_logging() {
        $this->logging_enabled = false;
        update_option('bp_export_import_enable_logging', false);
    }

    /**
     * Log operation start
     *
     * @param string $operation_id Operation ID
     * @param string $operation_type Operation type (export/import)
     * @param array $settings Operation settings
     */
    public function log_operation_start($operation_id, $operation_type, $settings = array()) {
        $this->log_info("Operation started: {$operation_type}", array(
            'operation_id' => $operation_id,
            'settings' => $settings
        ));
    }

    /**
     * Log operation complete
     *
     * @param string $operation_id Operation ID
     * @param string $operation_type Operation type
     * @param array $results Operation results
     */
    public function log_operation_complete($operation_id, $operation_type, $results = array()) {
        $this->log_info("Operation completed: {$operation_type}", array(
            'operation_id' => $operation_id,
            'results' => $results
        ));
    }

    /**
     * Log operation error
     *
     * @param string $operation_id Operation ID
     * @param string $operation_type Operation type
     * @param string $error_message Error message
     * @param array $context Additional context
     */
    public function log_operation_error($operation_id, $operation_type, $error_message, $context = array()) {
        $this->log_error("Operation failed: {$operation_type} - {$error_message}", array(
            'operation_id' => $operation_id,
            'context' => $context
        ));
    }

    /**
     * Display admin notice for error messages
     *
     * @param string $message Error message
     */
    public function display_error_notice($message) {
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * Display admin notice for success messages
     *
     * @param string $message Success message
     */
    public function display_success_notice($message) {
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * Display admin notice for warning messages
     *
     * @param string $message Warning message
     */
    public function display_warning_notice($message) {
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * Display admin notice for info messages
     *
     * @param string $message Info message
     */
    public function display_info_notice($message) {
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * Get recent log entries
     *
     * @param int $limit Number of entries to retrieve
     * @param string $level Log level filter (optional)
     * @return array Array of log entries
     */
    public function get_recent_entries($limit = 50, $level = '') {
        if (!file_exists($this->log_file)) {
            return array();
        }

        $lines = file($this->log_file);
        $entries = array();

        // Process lines in reverse order (newest first)
        for ($i = count($lines) - 1; $i >= 0 && count($entries) < $limit; $i--) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            $entry = $this->parse_log_line($line);
            if ($entry && (empty($level) || $entry['level'] === $level)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Parse a log line into components
     *
     * @param string $line Log line
     * @return array|false Parsed entry or false if invalid
     */
    private function parse_log_line($line) {
        // Pattern: [timestamp] [level] [user] message
        $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] (.+)$/';
        
        if (preg_match($pattern, $line, $matches)) {
            $message = $matches[4];
            $context = array();

            // Extract context if present
            if (strpos($message, ' | Context: ') !== false) {
                $parts = explode(' | Context: ', $message, 2);
                $message = $parts[0];
                $context = json_decode($parts[1], true) ?: array();
            }

            return array(
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'user' => $matches[3],
                'message' => $message,
                'context' => $context
            );
        }

        return false;
    }

    /**
     * Get log statistics
     *
     * @return array Log statistics
     */
    public function get_log_stats() {
        if (!file_exists($this->log_file)) {
            return array(
                'total_entries' => 0,
                'file_size' => 0,
                'last_entry' => null,
                'levels' => array()
            );
        }

        $lines = file($this->log_file);
        $stats = array(
            'total_entries' => 0,
            'file_size' => filesize($this->log_file),
            'last_entry' => null,
            'levels' => array(
                'INFO' => 0,
                'WARNING' => 0,
                'ERROR' => 0,
                'DEBUG' => 0
            )
        );

        foreach ($lines as $line) {
            $entry = $this->parse_log_line(trim($line));
            if ($entry) {
                $stats['total_entries']++;
                $stats['last_entry'] = $entry['timestamp'];
                
                if (isset($stats['levels'][$entry['level']])) {
                    $stats['levels'][$entry['level']]++;
                }
            }
        }

        return $stats;
    }

    /**
     * Export logs to downloadable file
     *
     * @param string $format Export format (txt, csv, json)
     * @return string|false File path or false on failure
     */
    public function export_logs($format = 'txt') {
        if (!file_exists($this->log_file)) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/bp-export-import-downloads/';
        
        if (!is_dir($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $timestamp = date('Y-m-d-H-i-s');
        $export_file = $export_dir . "bp-export-import-logs-{$timestamp}.{$format}";

        switch ($format) {
            case 'csv':
                return $this->export_logs_csv($export_file);
            case 'json':
                return $this->export_logs_json($export_file);
            default:
                // Just copy the txt file
                if (copy($this->log_file, $export_file)) {
                    return $export_file;
                }
                return false;
        }
    }

    /**
     * Export logs to CSV format
     *
     * @param string $export_file Export file path
     * @return string|false File path or false on failure
     */
    private function export_logs_csv($export_file) {
        $handle = fopen($export_file, 'w');
        if (!$handle) {
            return false;
        }

        // Write CSV headers
        fputcsv($handle, array('Timestamp', 'Level', 'User', 'Message', 'Context'));

        // Process log entries
        $lines = file($this->log_file);
        foreach ($lines as $line) {
            $entry = $this->parse_log_line(trim($line));
            if ($entry) {
                fputcsv($handle, array(
                    $entry['timestamp'],
                    $entry['level'],
                    $entry['user'],
                    $entry['message'],
                    json_encode($entry['context'])
                ));
            }
        }

        fclose($handle);
        return $export_file;
    }

    /**
     * Export logs to JSON format
     *
     * @param string $export_file Export file path
     * @return string|false File path or false on failure
     */
    private function export_logs_json($export_file) {
        $entries = array();
        $lines = file($this->log_file);
        
        foreach ($lines as $line) {
            $entry = $this->parse_log_line(trim($line));
            if ($entry) {
                $entries[] = $entry;
            }
        }

        $json_data = json_encode(array(
            'exported_at' => current_time('c'),
            'total_entries' => count($entries),
            'entries' => $entries
        ), JSON_PRETTY_PRINT);

        if (file_put_contents($export_file, $json_data)) {
            return $export_file;
        }

        return false;
    }
}