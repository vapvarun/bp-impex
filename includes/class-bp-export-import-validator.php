<?php
/**
 * BP Export Import Validator Class
 *
 * Handles validation for files, data, and user inputs
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Validator {

    /**
     * Maximum file size in bytes (50MB)
     */
    const MAX_FILE_SIZE = 52428800;

    /**
     * Allowed file types
     */
    private $allowed_file_types = array('csv', 'json', 'xml');

    /**
     * Required CSV headers
     */
    private $required_csv_headers = array('username', 'email');

    /**
     * Constructor
     */
    public function __construct() {
        $this->allowed_file_types = get_option('bp_export_import_allowed_file_types', $this->allowed_file_types);
    }

    /**
     * Validate uploaded file
     *
     * @param array $file $_FILES array element
     * @return true|WP_Error
     */
    public function validate_uploaded_file($file) {
        // Check if file was uploaded
        if (!isset($file) || empty($file['tmp_name'])) {
            return new WP_Error('no_file', __('No file was uploaded.', 'bp-export-import'));
        }

        // Check upload errors
        $upload_error = $this->check_upload_errors($file['error']);
        if (is_wp_error($upload_error)) {
            return $upload_error;
        }

        // Check file size
        $size_check = $this->validate_file_size($file['size']);
        if (is_wp_error($size_check)) {
            return $size_check;
        }

        // Check file type
        $type_check = $this->validate_file_type($file['name'], $file['tmp_name']);
        if (is_wp_error($type_check)) {
            return $type_check;
        }

        // Check file content
        $content_check = $this->validate_file_content($file['tmp_name'], $this->get_file_extension($file['name']));
        if (is_wp_error($content_check)) {
            return $content_check;
        }

        return true;
    }

    /**
     * Check upload errors
     *
     * @param int $error Upload error code
     * @return true|WP_Error
     */
    private function check_upload_errors($error) {
        switch ($error) {
            case UPLOAD_ERR_OK:
                return true;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return new WP_Error('file_too_large', __('The uploaded file is too large.', 'bp-export-import'));
            case UPLOAD_ERR_PARTIAL:
                return new WP_Error('partial_upload', __('The file was only partially uploaded.', 'bp-export-import'));
            case UPLOAD_ERR_NO_FILE:
                return new WP_Error('no_file', __('No file was uploaded.', 'bp-export-import'));
            case UPLOAD_ERR_NO_TMP_DIR:
                return new WP_Error('no_tmp_dir', __('Missing temporary folder.', 'bp-export-import'));
            case UPLOAD_ERR_CANT_WRITE:
                return new WP_Error('cant_write', __('Failed to write file to disk.', 'bp-export-import'));
            case UPLOAD_ERR_EXTENSION:
                return new WP_Error('extension_error', __('A PHP extension stopped the file upload.', 'bp-export-import'));
            default:
                return new WP_Error('unknown_error', __('Unknown upload error.', 'bp-export-import'));
        }
    }

    /**
     * Validate file size
     *
     * @param int $size File size in bytes
     * @return true|WP_Error
     */
    private function validate_file_size($size) {
        $max_size = get_option('bp_export_import_max_file_size', self::MAX_FILE_SIZE);
        
        if ($size > $max_size) {
            return new WP_Error('file_too_large', sprintf(
                __('File size (%s) exceeds maximum allowed size (%s).', 'bp-export-import'),
                size_format($size),
                size_format($max_size)
            ));
        }

        return true;
    }

    /**
     * Validate file type
     *
     * @param string $filename Original filename
     * @param string $filepath Temporary file path
     * @return true|WP_Error
     */
    private function validate_file_type($filename, $filepath) {
        // Check file extension
        $extension = $this->get_file_extension($filename);
        if (!in_array($extension, $this->allowed_file_types)) {
            return new WP_Error('invalid_file_type', sprintf(
                __('Invalid file type. Allowed types: %s', 'bp-export-import'),
                implode(', ', $this->allowed_file_types)
            ));
        }

        // Check MIME type
        $mime_type = $this->get_mime_type($filepath);
        $allowed_mimes = $this->get_allowed_mime_types();
        
        if (!in_array($mime_type, $allowed_mimes)) {
            return new WP_Error('invalid_mime_type', sprintf(
                __('Invalid file MIME type: %s', 'bp-export-import'),
                $mime_type
            ));
        }

        // Additional security checks
        if ($this->is_potentially_dangerous_file($filepath, $extension)) {
            return new WP_Error('dangerous_file', __('File appears to contain dangerous content.', 'bp-export-import'));
        }

        return true;
    }

    /**
     * Validate file content
     *
     * @param string $filepath File path
     * @param string $format File format (csv, json, xml)
     * @return true|WP_Error
     */
    public function validate_file_content($filepath, $format) {
        switch ($format) {
            case 'csv':
                return $this->validate_csv_content($filepath);
            case 'json':
                return $this->validate_json_content($filepath);
            case 'xml':
                return $this->validate_xml_content($filepath);
            default:
                return new WP_Error('unsupported_format', __('Unsupported file format.', 'bp-export-import'));
        }
    }

    /**
     * Validate CSV content
     *
     * @param string $filepath CSV file path
     * @return true|WP_Error
     */
    private function validate_csv_content($filepath) {
        if (!is_readable($filepath)) {
            return new WP_Error('cant_read_file', __('Cannot read the uploaded file.', 'bp-export-import'));
        }

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return new WP_Error('cant_open_file', __('Cannot open the uploaded file.', 'bp-export-import'));
        }

        // Read header row
        $header = fgetcsv($handle);
        fclose($handle);

        if (empty($header)) {
            return new WP_Error('empty_file', __('The uploaded file appears to be empty or invalid.', 'bp-export-import'));
        }

        // Clean headers
        $header = array_map('trim', $header);
        $header = array_map('strtolower', $header);

        // Check for required fields
        foreach ($this->required_csv_headers as $required_field) {
            if (!in_array(strtolower($required_field), $header)) {
                return new WP_Error('missing_required_field', sprintf(
                    __('Required field "%s" not found in CSV headers. Found: %s', 'bp-export-import'),
                    $required_field,
                    implode(', ', $header)
                ));
            }
        }

        // Validate data types in first few rows
        $validation_result = $this->validate_csv_data_types($filepath, $header);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        return true;
    }

    /**
     * Validate CSV data types
     *
     * @param string $filepath CSV file path
     * @param array $headers CSV headers
     * @return true|WP_Error
     */
    private function validate_csv_data_types($filepath, $headers) {
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return new WP_Error('cant_open_file', __('Cannot open file for validation.', 'bp-export-import'));
        }

        // Skip header row
        fgetcsv($handle);

        $row_count = 0;
        $max_validation_rows = 10;

        while (($data = fgetcsv($handle)) !== false && $row_count < $max_validation_rows) {
            $row_count++;

            if (count($data) !== count($headers)) {
                fclose($handle);
                return new WP_Error('column_mismatch', sprintf(
                    __('Row %d: Column count mismatch. Expected %d columns, found %d.', 'bp-export-import'),
                    $row_count + 1,
                    count($headers),
                    count($data)
                ));
            }

            $row_data = array_combine($headers, $data);

            // Validate email format
            if (isset($row_data['email']) && !empty($row_data['email'])) {
                if (!is_email($row_data['email'])) {
                    fclose($handle);
                    return new WP_Error('invalid_email', sprintf(
                        __('Row %d: Invalid email format: %s', 'bp-export-import'),
                        $row_count + 1,
                        $row_data['email']
                    ));
                }
            }

            // Validate username format
            if (isset($row_data['username']) && !empty($row_data['username'])) {
                if (!$this->is_valid_username($row_data['username'])) {
                    fclose($handle);
                    return new WP_Error('invalid_username', sprintf(
                        __('Row %d: Invalid username format: %s', 'bp-export-import'),
                        $row_count + 1,
                        $row_data['username']
                    ));
                }
            }
        }

        fclose($handle);
        return true;
    }

    /**
     * Validate JSON content
     *
     * @param string $filepath JSON file path
     * @return true|WP_Error
     */
    private function validate_json_content($filepath) {
        $content = file_get_contents($filepath);
        if ($content === false) {
            return new WP_Error('cant_read_file', __('Cannot read the uploaded file.', 'bp-export-import'));
        }

        // Check if file is too large to parse
        if (strlen($content) > 10 * 1024 * 1024) { // 10MB limit for JSON parsing
            return new WP_Error('json_too_large', __('JSON file is too large to validate.', 'bp-export-import'));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', sprintf(
                __('Invalid JSON format: %s', 'bp-export-import'),
                json_last_error_msg()
            ));
        }

        if (empty($data)) {
            return new WP_Error('empty_file', __('The uploaded file appears to be empty.', 'bp-export-import'));
        }

        // Validate JSON structure
        if (!$this->validate_json_structure($data)) {
            return new WP_Error('invalid_json_structure', __('Invalid JSON structure for user data.', 'bp-export-import'));
        }

        return true;
    }

    /**
     * Validate JSON structure
     *
     * @param mixed $data Decoded JSON data
     * @return bool
     */
    private function validate_json_structure($data) {
        // Check if it's an array of users
        if (!is_array($data)) {
            return false;
        }

        // Check if it has a users key (exported format)
        if (isset($data['users']) && is_array($data['users'])) {
            $data = $data['users'];
        }

        // Validate first few user objects
        $count = 0;
        foreach ($data as $user) {
            if ($count >= 5) break; // Only validate first 5 users

            if (!is_array($user)) {
                return false;
            }

            // Check for required fields
            if (!isset($user['username']) || !isset($user['email'])) {
                return false;
            }

            $count++;
        }

        return true;
    }

    /**
     * Validate XML content
     *
     * @param string $filepath XML file path
     * @return true|WP_Error
     */
    private function validate_xml_content($filepath) {
        // Disable libxml errors to handle them manually
        $use_errors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_file($filepath);
        $errors = libxml_get_errors();

        // Restore error handling
        libxml_use_internal_errors($use_errors);

        if ($xml === false || !empty($errors)) {
            $error_messages = array();
            foreach ($errors as $error) {
                $error_messages[] = trim($error->message);
            }
            
            return new WP_Error('invalid_xml', sprintf(
                __('Invalid XML format: %s', 'bp-export-import'),
                implode(', ', $error_messages)
            ));
        }

        // Check for user elements
        if (!isset($xml->user) || count($xml->user) === 0) {
            return new WP_Error('no_users_found', __('No user data found in XML file.', 'bp-export-import'));
        }

        return true;
    }

    /**
     * Validate user data array
     *
     * @param array $user_data User data
     * @return true|WP_Error
     */
    public function validate_user_data($user_data) {
        // Check required fields
        if (empty($user_data['username'])) {
            return new WP_Error('missing_username', __('Username is required.', 'bp-export-import'));
        }

        if (empty($user_data['email'])) {
            return new WP_Error('missing_email', __('Email is required.', 'bp-export-import'));
        }

        // Validate email format
        if (!is_email($user_data['email'])) {
            return new WP_Error('invalid_email', __('Invalid email format.', 'bp-export-import'));
        }

        // Validate username
        if (!$this->is_valid_username($user_data['username'])) {
            return new WP_Error('invalid_username', __('Invalid username format.', 'bp-export-import'));
        }

        // Check username length
        if (strlen($user_data['username']) > 60) {
            return new WP_Error('username_too_long', __('Username is too long (maximum 60 characters).', 'bp-export-import'));
        }

        // Validate display name if provided
        if (isset($user_data['display_name']) && strlen($user_data['display_name']) > 250) {
            return new WP_Error('display_name_too_long', __('Display name is too long (maximum 250 characters).', 'bp-export-import'));
        }

        return true;
    }

    /**
     * Check if username is valid
     *
     * @param string $username Username to validate
     * @return bool
     */
    private function is_valid_username($username) {
        return validate_username($username);
    }

    /**
     * Get file extension
     *
     * @param string $filename Filename
     * @return string
     */
    private function get_file_extension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Get file MIME type
     *
     * @param string $filepath File path
     * @return string
     */
    private function get_mime_type($filepath) {
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $filepath);
            finfo_close($finfo);
            return $mime_type;
        } elseif (function_exists('mime_content_type')) {
            return mime_content_type($filepath);
        }
        
        return 'application/octet-stream';
    }

    /**
     * Get allowed MIME types
     *
     * @return array
     */
    private function get_allowed_mime_types() {
        return array(
            'text/csv',
            'text/plain',
            'application/csv',
            'application/json',
            'text/json',
            'application/xml',
            'text/xml',
            'application/vnd.ms-excel'
        );
    }

    /**
     * Check if file is potentially dangerous
     *
     * @param string $filepath File path
     * @param string $extension File extension
     * @return bool
     */
    private function is_potentially_dangerous_file($filepath, $extension) {
        // Read first few bytes to check for executable signatures
        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            return true; // If we can't read it, consider it dangerous
        }

        $first_bytes = fread($handle, 10);
        fclose($handle);

        // Check for executable signatures
        $dangerous_signatures = array(
            "\x4D\x5A", // PE executable
            "\x7F\x45\x4C\x46", // ELF executable
            "\xFE\xED\xFA", // Mach-O executable
            "<?php", // PHP code
            "<script", // JavaScript
        );

        foreach ($dangerous_signatures as $signature) {
            if (strpos($first_bytes, $signature) === 0) {
                return true;
            }
        }

        // Check file content for suspicious patterns
        if ($extension === 'csv') {
            return $this->check_csv_for_dangerous_content($filepath);
        }

        return false;
    }

    /**
     * Check CSV for dangerous content
     *
     * @param string $filepath CSV file path
     * @return bool
     */
    private function check_csv_for_dangerous_content($filepath) {
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return true;
        }

        $line_count = 0;
        $max_check_lines = 100;

        while (($line = fgets($handle)) !== false && $line_count < $max_check_lines) {
            // Check for formula injection attempts
            if (preg_match('/^[=@+\-]/', trim($line))) {
                fclose($handle);
                return true;
            }

            // Check for script tags or PHP code
            if (preg_match('/<script|<\?php|javascript:/i', $line)) {
                fclose($handle);
                return true;
            }

            $line_count++;
        }

        fclose($handle);
        return false;
    }

    /**
     * Validate export options
     *
     * @param array $options Export options
     * @return true|WP_Error
     */
    public function validate_export_options($options) {
        // Validate format
        if (isset($options['format']) && !in_array($options['format'], array('csv', 'json', 'xml'))) {
            return new WP_Error('invalid_format', __('Invalid export format.', 'bp-export-import'));
        }

        // Validate user roles
        if (isset($options['roles']) && !empty($options['roles'])) {
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

        // Validate XProfile fields
        if (isset($options['xprofile_fields']) && !empty($options['xprofile_fields'])) {
            $available_fields = $this->get_available_xprofile_fields();
            foreach ($options['xprofile_fields'] as $field) {
                if (!in_array($field, $available_fields)) {
                    return new WP_Error('invalid_xprofile_field', sprintf(
                        __('Invalid XProfile field: %s', 'bp-export-import'),
                        $field
                    ));
                }
            }
        }

        return true;
    }

    /**
     * Get available XProfile fields
     *
     * @return array
     */
    private function get_available_xprofile_fields() {
        $fields = array();

        if (function_exists('bp_is_active') && bp_is_active('xprofile')) {
            if (function_exists('bp_xprofile_get_groups')) {
                $profile_groups = bp_xprofile_get_groups(array('fetch_fields' => true));
                
                if (is_array($profile_groups)) {
                    foreach ($profile_groups as $group) {
                        if (isset($group->fields) && is_array($group->fields)) {
                            foreach ($group->fields as $field) {
                                $fields[] = $field->name;
                            }
                        }
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Sanitize user data
     *
     * @param array $user_data Raw user data
     * @return array Sanitized user data
     */
    public function sanitize_user_data($user_data) {
        $sanitized = array();

        // Sanitize username
        if (isset($user_data['username'])) {
            $sanitized['username'] = sanitize_user($user_data['username']);
        }

        // Sanitize email
        if (isset($user_data['email'])) {
            $sanitized['email'] = sanitize_email($user_data['email']);
        }

        // Sanitize display name
        if (isset($user_data['display_name'])) {
            $sanitized['display_name'] = sanitize_text_field($user_data['display_name']);
        }

        // Sanitize first and last name
        if (isset($user_data['first_name'])) {
            $sanitized['first_name'] = sanitize_text_field($user_data['first_name']);
        }

        if (isset($user_data['last_name'])) {
            $sanitized['last_name'] = sanitize_text_field($user_data['last_name']);
        }

        // Sanitize description
        if (isset($user_data['description'])) {
            $sanitized['description'] = sanitize_textarea_field($user_data['description']);
        }

        // Sanitize URL
        if (isset($user_data['user_url'])) {
            $sanitized['user_url'] = esc_url_raw($user_data['user_url']);
        }

        // Sanitize XProfile fields
        foreach ($user_data as $key => $value) {
            if (strpos($key, 'xprofile_') === 0) {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        // Sanitize meta fields
        foreach ($user_data as $key => $value) {
            if (strpos($key, 'meta_') === 0) {
                if (is_array($value) || is_object($value)) {
                    $sanitized[$key] = $value; // Will be handled by maybe_serialize
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
        }

        return $sanitized;
    }
}