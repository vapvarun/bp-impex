<?php
/**
 * BP Export Import Background Process Class - LAZY LOADING VERSION
 *
 * Handles background processing for large import/export operations
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Background_Process {

    /**
     * Background process action name
     */
    const ACTION_NAME = 'bp_export_import_background_process';

    /**
     * Queue option name
     */
    const QUEUE_OPTION = 'bp_export_import_queue';

    /**
     * Process lock option name
     */
    const LOCK_OPTION = 'bp_export_import_process_lock';

    /**
     * Maximum execution time per batch (seconds)
     */
    const MAX_EXECUTION_TIME = 20;

    /**
     * Progress tracker instance
     */
    private $progress;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor - MINIMAL initialization only
     */
    public function __construct() {
        // DON'T auto-load components or register hooks
        // Only load when explicitly needed
    }

    /**
     * Setup WordPress hooks - call this only when needed
     */
    public function setup_hooks() {
        add_action('wp_ajax_' . self::ACTION_NAME, array($this, 'handle_ajax_request'));
        add_action('wp_ajax_nopriv_' . self::ACTION_NAME, array($this, 'handle_ajax_request'));
        add_action('wp_scheduled_delete', array($this, 'cleanup_old_processes'));
        
        // Cron hook for processing queue
        add_action('bp_export_import_process_queue', array($this, 'process_queue'));
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('bp_export_import_process_queue')) {
            wp_schedule_event(time(), 'every_minute', 'bp_export_import_process_queue');
        }
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

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'bp-export-import')
        );
        return $schedules;
    }

    /**
     * Queue an import operation for background processing
     *
     * @param string $operation_id Unique operation ID
     * @param string $file_path Path to import file
     * @param array $settings Import settings
     * @return bool Success status
     */
    public function queue_import($operation_id, $file_path, $settings = array()) {
        $job_data = array(
            'type' => 'import',
            'operation_id' => $operation_id,
            'file_path' => $file_path,
            'settings' => $settings,
            'user_id' => get_current_user_id(),
            'created_at' => current_time('timestamp'),
            'status' => 'queued'
        );

        return $this->add_to_queue($job_data);
    }

    /**
     * Queue an export operation for background processing
     *
     * @param string $operation_id Unique operation ID
     * @param array $settings Export settings
     * @return bool Success status
     */
    public function queue_export($operation_id, $settings = array()) {
        $job_data = array(
            'type' => 'export',
            'operation_id' => $operation_id,
            'settings' => $settings,
            'user_id' => get_current_user_id(),
            'created_at' => current_time('timestamp'),
            'status' => 'queued'
        );

        return $this->add_to_queue($job_data);
    }

    /**
     * Add job to processing queue
     *
     * @param array $job_data Job data
     * @return bool Success status
     */
    private function add_to_queue($job_data) {
        $queue = get_option(self::QUEUE_OPTION, array());
        $queue[] = $job_data;
        
        return update_option(self::QUEUE_OPTION, $queue);
    }

    /**
     * Process the job queue
     */
    public function process_queue() {
        // Check if another process is already running
        if ($this->is_process_locked()) {
            return;
        }

        // Lock the process
        $this->lock_process();

        try {
            $queue = get_option(self::QUEUE_OPTION, array());
            
            if (empty($queue)) {
                $this->unlock_process();
                return;
            }

            // Process first job in queue
            $job = array_shift($queue);
            
            // Update queue
            update_option(self::QUEUE_OPTION, $queue);
            
            // Process the job
            $this->process_job($job);
            
        } catch (Exception $e) {
            $logger = $this->get_logger();
            if ($logger) {
                $logger->log_error('Background process error: ' . $e->getMessage());
            }
        } finally {
            $this->unlock_process();
        }
    }

    /**
     * Process a single job
     *
     * @param array $job Job data
     */
    private function process_job($job) {
        $operation_id = $job['operation_id'];
        
        try {
            switch ($job['type']) {
                case 'import':
                    $this->process_import_job($job);
                    break;
                case 'export':
                    $this->process_export_job($job);
                    break;
                default:
                    throw new Exception('Unknown job type: ' . $job['type']);
            }
        } catch (Exception $e) {
            $progress = $this->get_progress();
            if ($progress) {
                $progress->complete_operation($operation_id, 'failed', array($e->getMessage()));
            }
            
            $logger = $this->get_logger();
            if ($logger) {
                $logger->log_error("Job {$operation_id} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Process import job
     *
     * @param array $job Job data
     */
    private function process_import_job($job) {
        $operation_id = $job['operation_id'];
        $file_path = $job['file_path'];
        $settings = $job['settings'];
        
        // Validate file still exists
        if (!file_exists($file_path)) {
            throw new Exception('Import file not found: ' . $file_path);
        }

        // Initialize import process
        $importer = bp_export_import()->get_component('import');
        
        // Set up progress tracking
        $format = $settings['format'] ?? $this->detect_file_format($file_path);
        $total_records = $this->count_file_records($file_path, $format);
        
        $progress = $this->get_progress();
        if ($progress) {
            $progress->start_operation($operation_id, 'import', $total_records, $settings);
        }
        
        // Process import in batches
        $batch_size = $settings['batch_size'] ?? 100;
        $start_time = time();
        
        switch ($format) {
            case 'csv':
                $this->process_csv_import($file_path, $operation_id, $batch_size, $start_time);
                break;
            case 'json':
                $this->process_json_import($file_path, $operation_id, $batch_size, $start_time);
                break;
            case 'xml':
                $this->process_xml_import($file_path, $operation_id, $batch_size, $start_time);
                break;
            default:
                throw new Exception('Unsupported file format: ' . $format);
        }

        // Clean up temporary file
        if (strpos($file_path, wp_upload_dir()['basedir'] . '/bp-export-import-temp/') === 0) {
            unlink($file_path);
        }

        if ($progress) {
            $progress->complete_operation($operation_id, 'completed');
        }
    }

    /**
     * Process export job
     *
     * @param array $job Job data
     */
    private function process_export_job($job) {
        $operation_id = $job['operation_id'];
        $settings = $job['settings'];
        
        // Initialize export process
        $exporter = bp_export_import()->get_component('export');
        
        // Count total users
        $total_users = $this->count_export_users($settings);
        
        $progress = $this->get_progress();
        if ($progress) {
            $progress->start_operation($operation_id, 'export', $total_users, $settings);
        }
        
        // Process export in batches
        $batch_size = $settings['batch_size'] ?? 500;
        $format = $settings['format'] ?? 'csv';
        
        // Create temporary export file
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/bp-export-import-temp/';
        
        if (!is_dir($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $export_file = $temp_dir . 'export_' . $operation_id . '.' . $format;
        
        $this->process_export_batches($export_file, $operation_id, $settings, $batch_size);
        
        // Move file to downloads directory
        $downloads_dir = $upload_dir['basedir'] . '/bp-export-import-downloads/';
        if (!is_dir($downloads_dir)) {
            wp_mkdir_p($downloads_dir);
        }
        
        $final_file = $downloads_dir . 'export_' . date('Y-m-d_H-i-s') . '.' . $format;
        rename($export_file, $final_file);
        
        // Update operation with download link
        $download_url = $upload_dir['baseurl'] . '/bp-export-import-downloads/' . basename($final_file);
        
        if ($progress) {
            $progress->log_event($operation_id, 'Export file available: ' . $download_url);
            $progress->complete_operation($operation_id, 'completed');
        }
        
        // Send email notification if enabled
        if ($settings['email_notification'] ?? false) {
            $this->send_completion_email($job['user_id'], 'export', $download_url);
        }
    }

    /**
     * Process CSV import in batches
     */
    private function process_csv_import($file_path, $operation_id, $batch_size, $start_time) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file for reading');
        }

        $headers = fgetcsv($handle);
        $processed = 0;
        $errors = array();
        $batch = array();

        while (($data = fgetcsv($handle)) !== false) {
            // Check execution time limit
            if (time() - $start_time > self::MAX_EXECUTION_TIME) {
                // Re-queue remaining data
                $this->requeue_import_continuation($operation_id, $file_path, $processed);
                break;
            }

            if (count($data) === count($headers)) {
                $user_data = array_combine($headers, $data);
                $batch[] = $user_data;

                if (count($batch) >= $batch_size) {
                    $batch_result = $this->process_import_batch($batch);
                    $processed += $batch_result['processed'];
                    $errors = array_merge($errors, $batch_result['errors']);
                    
                    $progress = $this->get_progress();
                    if ($progress) {
                        $progress->update_progress($operation_id, $processed, $batch_result['errors']);
                    }
                    $batch = array();
                }
            } else {
                $errors[] = "Row " . ($processed + 1) . ": Column count mismatch";
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $batch_result = $this->process_import_batch($batch);
            $processed += $batch_result['processed'];
            $errors = array_merge($errors, $batch_result['errors']);
            
            $progress = $this->get_progress();
            if ($progress) {
                $progress->update_progress($operation_id, $processed, $batch_result['errors']);
            }
        }

        fclose($handle);
    }

    /**
     * Process JSON import in batches
     */
    private function process_json_import($file_path, $operation_id, $batch_size, $start_time) {
        $json_data = file_get_contents($file_path);
        $data = json_decode($json_data, true);

        if (isset($data['users'])) {
            $data = $data['users'];
        }

        $processed = 0;
        $errors = array();
        $batch = array();

        foreach ($data as $user_data) {
            // Check execution time limit
            if (time() - $start_time > self::MAX_EXECUTION_TIME) {
                // Re-queue remaining data
                $remaining_data = array_slice($data, $processed);
                $this->requeue_json_continuation($operation_id, $remaining_data);
                break;
            }

            $batch[] = $user_data;

            if (count($batch) >= $batch_size) {
                $batch_result = $this->process_import_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                
                $progress = $this->get_progress();
                if ($progress) {
                    $progress->update_progress($operation_id, $processed, $batch_result['errors']);
                }
                $batch = array();
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $batch_result = $this->process_import_batch($batch);
            $processed += $batch_result['processed'];
            $errors = array_merge($errors, $batch_result['errors']);
            
            $progress = $this->get_progress();
            if ($progress) {
                $progress->update_progress($operation_id, $processed, $batch_result['errors']);
            }
        }
    }

    /**
     * Process import batch
     */
    private function process_import_batch($batch) {
        $importer = bp_export_import()->get_component('import');
        return $importer->process_user_batch($batch);
    }

    /**
     * Process export in batches
     */
    private function process_export_batches($export_file, $operation_id, $settings, $batch_size) {
        $exporter = bp_export_import()->get_component('export');
        $format = $settings['format'] ?? 'csv';
        
        $page = 1;
        $processed = 0;
        $output = null;

        // Initialize output file
        if ($format === 'csv') {
            $output = fopen($export_file, 'w');
            // Add BOM for proper UTF-8 encoding
            fwrite($output, "\xEF\xBB\xBF");
        } elseif ($format === 'json') {
            file_put_contents($export_file, '{"users":[');
        } elseif ($format === 'xml') {
            file_put_contents($export_file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n<users>\n");
        }

        $headers_written = false;

        do {
            $users = $this->get_users_batch($page, $batch_size, $settings);
            
            if (empty($users)) {
                break;
            }

            foreach ($users as $user) {
                $user_data = $exporter->prepare_user_data($user, $settings);
                
                switch ($format) {
                    case 'csv':
                        if (!$headers_written) {
                            fputcsv($output, array_keys($user_data));
                            $headers_written = true;
                        }
                        fputcsv($output, array_values($user_data));
                        break;
                        
                    case 'json':
                        if ($processed > 0) {
                            file_put_contents($export_file, ',', FILE_APPEND);
                        }
                        file_put_contents($export_file, json_encode($user_data), FILE_APPEND);
                        break;
                        
                    case 'xml':
                        $xml_data = "  <user>\n";
                        foreach ($user_data as $key => $value) {
                            $safe_key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                            $safe_value = htmlspecialchars($value, ENT_XML1, 'UTF-8');
                            $xml_data .= "    <{$safe_key}>{$safe_value}</{$safe_key}>\n";
                        }
                        $xml_data .= "  </user>\n";
                        file_put_contents($export_file, $xml_data, FILE_APPEND);
                        break;
                }
                
                $processed++;
            }

            $progress = $this->get_progress();
            if ($progress) {
                $progress->update_progress($operation_id, $processed);
            }
            $page++;

        } while (count($users) === $batch_size);

        // Finalize output file
        if ($format === 'csv' && $output) {
            fclose($output);
        } elseif ($format === 'json') {
            file_put_contents($export_file, ']}', FILE_APPEND);
        } elseif ($format === 'xml') {
            file_put_contents($export_file, '</users>', FILE_APPEND);
        }
    }

    /**
     * Get users batch for export
     */
    private function get_users_batch($page, $batch_size, $settings) {
        $args = array(
            'fields' => 'all',
            'number' => $batch_size,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC'
        );

        if (!empty($settings['roles'])) {
            $args['role__in'] = $settings['roles'];
        }

        if (!empty($settings['date_from'])) {
            $args['date_query'] = array(
                array(
                    'after' => $settings['date_from'],
                    'inclusive' => true,
                ),
            );
        }

        $user_query = new WP_User_Query($args);
        return $user_query->get_results();
    }

    /**
     * Count users for export
     */
    private function count_export_users($settings) {
        $args = array(
            'count_total' => true,
            'fields' => 'ID'
        );

        if (!empty($settings['roles'])) {
            $args['role__in'] = $settings['roles'];
        }

        if (!empty($settings['date_from'])) {
            $args['date_query'] = array(
                array(
                    'after' => $settings['date_from'],
                    'inclusive' => true,
                ),
            );
        }

        $user_query = new WP_User_Query($args);
        return $user_query->get_total();
    }

    /**
     * Count records in file
     */
    private function count_file_records($file_path, $format) {
        switch ($format) {
            case 'csv':
                $lines = count(file($file_path));
                return max(0, $lines - 1); // Subtract header
            case 'json':
                $data = json_decode(file_get_contents($file_path), true);
                if (isset($data['users'])) {
                    return count($data['users']);
                }
                return is_array($data) ? count($data) : 0;
            case 'xml':
                $xml = simplexml_load_file($file_path);
                return $xml ? count($xml->user) : 0;
            default:
                return 0;
        }
    }

    /**
     * Detect file format from extension
     */
    private function detect_file_format($file_path) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        return in_array($extension, array('csv', 'json', 'xml')) ? $extension : 'csv';
    }

    /**
     * Check if process is locked
     */
    private function is_process_locked() {
        $lock_time = get_option(self::LOCK_OPTION, 0);
        
        // Consider lock expired after 5 minutes
        if ($lock_time && (time() - $lock_time) < 300) {
            return true;
        }
        
        return false;
    }

    /**
     * Lock the process
     */
    private function lock_process() {
        update_option(self::LOCK_OPTION, time());
    }

    /**
     * Unlock the process
     */
    private function unlock_process() {
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Send completion email notification
     */
    private function send_completion_email($user_id, $operation_type, $download_url = '') {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $subject = sprintf(
            __('BP Export/Import: %s completed', 'bp-export-import'),
            ucfirst($operation_type)
        );

        $message = sprintf(
            __('Your %s operation has been completed successfully.', 'bp-export-import'),
            $operation_type
        );

        if ($download_url) {
            $message .= "\n\n" . sprintf(
                __('Download your file: %s', 'bp-export-import'),
                $download_url
            );
        }

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Handle AJAX request for background processing
     */
    public function handle_ajax_request() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        
        switch ($action) {
            case 'start_background_import':
                $this->start_background_import();
                break;
            case 'start_background_export':
                $this->start_background_export();
                break;
            default:
                wp_send_json_error(__('Invalid action.', 'bp-export-import'));
        }
    }

    /**
     * Start background import via AJAX
     */
    private function start_background_import() {
        if (!current_user_can('import')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        $progress = $this->get_progress();
        $operation_id = $progress->generate_operation_id('import');
        $file_data = isset($_POST['file_data']) ? $_POST['file_data'] : array();
        $settings = isset($_POST['settings']) ? $_POST['settings'] : array();

        // Queue the import
        if ($this->queue_import($operation_id, $file_data['path'], $settings)) {
            wp_send_json_success(array(
                'operation_id' => $operation_id,
                'message' => __('Import queued for background processing.', 'bp-export-import')
            ));
        } else {
            wp_send_json_error(__('Failed to queue import.', 'bp-export-import'));
        }
    }

    /**
     * Start background export via AJAX
     */
    private function start_background_export() {
        if (!current_user_can('export')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        $progress = $this->get_progress();
        $operation_id = $progress->generate_operation_id('export');
        $settings = isset($_POST['settings']) ? $_POST['settings'] : array();

        // Queue the export
        if ($this->queue_export($operation_id, $settings)) {
            wp_send_json_success(array(
                'operation_id' => $operation_id,
                'message' => __('Export queued for background processing.', 'bp-export-import')
            ));
        } else {
            wp_send_json_error(__('Failed to queue export.', 'bp-export-import'));
        }
    }

    /**
     * Clean up old background processes
     */
    public function cleanup_old_processes() {
        // Clean up old queue items (older than 7 days)
        $queue = get_option(self::QUEUE_OPTION, array());
        $cutoff_time = time() - (7 * DAY_IN_SECONDS);
        
        $queue = array_filter($queue, function($job) use ($cutoff_time) {
            return $job['created_at'] > $cutoff_time;
        });
        
        update_option(self::QUEUE_OPTION, array_values($queue));
        
        // Remove expired locks
        $lock_time = get_option(self::LOCK_OPTION, 0);
        if ($lock_time && (time() - $lock_time) > 300) {
            delete_option(self::LOCK_OPTION);
        }
    }
}