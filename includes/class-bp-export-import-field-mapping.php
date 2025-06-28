<?php
/**
 * BP Export Import Field Mapping Class
 *
 * Manages field mapping for import/export operations
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Field_Mapping {

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = bp_export_import()->get_component('logger');
        $this->setup_hooks();
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        add_action('admin_init', array($this, 'handle_mapping_save'));
        add_action('wp_ajax_bp_load_field_mapping', array($this, 'ajax_load_field_mapping'));
        add_action('wp_ajax_bp_save_field_mapping', array($this, 'ajax_save_field_mapping'));
        add_action('wp_ajax_bp_delete_field_mapping', array($this, 'ajax_delete_field_mapping'));
    }

    /**
     * Handle field mapping save from admin form
     */
    public function handle_mapping_save() {
        if (isset($_POST['bp_save_field_mapping']) && 
            check_admin_referer('bp_export_import_field_mapping_nonce', '_wpnonce_bp_export_import_field_mapping')) {
            
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to manage field mappings.', 'bp-export-import'));
            }

            $this->save_field_mapping();
        }
    }

    /**
     * AJAX handler for loading field mapping
     */
    public function ajax_load_field_mapping() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        $mapping_id = isset($_POST['mapping_id']) ? sanitize_text_field($_POST['mapping_id']) : '';
        
        if (empty($mapping_id)) {
            wp_send_json_error(__('Invalid mapping ID.', 'bp-export-import'));
        }

        $mapping = $this->get_field_mapping($mapping_id);
        
        if ($mapping) {
            wp_send_json_success($mapping);
        } else {
            wp_send_json_error(__('Mapping not found.', 'bp-export-import'));
        }
    }

    /**
     * AJAX handler for saving field mapping
     */
    public function ajax_save_field_mapping() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        $mapping_name = isset($_POST['mapping_name']) ? sanitize_text_field($_POST['mapping_name']) : '';
        $mapping_data = isset($_POST['mapping_data']) ? $_POST['mapping_data'] : array();
        
        if (empty($mapping_name)) {
            wp_send_json_error(__('Mapping name is required.', 'bp-export-import'));
        }

        $mapping_id = $this->save_field_mapping_data($mapping_name, $mapping_data);
        
        if ($mapping_id) {
            wp_send_json_success(array(
                'mapping_id' => $mapping_id,
                'message' => __('Field mapping saved successfully.', 'bp-export-import')
            ));
        } else {
            wp_send_json_error(__('Failed to save field mapping.', 'bp-export-import'));
        }
    }

    /**
     * AJAX handler for deleting field mapping
     */
    public function ajax_delete_field_mapping() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'bp-export-import'));
        }

        $mapping_id = isset($_POST['mapping_id']) ? sanitize_text_field($_POST['mapping_id']) : '';
        
        if (empty($mapping_id)) {
            wp_send_json_error(__('Invalid mapping ID.', 'bp-export-import'));
        }

        if ($this->delete_field_mapping($mapping_id)) {
            wp_send_json_success(__('Field mapping deleted successfully.', 'bp-export-import'));
        } else {
            wp_send_json_error(__('Failed to delete field mapping.', 'bp-export-import'));
        }
    }

    /**
     * Save field mapping from form
     */
    private function save_field_mapping() {
        $mapping_name = isset($_POST['mapping_name']) ? sanitize_text_field($_POST['mapping_name']) : '';
        $field_mapping = isset($_POST['field_mapping']) ? $_POST['field_mapping'] : array();
        
        if (empty($mapping_name)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('Mapping name is required.', 'bp-export-import') . '</p></div>';
            });
            return;
        }

        $mapping_id = $this->save_field_mapping_data($mapping_name, $field_mapping);
        
        if ($mapping_id) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>' . __('Field mapping saved successfully.', 'bp-export-import') . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('Failed to save field mapping.', 'bp-export-import') . '</p></div>';
            });
        }
    }

    /**
     * Save field mapping data
     *
     * @param string $name Mapping name
     * @param array $mapping_data Mapping configuration
     * @return string|false Mapping ID or false on failure
     */
    private function save_field_mapping_data($name, $mapping_data) {
        // Sanitize mapping data
        $sanitized_mapping = array();
        
        foreach ($mapping_data as $bp_field => $import_field) {
            $bp_field = sanitize_text_field($bp_field);
            $import_field = sanitize_text_field($import_field);
            
            if (!empty($bp_field) && !empty($import_field)) {
                $sanitized_mapping[$bp_field] = $import_field;
            }
        }

        $mapping_id = 'mapping_' . time() . '_' . uniqid();
        
        $mapping_config = array(
            'id' => $mapping_id,
            'name' => $name,
            'mapping' => $sanitized_mapping,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        );

        $saved_mappings = get_option('bp_export_import_field_mappings', array());
        $saved_mappings[$mapping_id] = $mapping_config;
        
        if (update_option('bp_export_import_field_mappings', $saved_mappings)) {
            // Log mapping creation
            if ($this->logger) {
                $this->logger->log_info("Field mapping '{$name}' created", array(
                    'mapping_id' => $mapping_id,
                    'field_count' => count($sanitized_mapping)
                ));
            }
            
            return $mapping_id;
        }
        
        return false;
    }

    /**
     * Get field mapping by ID
     *
     * @param string $mapping_id Mapping ID
     * @return array|false Mapping configuration or false if not found
     */
    public function get_field_mapping($mapping_id) {
        $saved_mappings = get_option('bp_export_import_field_mappings', array());
        return isset($saved_mappings[$mapping_id]) ? $saved_mappings[$mapping_id] : false;
    }

    /**
     * Get all saved field mappings
     *
     * @return array Array of saved mappings
     */
    public function get_all_field_mappings() {
        return get_option('bp_export_import_field_mappings', array());
    }

    /**
     * Delete field mapping
     *
     * @param string $mapping_id Mapping ID
     * @return bool True on success, false on failure
     */
    public function delete_field_mapping($mapping_id) {
        $saved_mappings = get_option('bp_export_import_field_mappings', array());
        
        if (isset($saved_mappings[$mapping_id])) {
            $mapping_name = $saved_mappings[$mapping_id]['name'];
            unset($saved_mappings[$mapping_id]);
            
            $result = update_option('bp_export_import_field_mappings', $saved_mappings);
            
            // Log mapping deletion
            if ($result && $this->logger) {
                $this->logger->log_info("Field mapping '{$mapping_name}' deleted", array(
                    'mapping_id' => $mapping_id
                ));
            }
            
            return $result;
        }
        
        return false;
    }

    /**
     * Apply field mapping to imported data
     *
     * @param array $imported_data Raw imported data
     * @param array $field_mapping Mapping configuration
     * @return array Mapped data
     */
    public function apply_field_mapping($imported_data, $field_mapping) {
        $mapped_data = array();

        // First, copy unmapped standard fields
        $standard_fields = array('username', 'email', 'display_name', 'first_name', 'last_name', 'description', 'user_url');
        foreach ($standard_fields as $field) {
            if (isset($imported_data[$field])) {
                $mapped_data[$field] = $imported_data[$field];
            }
        }

        // Apply field mapping
        foreach ($field_mapping as $bp_field => $import_field) {
            if (isset($imported_data[$import_field])) {
                $mapped_data[$bp_field] = $imported_data[$import_field];
            }
        }

        // Handle unmapped fields (preserve with original names)
        foreach ($imported_data as $field => $value) {
            if (!isset($mapped_data[$field]) && !in_array($field, $field_mapping)) {
                $mapped_data[$field] = $value;
            }
        }

        return $mapped_data;
    }

    /**
     * Get available BuddyPress fields for mapping
     *
     * @return array Array of available fields
     */
    public function get_available_bp_fields() {
        $fields = array();

        // Standard WordPress user fields
        $fields['standard'] = array(
            'username' => __('Username', 'bp-export-import'),
            'email' => __('Email', 'bp-export-import'),
            'display_name' => __('Display Name', 'bp-export-import'),
            'first_name' => __('First Name', 'bp-export-import'),
            'last_name' => __('Last Name', 'bp-export-import'),
            'description' => __('Description', 'bp-export-import'),
            'user_url' => __('Website', 'bp-export-import')
        );

        // BuddyPress XProfile fields
        $fields['xprofile'] = array();
        if (function_exists('bp_is_active') && bp_is_active('xprofile')) {
            $xprofile_groups = $this->get_xprofile_field_groups();
            foreach ($xprofile_groups as $group) {
                foreach ($group['fields'] as $field) {
                    $fields['xprofile']['xprofile_' . sanitize_key($field['name'])] = $field['name'] . ' (' . $group['name'] . ')';
                }
            }
        }

        // Common user meta fields
        $fields['meta'] = array(
            'meta_nickname' => __('Nickname', 'bp-export-import'),
            'meta_admin_color' => __('Admin Color Scheme', 'bp-export-import'),
            'meta_locale' => __('Language', 'bp-export-import'),
            'meta_show_admin_bar_front' => __('Show Admin Bar', 'bp-export-import')
        );

        return apply_filters('bp_export_import_available_fields', $fields);
    }

    /**
     * Get XProfile field groups
     *
     * @return array Array of field groups
     */
    private function get_xprofile_field_groups() {
        $field_groups = array();

        if (!function_exists('bp_xprofile_get_groups')) {
            return $field_groups;
        }

        $groups = bp_xprofile_get_groups(array('fetch_fields' => true));
        
        if (is_array($groups)) {
            foreach ($groups as $group) {
                $group_data = array(
                    'id' => $group->id,
                    'name' => $group->name,
                    'fields' => array()
                );
                
                if (isset($group->fields) && is_array($group->fields)) {
                    foreach ($group->fields as $field) {
                        $group_data['fields'][] = array(
                            'id' => $field->id,
                            'name' => $field->name,
                            'type' => $field->type,
                            'description' => $field->description ?? '',
                            'is_required' => $field->is_required ?? false
                        );
                    }
                }
                
                $field_groups[] = $group_data;
            }
        }

        return $field_groups;
    }

    /**
     * Detect import file fields
     *
     * @param string $file_path File path
     * @param string $format File format
     * @return array Array of detected fields
     */
    public function detect_import_fields($file_path, $format) {
        switch ($format) {
            case 'csv':
                return $this->detect_csv_fields($file_path);
            case 'json':
                return $this->detect_json_fields($file_path);
            case 'xml':
                return $this->detect_xml_fields($file_path);
            default:
                return array();
        }
    }

    /**
     * Detect CSV file fields
     *
     * @param string $file_path CSV file path
     * @return array Array of field names
     */
    private function detect_csv_fields($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return array();
        }

        $headers = fgetcsv($handle);
        fclose($handle);

        return $headers ? array_map('trim', $headers) : array();
    }

    /**
     * Detect JSON file fields
     *
     * @param string $file_path JSON file path
     * @return array Array of field names
     */
    private function detect_json_fields($file_path) {
        $content = file_get_contents($file_path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }

        if (isset($data['users']) && is_array($data['users']) && !empty($data['users'])) {
            return array_keys($data['users'][0]);
        } elseif (is_array($data) && !empty($data)) {
            return array_keys($data[0]);
        }

        return array();
    }

    /**
     * Detect XML file fields
     *
     * @param string $file_path XML file path
     * @return array Array of field names
     */
    private function detect_xml_fields($file_path) {
        $xml = simplexml_load_file($file_path);
        
        if (!$xml || !isset($xml->user) || count($xml->user) === 0) {
            return array();
        }

        $first_user = $xml->user[0];
        $fields = array();

        foreach ($first_user as $key => $value) {
            $fields[] = $key;
        }

        return $fields;
    }

    /**
     * Generate automatic field mapping suggestions
     *
     * @param array $import_fields Array of import field names
     * @return array Suggested mapping
     */
    public function suggest_field_mapping($import_fields) {
        $suggestions = array();
        $bp_fields = $this->get_available_bp_fields();
        
        // Flatten BP fields for easier matching
        $all_bp_fields = array();
        foreach ($bp_fields as $category => $fields) {
            $all_bp_fields = array_merge($all_bp_fields, $fields);
        }

        // Define common field mappings
        $common_mappings = array(
            // Exact matches
            'username' => 'username',
            'user_login' => 'username',
            'login' => 'username',
            'email' => 'email',
            'user_email' => 'email',
            'email_address' => 'email',
            'display_name' => 'display_name',
            'displayname' => 'display_name',
            'full_name' => 'display_name',
            'first_name' => 'first_name',
            'firstname' => 'first_name',
            'fname' => 'first_name',
            'last_name' => 'last_name',
            'lastname' => 'last_name',
            'lname' => 'last_name',
            'surname' => 'last_name',
            'description' => 'description',
            'bio' => 'description',
            'biography' => 'description',
            'about' => 'description',
            'user_url' => 'user_url',
            'website' => 'user_url',
            'url' => 'user_url',
            'homepage' => 'user_url',
            
            // XProfile field mappings (common patterns)
            'location' => 'xprofile_location',
            'city' => 'xprofile_location',
            'country' => 'xprofile_country',
            'phone' => 'xprofile_phone',
            'telephone' => 'xprofile_phone',
            'mobile' => 'xprofile_phone',
            'age' => 'xprofile_age',
            'birthday' => 'xprofile_birthday',
            'birthdate' => 'xprofile_birthday',
            'date_of_birth' => 'xprofile_birthday',
            'gender' => 'xprofile_gender',
            'occupation' => 'xprofile_occupation',
            'job' => 'xprofile_occupation',
            'company' => 'xprofile_company',
            'organization' => 'xprofile_company'
        );

        foreach ($import_fields as $import_field) {
            $import_field_lower = strtolower(trim($import_field));
            
            // Check for exact matches first
            if (isset($common_mappings[$import_field_lower])) {
                $suggested_field = $common_mappings[$import_field_lower];
                if (array_key_exists($suggested_field, $all_bp_fields)) {
                    $suggestions[$suggested_field] = $import_field;
                    continue;
                }
            }

            // Check for partial matches
            foreach ($all_bp_fields as $bp_field => $bp_label) {
                $bp_field_clean = str_replace(array('xprofile_', 'meta_'), '', $bp_field);
                
                if (strpos($import_field_lower, strtolower($bp_field_clean)) !== false ||
                    strpos(strtolower($bp_field_clean), $import_field_lower) !== false) {
                    $suggestions[$bp_field] = $import_field;
                    break;
                }
            }
        }

        return apply_filters('bp_export_import_suggested_mapping', $suggestions, $import_fields);
    }

    /**
     * Validate field mapping configuration
     *
     * @param array $mapping Field mapping configuration
     * @return true|WP_Error
     */
    public function validate_field_mapping($mapping) {
        if (empty($mapping) || !is_array($mapping)) {
            return new WP_Error('empty_mapping', __('Field mapping cannot be empty.', 'bp-export-import'));
        }

        $bp_fields = $this->get_available_bp_fields();
        $all_bp_fields = array();
        foreach ($bp_fields as $category => $fields) {
            $all_bp_fields = array_merge($all_bp_fields, array_keys($fields));
        }

        foreach ($mapping as $bp_field => $import_field) {
            // Check if BP field exists
            if (!in_array($bp_field, $all_bp_fields)) {
                return new WP_Error('invalid_bp_field', sprintf(
                    __('Invalid BuddyPress field: %s', 'bp-export-import'),
                    $bp_field
                ));
            }

            // Check for required fields
            if (in_array($bp_field, array('username', 'email')) && empty($import_field)) {
                return new WP_Error('missing_required_mapping', sprintf(
                    __('Required field "%s" must be mapped.', 'bp-export-import'),
                    $bp_field
                ));
            }
        }

        return true;
    }

    /**
     * Export field mapping configuration
     *
     * @param string $mapping_id Mapping ID
     * @return string|false JSON string or false on failure
     */
    public function export_field_mapping($mapping_id) {
        $mapping = $this->get_field_mapping($mapping_id);
        
        if (!$mapping) {
            return false;
        }

        return json_encode($mapping, JSON_PRETTY_PRINT);
    }

    /**
     * Import field mapping configuration
     *
     * @param string $json_data JSON mapping data
     * @return string|false Mapping ID or false on failure
     */
    public function import_field_mapping($json_data) {
        $mapping_data = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!isset($mapping_data['name']) || !isset($mapping_data['mapping'])) {
            return false;
        }

        // Validate mapping
        $validation_result = $this->validate_field_mapping($mapping_data['mapping']);
        if (is_wp_error($validation_result)) {
            return false;
        }

        return $this->save_field_mapping_data($mapping_data['name'], $mapping_data['mapping']);
    }

    /**
     * Get field mapping statistics
     *
     * @return array Mapping statistics
     */
    public function get_mapping_statistics() {
        $mappings = $this->get_all_field_mappings();
        
        $stats = array(
            'total_mappings' => count($mappings),
            'most_used_fields' => array(),
            'recent_mappings' => array()
        );

        // Analyze field usage
        $field_usage = array();
        foreach ($mappings as $mapping) {
            foreach ($mapping['mapping'] as $bp_field => $import_field) {
                if (!isset($field_usage[$bp_field])) {
                    $field_usage[$bp_field] = 0;
                }
                $field_usage[$bp_field]++;
            }
        }

        // Sort by usage and get top 10
        arsort($field_usage);
        $stats['most_used_fields'] = array_slice($field_usage, 0, 10, true);

        // Get recent mappings
        $recent_mappings = array_slice($mappings, -5, 5, true);
        foreach ($recent_mappings as $id => $mapping) {
            $stats['recent_mappings'][] = array(
                'id' => $id,
                'name' => $mapping['name'],
                'created_at' => $mapping['created_at'],
                'field_count' => count($mapping['mapping'])
            );
        }

        return $stats;
    }
}