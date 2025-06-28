<?php
/**
 * BP Export Import Import Class
 *
 * Handles the import functionality for the BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Import {

    /**
     * Import batch size
     */
    private $batch_size = 100;

    /**
     * Progress tracker
     */
    private $progress;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Validator instance
     */
    private $validator;

    /**
     * Allowed file types
     */
    private $allowed_file_types = array('csv', 'json', 'xml');

    /**
     * Maximum file size (50MB)
     */
    private $max_file_size = 52428800;

    /**
     * Constructor
     */
    public function __construct() {
        $this->batch_size = get_option('bp_export_import_import_batch_size', 100);
        $this->max_file_size = get_option('bp_export_import_max_file_size', 52428800);
        $this->allowed_file_types = get_option('bp_export_import_allowed_file_types', $this->allowed_file_types);
        
        $this->progress = bp_export_import()->get_component('progress');
        $this->logger = bp_export_import()->get_component('logger');
        $this->validator = bp_export_import()->get_component('validator');
        
        $this->setup_hooks();
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        add_action('admin_init', array($this, 'handle_import_request'));
        add_action('wp_ajax_bp_import_users', array($this, 'ajax_import_users'));
        add_action('wp_ajax_bp_file_preview', array($this, 'ajax_file_preview'));
    }

    /**
     * Handle import request from admin form
     */
    public function handle_import_request() {
        if (isset($_POST['bp_export_import_import']) && 
            check_admin_referer('bp_export_import_import_nonce', '_wpnonce_bp_export_import_import')) {
            
            // Check user permissions
            if (!current_user_can('import') && !current_user_can('manage_options')) {
                wp_die(__('You do not have permission to import data.', 'bp-export-import'));
            }

            // Validate file upload
            $validation_result = $this->validate_uploaded_file();
            if (is_wp_error($validation_result)) {
                wp_die($validation_result->get_error_message());
            }

            $this->process_import();
        }
    }

    /**
     * AJAX handler for import requests
     */
    public function ajax_import_users() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        if (!current_user_can('import') && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        // Handle file upload
        if (!isset($_FILES['import_file']) || empty($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(__('No file uploaded.', 'bp-export-import'));
        }

        // Validate uploaded file
        $validation_result = $this->validate_uploaded_file();
        if (is_wp_error($validation_result)) {
            wp_send_json_error($validation_result->get_error_message());
        }

        // Start import process
        $operation_id = $this->progress->generate_operation_id('import');
        $file_path = $_FILES['import_file']['tmp_name'];
        $format = isset($_POST['import_format']) ? sanitize_text_field($_POST['import_format']) : 'csv';
        
        // Count total records
        $total_records = $this->count_file_records($file_path, $format);
        
        $this->progress->start_operation($operation_id, 'import', $total_records, $_POST);
        
        // Process import
        try {
            $result = $this->process_file_import($file_path, $format, $operation_id);
            
            $this->progress->complete_operation($operation_id, 'completed');
            
            wp_send_json_success(array(
                'operation_id' => $operation_id,
                'processed' => $result['processed'],
                'errors' => $result['errors'],
                'message' => sprintf(__('Import completed. %d users processed.', 'bp-export-import'), $result['processed'])
            ));
            
        } catch (Exception $e) {
            $this->progress->complete_operation($operation_id, 'failed', array($e->getMessage()));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX handler for file preview
     */
    public function ajax_file_preview() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        if (!current_user_can('import') && !current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        if (!isset($_FILES['preview_file']) || empty($_FILES['preview_file']['tmp_name'])) {
            wp_send_json_error(__('No file uploaded for preview.', 'bp-export-import'));
        }

        $file_path = $_FILES['preview_file']['tmp_name'];
        $format = $this->detect_file_format($_FILES['preview_file']['name']);
        
        try {
            $preview_data = $this->generate_file_preview($file_path, $format);
            wp_send_json_success($preview_data);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Validate uploaded file
     *
     * @return true|WP_Error
     */
    private function validate_uploaded_file() {
        if (!isset($_FILES['import_file']) || empty($_FILES['import_file']['tmp_name'])) {
            return new WP_Error('no_file', __('No file was uploaded.', 'bp-export-import'));
        }

        $file = $_FILES['import_file'];

        // Use validator if available
        if ($this->validator) {
            return $this->validator->validate_uploaded_file($file);
        }

        // Fallback validation
        return $this->basic_file_validation($file);
    }

    /**
     * Basic file validation (fallback)
     *
     * @param array $file File array from $_FILES
     * @return true|WP_Error
     */
    private function basic_file_validation($file) {
        // Check upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('File upload error.', 'bp-export-import'));
        }

        // Check file size
        if ($file['size'] > $this->max_file_size) {
            return new WP_Error('file_too_large', sprintf(
                __('File size (%s) exceeds maximum allowed size (%s).', 'bp-export-import'),
                size_format($file['size']),
                size_format($this->max_file_size)
            ));
        }

        // Check file type
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowed_file_types)) {
            return new WP_Error('invalid_file_type', sprintf(
                __('Invalid file type. Allowed types: %s', 'bp-export-import'),
                implode(', ', $this->allowed_file_types)
            ));
        }

        return true;
    }

    /**
     * Process import from admin form
     */
    private function process_import() {
        $file = $_FILES['import_file']['tmp_name'];
        $format = isset($_POST['import_format']) ? sanitize_text_field($_POST['import_format']) : 'csv';
        
        $operation_id = 'import_' . time() . '_' . uniqid();
        
        // Count total records for progress tracking
        $total_records = $this->count_file_records($file, $format);
        
        // Start progress tracking
        if ($this->progress) {
            $this->progress->start_operation($operation_id, 'import', $total_records, $_POST);
        }
        
        // Log import start
        if ($this->logger) {
            $this->logger->log_operation_start($operation_id, 'import', $_POST);
        }

        try {
            $result = $this->process_file_import($file, $format, $operation_id);
            
            // Complete progress tracking
            if ($this->progress) {
                $this->progress->complete_operation($operation_id, 'completed');
            }
            
            // Log import completion
            if ($this->logger) {
                $this->logger->log_operation_complete($operation_id, 'import', $result);
            }
            
            // Redirect with success message
            $redirect_url = add_query_arg(array(
                'page' => 'bp-export-import-import',
                'import_success' => 1,
                'processed' => $result['processed'],
                'errors' => count($result['errors'])
            ), admin_url('tools.php'));
            
            wp_redirect($redirect_url);
            exit;
            
        } catch (Exception $e) {
            // Handle import error
            if ($this->progress) {
                $this->progress->complete_operation($operation_id, 'failed', array($e->getMessage()));
            }
            
            if ($this->logger) {
                $this->logger->log_operation_error($operation_id, 'import', $e->getMessage());
            }
            
            wp_die(__('Import failed: ', 'bp-export-import') . $e->getMessage());
        }
    }

    /**
     * Process file import based on format
     *
     * @param string $file_path File path
     * @param string $format File format
     * @param string $operation_id Operation ID for progress tracking
     * @return array Import results
     */
    private function process_file_import($file_path, $format, $operation_id) {
        // Increase memory and time limits
        bp_export_import_increase_memory_limit('512M');
        bp_export_import_increase_time_limit(300);

        switch ($format) {
            case 'json':
                return $this->import_from_json($file_path, $operation_id);
            case 'xml':
                return $this->import_from_xml($file_path, $operation_id);
            case 'csv':
            default:
                return $this->import_from_csv($file_path, $operation_id);
        }
    }

    /**
     * Import users from CSV file
     *
     * @param string $file_path CSV file path
     * @param string $operation_id Operation ID for progress tracking
     * @return array Import results
     */
    private function import_from_csv($file_path, $operation_id) {
        if (($handle = fopen($file_path, 'r')) === false) {
            throw new Exception(__('Could not read the CSV file.', 'bp-export-import'));
        }

        $headers = fgetcsv($handle);
        if (empty($headers)) {
            fclose($handle);
            throw new Exception(__('CSV file appears to be empty or invalid.', 'bp-export-import'));
        }

        // Clean headers
        $headers = array_map('trim', $headers);

        $processed = 0;
        $errors = array();
        $batch = array();

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) !== count($headers)) {
                $errors[] = sprintf(__('Row %d: Column count mismatch', 'bp-export-import'), $processed + 2);
                continue;
            }

            $user_data = array_combine($headers, $data);
            $batch[] = $user_data;

            if (count($batch) >= $this->batch_size) {
                $batch_result = $this->process_user_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                
                // Update progress
                if ($this->progress) {
                    $this->progress->update_progress($operation_id, $processed, $batch_result['errors']);
                }
                
                $batch = array();
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $batch_result = $this->process_user_batch($batch);
            $processed += $batch_result['processed'];
            $errors = array_merge($errors, $batch_result['errors']);
            
            // Final progress update
            if ($this->progress) {
                $this->progress->update_progress($operation_id, $processed, $batch_result['errors']);
            }
        }

        fclose($handle);

        return array(
            'processed' => $processed,
            'errors' => $errors
        );
    }

    /**
     * Import users from JSON file
     *
     * @param string $file_path JSON file path
     * @param string $operation_id Operation ID for progress tracking
     * @return array Import results
     */
    private function import_from_json($file_path, $operation_id) {
        $json_data = file_get_contents($file_path);
        if ($json_data === false) {
            throw new Exception(__('Could not read the JSON file.', 'bp-export-import'));
        }

        $data = json_decode($json_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON format: ', 'bp-export-import') . json_last_error_msg());
        }

        // Handle different JSON structures
        if (isset($data['users'])) {
            $users = $data['users'];
        } elseif (is_array($data)) {
            $users = $data;
        } else {
            throw new Exception(__('Invalid JSON structure for user data.', 'bp-export-import'));
        }

        if (!is_array($users)) {
            throw new Exception(__('No user data found in JSON file.', 'bp-export-import'));
        }

        $processed = 0;
        $errors = array();
        $batch = array();

        foreach ($users as $user_data) {
            $batch[] = $user_data;

            if (count($batch) >= $this->batch_size) {
                $batch_result = $this->process_user_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                
                // Update progress
                if ($this->progress) {
                    $this->progress->update_progress($operation_id, $processed, $batch_result['errors']);
                }
                
                $batch = array();
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $batch_result = $this->process_user_batch($batch);
            $processed += $batch_result['processed'];
            $errors = array_merge($errors, $batch_result['errors']);
            
            // Final progress update
            if ($this->progress) {
                $this->progress->update_progress($operation_id, $processed, $batch_result['errors']);
            }
        }

        return array(
            'processed' => $processed,
            'errors' => $errors
        );
    }

    /**
     * Import users from XML file
     *
     * @param string $file_path XML file path
     * @param string $operation_id Operation ID for progress tracking
     * @return array Import results
     */
    private function import_from_xml($file_path, $operation_id) {
        $xml_data = simplexml_load_file($file_path);
        if ($xml_data === false) {
            throw new Exception(__('Could not parse XML file.', 'bp-export-import'));
        }

        if (!isset($xml_data->user) || count($xml_data->user) === 0) {
            throw new Exception(__('No user data found in XML file.', 'bp-export-import'));
        }

        $processed = 0;
        $errors = array();
        $batch = array();

        foreach ($xml_data->user as $user_xml) {
            $user_data = array();
            
            // Convert XML to array
            foreach ($user_xml as $key => $value) {
                $user_data[$key] = (string) $value;
            }
            
            $batch[] = $user_data;

            if (count($batch) >= $this->batch_size) {
                $batch_result = $this->process_user_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                
                // Update progress
                if ($this->progress) {
                    $this->progress->update_progress($operation_id, $processed, $batch_result['errors']);
                }
                
                $batch = array();
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $batch_result = $this->process_user_batch($batch);
            $processed += $batch_result['processed'];
            $errors = array_merge($errors, $batch_result['errors']);
            
            // Final progress update
            if ($this->progress) {
                $this->progress->update_progress($operation_id, $processed, $batch_result['errors']);
            }
        }

        return array(
            'processed' => $processed,
            'errors' => $errors
        );
    }

    /**
     * Process a batch of users
     *
     * @param array $batch Array of user data
     * @return array Batch processing results
     */
    public function process_user_batch($batch) {
        $processed = 0;
        $errors = array();

        foreach ($batch as $user_data) {
            try {
                // Validate user data
                if ($this->validator) {
                    $validation_result = $this->validator->validate_user_data($user_data);
                    if (is_wp_error($validation_result)) {
                        $errors[] = sprintf(
                            __('User %s: %s', 'bp-export-import'),
                            $user_data['username'] ?? 'Unknown',
                            $validation_result->get_error_message()
                        );
                        continue;
                    }
                    
                    // Sanitize user data
                    $user_data = $this->validator->sanitize_user_data($user_data);
                } else {
                    // Basic validation and sanitization
                    $user_data = $this->sanitize_user_data($user_data);
                }

                $result = $this->create_or_update_user($user_data);
                
                if (is_wp_error($result)) {
                    $errors[] = sprintf(
                        __('User %s: %s', 'bp-export-import'),
                        $user_data['username'] ?? 'Unknown',
                        $result->get_error_message()
                    );
                } else {
                    $processed++;
                    
                    // Fire action for successful user processing
                    do_action('bp_export_import_user_imported', $result, $user_data);
                }
                
            } catch (Exception $e) {
                $errors[] = sprintf(
                    __('User %s: %s', 'bp-export-import'),
                    $user_data['username'] ?? 'Unknown',
                    $e->getMessage()
                );
            }
        }

        return array(
            'processed' => $processed,
            'errors' => $errors
        );
    }

    /**
     * Basic user data sanitization (fallback)
     *
     * @param array $user_data Raw user data
     * @return array Sanitized user data
     */
    private function sanitize_user_data($user_data) {
        $sanitized = array();

        // Basic field sanitization
        $text_fields = array('username', 'email', 'display_name', 'first_name', 'last_name', 'user_url');
        foreach ($text_fields as $field) {
            if (isset($user_data[$field])) {
                $sanitized[$field] = sanitize_text_field($user_data[$field]);
            }
        }

        // Email specific sanitization
        if (isset($user_data['email'])) {
            $sanitized['email'] = sanitize_email($user_data['email']);
        }

        // Username specific sanitization
        if (isset($user_data['username'])) {
            $sanitized['username'] = sanitize_user($user_data['username']);
        }

        // URL specific sanitization
        if (isset($user_data['user_url'])) {
            $sanitized['user_url'] = esc_url_raw($user_data['user_url']);
        }

        // Description field
        if (isset($user_data['description'])) {
            $sanitized['description'] = sanitize_textarea_field($user_data['description']);
        }

        // Handle XProfile and meta fields
        foreach ($user_data as $key => $value) {
            if (strpos($key, 'xprofile_') === 0 || strpos($key, 'meta_') === 0) {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }

    /**
     * Create or update a user
     *
     * @param array $user_data User data
     * @return int|WP_Error User ID or error
     */
    private function create_or_update_user($user_data) {
        // Validate required fields
        if (empty($user_data['username']) || empty($user_data['email'])) {
            return new WP_Error('missing_required_data', __('Username and email are required.', 'bp-export-import'));
        }

        // Validate email format
        if (!is_email($user_data['email'])) {
            return new WP_Error('invalid_email', __('Invalid email address.', 'bp-export-import'));
        }

        $username = $user_data['username'];
        $email = $user_data['email'];

        $user_id = username_exists($username);
        $email_exists = email_exists($email);

        // Determine import mode
        $import_mode = isset($_POST['import_mode']) ? sanitize_text_field($_POST['import_mode']) : 'create_only';

        if ($user_id || $email_exists) {
            if ($import_mode === 'create_only') {
                return new WP_Error('user_exists', __('User already exists and import mode is set to create only.', 'bp-export-import'));
            }
            
            $user_id = $user_id ?: $email_exists;
            return $this->update_existing_user($user_id, $user_data);
        } else {
            return $this->create_new_user($user_data);
        }
    }

    /**
     * Create a new user
     *
     * @param array $user_data User data
     * @return int|WP_Error User ID or error
     */
    private function create_new_user($user_data) {
        $username = $user_data['username'];
        $email = $user_data['email'];
        
        // Generate password or use provided one
        $password = isset($user_data['password']) ? $user_data['password'] : wp_generate_password();

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Update additional user data
        $this->update_user_meta($user_id, $user_data);
        $this->update_user_profile($user_id, $user_data);

        // Send notification if enabled
        if (isset($_POST['send_notification']) && $_POST['send_notification']) {
            wp_new_user_notification($user_id, null, 'user');
        }

        return $user_id;
    }

    /**
     * Update existing user
     *
     * @param int $user_id User ID
     * @param array $user_data User data
     * @return int|WP_Error User ID or error
     */
    private function update_existing_user($user_id, $user_data) {
        $update_data = array('ID' => $user_id);

        // Update basic user fields
        $user_fields = array('email' => 'user_email', 'display_name' => 'display_name', 'user_url' => 'user_url');
        foreach ($user_fields as $data_key => $user_key) {
            if (!empty($user_data[$data_key])) {
                $update_data[$user_key] = $user_data[$data_key];
            }
        }

        if (count($update_data) > 1) { // More than just ID
            $result = wp_update_user($update_data);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        // Update user meta and profile data
        $this->update_user_meta($user_id, $user_data);
        $this->update_user_profile($user_id, $user_data);

        return $user_id;
    }

    /**
     * Update user meta data
     *
     * @param int $user_id User ID
     * @param array $user_data User data
     */
    private function update_user_meta($user_id, $user_data) {
        // Standard user meta fields
        $meta_fields = array('first_name', 'last_name', 'description');
        foreach ($meta_fields as $field) {
            if (isset($user_data[$field])) {
                update_user_meta($user_id, $field, $user_data[$field]);
            }
        }

        // Custom user meta fields (prefixed with meta_)
        foreach ($user_data as $key => $value) {
            if (strpos($key, 'meta_') === 0) {
                $meta_key = str_replace('meta_', '', $key);
                
                // Handle JSON data
                if (is_string($value) && $this->is_json($value)) {
                    $value = json_decode($value, true);
                }
                
                update_user_meta($user_id, sanitize_key($meta_key), $value);
            }
        }
    }

    /**
     * Update BuddyPress user profile data
     *
     * @param int $user_id User ID
     * @param array $user_data User data
     */
    private function update_user_profile($user_id, $user_data) {
        if (!function_exists('xprofile_set_field_data')) {
            return;
        }

        // Update XProfile fields (prefixed with xprofile_)
        foreach ($user_data as $key => $value) {
            if (strpos($key, 'xprofile_') === 0) {
                $field_name = str_replace('xprofile_', '', $key);
                $field_id = $this->get_xprofile_field_id_by_name($field_name);
                
                if ($field_id) {
                    xprofile_set_field_data($field_id, $user_id, $value);
                }
            }
        }
    }

    /**
     * Get XProfile field ID by name
     *
     * @param string $field_name Field name
     * @return int|false Field ID or false if not found
     */
    private function get_xprofile_field_id_by_name($field_name) {
        global $wpdb;

        if (!function_exists('bp_is_active') || !bp_is_active('xprofile')) {
            return false;
        }

        $field_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->base_prefix}bp_xprofile_fields WHERE name = %s",
            $field_name
        ));

        return $field_id ? intval($field_id) : false;
    }

    /**
     * Check if string is JSON
     *
     * @param string $string String to check
     * @return bool True if JSON, false otherwise
     */
    private function is_json($string) {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Count records in file
     *
     * @param string $file_path File path
     * @param string $format File format
     * @return int Number of records
     */
    private function count_file_records($file_path, $format) {
        switch ($format) {
            case 'csv':
                $lines = count(file($file_path));
                return max(0, $lines - 1); // Subtract header row
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
     * Detect file format from filename
     *
     * @param string $filename Filename
     * @return string Detected format
     */
    private function detect_file_format($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowed_file_types) ? $extension : 'csv';
    }

    /**
     * Generate file preview
     *
     * @param string $file_path File path
     * @param string $format File format
     * @return array Preview data
     */
    private function generate_file_preview($file_path, $format) {
        switch ($format) {
            case 'csv':
                return $this->preview_csv($file_path);
            case 'json':
                return $this->preview_json($file_path);
            case 'xml':
                return $this->preview_xml($file_path);
            default:
                throw new Exception(__('Unsupported file format for preview.', 'bp-export-import'));
        }
    }

    /**
     * Preview CSV file
     *
     * @param string $file_path CSV file path
     * @return array Preview data
     */
    private function preview_csv($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot read CSV file.', 'bp-export-import'));
        }

        $headers = fgetcsv($handle);
        $data = array();
        $row_count = 0;

        while (($row = fgetcsv($handle)) !== false && $row_count < 5) {
            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
                $row_count++;
            }
        }

        fclose($handle);

        $total_lines = count(file($file_path));

        return array(
            'headers' => $headers,
            'data' => $data,
            'total' => max(0, $total_lines - 1) // Subtract header
        );
    }

    /**
     * Preview JSON file
     *
     * @param string $file_path JSON file path
     * @return array Preview data
     */
    private function preview_json($file_path) {
        $content = file_get_contents($file_path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON format.', 'bp-export-import'));
        }

        if (isset($data['users'])) {
            $users = $data['users'];
        } elseif (is_array($data)) {
            $users = $data;
        } else {
            throw new Exception(__('No user data found in JSON.', 'bp-export-import'));
        }

        return array(
            'data' => array_slice($users, 0, 5),
            'total' => count($users)
        );
    }

    /**
     * Preview XML file
     *
     * @param string $file_path XML file path
     * @return array Preview data
     */
    private function preview_xml($file_path) {
        $xml = simplexml_load_file($file_path);
        if (!$xml) {
            throw new Exception(__('Invalid XML format.', 'bp-export-import'));
        }

        if (!isset($xml->user)) {
            throw new Exception(__('No user data found in XML.', 'bp-export-import'));
        }

        $data = array();
        $count = 0;

        foreach ($xml->user as $user) {
            if ($count >= 5) break;
            
            $user_data = array();
            foreach ($user as $key => $value) {
                $user_data[$key] = (string) $value;
            }
            
            $data[] = $user_data;
            $count++;
        }

        return array(
            'data' => $data,
            'total' => count($xml->user)
        );
    }

    /**
     * Schedule background import
     *
     * @param string $file_path File path
     * @param array $settings Import settings
     * @return string|false Operation ID or false on failure
     */
    public function schedule_background_import($file_path, $settings) {
        $background_process = bp_export_import()->get_component('background_process');
        
        if (!$background_process) {
            return false;
        }
        
        $operation_id = $this->progress->generate_operation_id('import');
        
        if ($background_process->queue_import($operation_id, $file_path, $settings)) {
            return $operation_id;
        }
        
        return false;
    }

    /**
     * Get import statistics
     *
     * @return array Import statistics
     */
    public function get_import_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Get recent import operations
        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            $stats['recent_imports'] = $wpdb->get_results(
                "SELECT * FROM {$table_name} 
                 WHERE operation_type = 'import' 
                 ORDER BY started_at DESC 
                 LIMIT 5",
                ARRAY_A
            );
            
            // Get import success rate
            $stats['success_rate'] = $wpdb->get_var(
                "SELECT ROUND(
                    (COUNT(CASE WHEN status = 'completed' THEN 1 END) * 100.0) / COUNT(*), 2
                ) FROM {$table_name} 
                WHERE operation_type = 'import' 
                AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ) ?: 0;
        }
        
        return $stats;
    }

    /**
     * Validate import data integrity
     *
     * @param string $file_path File path
     * @param string $format File format
     * @return array|WP_Error Validation results
     */
    public function validate_import_data($file_path, $format) {
        $validation_results = array(
            'valid' => true,
            'errors' => array(),
            'warnings' => array(),
            'stats' => array()
        );

        try {
            switch ($format) {
                case 'csv':
                    return $this->validate_csv_data($file_path);
                case 'json':
                    return $this->validate_json_data($file_path);
                case 'xml':
                    return $this->validate_xml_data($file_path);
                default:
                    $validation_results['valid'] = false;
                    $validation_results['errors'][] = __('Unsupported file format.', 'bp-export-import');
            }
        } catch (Exception $e) {
            $validation_results['valid'] = false;
            $validation_results['errors'][] = $e->getMessage();
        }

        return $validation_results;
    }

    /**
     * Validate CSV data integrity
     *
     * @param string $file_path CSV file path
     * @return array Validation results
     */
    private function validate_csv_data($file_path) {
        $results = array(
            'valid' => true,
            'errors' => array(),
            'warnings' => array(),
            'stats' => array(
                'total_rows' => 0,
                'valid_emails' => 0,
                'duplicate_usernames' => 0,
                'duplicate_emails' => 0
            )
        );

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $results['valid'] = false;
            $results['errors'][] = __('Cannot read CSV file.', 'bp-export-import');
            return $results;
        }

        $headers = fgetcsv($handle);
        $usernames = array();
        $emails = array();
        $row_number = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row_number++;
            $results['stats']['total_rows']++;

            if (count($data) !== count($headers)) {
                $results['errors'][] = sprintf(__('Row %d: Column count mismatch.', 'bp-export-import'), $row_number);
                continue;
            }

            $user_data = array_combine($headers, $data);

            // Check for required fields
            if (empty($user_data['username']) || empty($user_data['email'])) {
                $results['errors'][] = sprintf(__('Row %d: Missing username or email.', 'bp-export-import'), $row_number);
                continue;
            }

            // Validate email
            if (!is_email($user_data['email'])) {
                $results['errors'][] = sprintf(__('Row %d: Invalid email format.', 'bp-export-import'), $row_number);
            } else {
                $results['stats']['valid_emails']++;
            }

            // Check for duplicates within file
            if (in_array($user_data['username'], $usernames)) {
                $results['stats']['duplicate_usernames']++;
                $results['warnings'][] = sprintf(__('Row %d: Duplicate username "%s".', 'bp-export-import'), $row_number, $user_data['username']);
            } else {
                $usernames[] = $user_data['username'];
            }

            if (in_array($user_data['email'], $emails)) {
                $results['stats']['duplicate_emails']++;
                $results['warnings'][] = sprintf(__('Row %d: Duplicate email "%s".', 'bp-export-import'), $row_number, $user_data['email']);
            } else {
                $emails[] = $user_data['email'];
            }
        }

        fclose($handle);

        if (!empty($results['errors'])) {
            $results['valid'] = false;
        }

        return $results;
    }

    /**
     * Validate JSON data integrity
     *
     * @param string $file_path JSON file path
     * @return array Validation results
     */
    private function validate_json_data($file_path) {
        $results = array(
            'valid' => true,
            'errors' => array(),
            'warnings' => array(),
            'stats' => array('total_users' => 0)
        );

        $content = file_get_contents($file_path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $results['valid'] = false;
            $results['errors'][] = __('Invalid JSON format: ', 'bp-export-import') . json_last_error_msg();
            return $results;
        }

        if (isset($data['users'])) {
            $users = $data['users'];
        } elseif (is_array($data)) {
            $users = $data;
        } else {
            $results['valid'] = false;
            $results['errors'][] = __('No user data found in JSON.', 'bp-export-import');
            return $results;
        }

        $results['stats']['total_users'] = count($users);

        // Validate first few users for structure
        $sample_users = array_slice($users, 0, 10);
        foreach ($sample_users as $index => $user) {
            if (!isset($user['username']) || !isset($user['email'])) {
                $results['errors'][] = sprintf(__('User %d: Missing username or email.', 'bp-export-import'), $index + 1);
            }
        }

        if (!empty($results['errors'])) {
            $results['valid'] = false;
        }

        return $results;
    }

    /**
     * Validate XML data integrity
     *
     * @param string $file_path XML file path
     * @return array Validation results
     */
    private function validate_xml_data($file_path) {
        $results = array(
            'valid' => true,
            'errors' => array(),
            'warnings' => array(),
            'stats' => array('total_users' => 0)
        );

        $xml = simplexml_load_file($file_path);
        if (!$xml) {
            $results['valid'] = false;
            $results['errors'][] = __('Invalid XML format.', 'bp-export-import');
            return $results;
        }

        if (!isset($xml->user)) {
            $results['valid'] = false;
            $results['errors'][] = __('No user data found in XML.', 'bp-export-import');
            return $results;
        }

        $results['stats']['total_users'] = count($xml->user);

        // Validate first few users
        $count = 0;
        foreach ($xml->user as $user) {
            if ($count >= 10) break;
            
            if (!isset($user->username) || !isset($user->email)) {
                $results['errors'][] = sprintf(__('User %d: Missing username or email.', 'bp-export-import'), $count + 1);
            }
            
            $count++;
        }

        if (!empty($results['errors'])) {
            $results['valid'] = false;
        }

        return $results;
    }
}