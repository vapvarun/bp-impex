<?php
/**
 * BP Export Import Frontend Class
 *
 * Handles frontend functionality for user profile export
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BP_Export_Import_Frontend {

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
     * Setup WordPress and BuddyPress hooks
     */
    private function setup_hooks() {
        // Only enable if frontend export is enabled
        if (!get_option('bp_export_import_enable_frontend_export', true)) {
            return;
        }

        // BuddyPress navigation hooks
        add_action('bp_setup_nav', array($this, 'setup_nav'), 100);
        add_action('bp_actions', array($this, 'handle_export_request'));
        
        // AJAX hooks for frontend
        add_action('wp_ajax_bp_frontend_export', array($this, 'ajax_export_profile'));
        add_action('wp_ajax_bp_frontend_export_progress', array($this, 'ajax_export_progress'));
    }

    /**
     * Setup BuddyPress navigation
     */
    public function setup_nav() {
        if (!bp_is_active('settings')) {
            return;
        }

        // Add export tab under settings
        bp_core_new_subnav_item(array(
            'name'            => __('Export Data', 'bp-export-import'),
            'slug'            => 'export-data',
            'parent_slug'     => 'settings',
            'parent_url'      => bp_displayed_user_domain() . 'settings/',
            'screen_function' => array($this, 'export_screen'),
            'position'        => 30,
            'user_has_access' => bp_is_my_profile()
        ));
    }

    /**
     * Export screen function
     */
    public function export_screen() {
        add_action('bp_template_content', array($this, 'export_content'));
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
    }

    /**
     * Export screen content
     */
    public function export_content() {
        $user_id = bp_displayed_user_id();
        
        // Security check
        if (!bp_is_my_profile()) {
            echo '<div class="bp-feedback error">';
            echo '<span class="bp-icon" aria-hidden="true"></span>';
            echo '<p>' . __('You can only export your own profile data.', 'bp-export-import') . '</p>';
            echo '</div>';
            return;
        }

        // Get user's export history
        $recent_exports = $this->get_user_export_history($user_id, 5);
        
        include bp_export_import_get_template('frontend/export-profile.php');
    }

    /**
     * Handle frontend export request
     */
    public function handle_export_request() {
        if (!isset($_POST['bp_frontend_export']) || 
            !check_admin_referer('bp_frontend_export_nonce', '_wpnonce_bp_frontend_export')) {
            return;
        }

        // Security checks
        if (!bp_is_my_profile()) {
            bp_core_add_message(__('You can only export your own profile data.', 'bp-export-import'), 'error');
            return;
        }

        $user_id = bp_displayed_user_id();
        $format = isset($_POST['export_format']) ? sanitize_text_field($_POST['export_format']) : 'csv';
        $include_meta = isset($_POST['include_meta']) ? (bool) $_POST['include_meta'] : false;
        $include_activity = isset($_POST['include_activity']) ? (bool) $_POST['include_activity'] : false;

        try {
            $this->export_user_profile($user_id, $format, $include_meta, $include_activity);
        } catch (Exception $e) {
            bp_core_add_message(__('Export failed. Please try again.', 'bp-export-import'), 'error');
            
            if ($this->logger) {
                $this->logger->log_error('Frontend export failed', array(
                    'user_id' => $user_id,
                    'error' => $e->getMessage()
                ));
            }
        }
    }

    /**
     * AJAX handler for profile export
     */
    public function ajax_export_profile() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('Please log in to export your profile.', 'bp-export-import'));
        }

        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
        $include_meta = isset($_POST['include_meta']) ? (bool) $_POST['include_meta'] : false;
        $include_activity = isset($_POST['include_activity']) ? (bool) $_POST['include_activity'] : false;

        try {
            $download_url = $this->export_user_profile($user_id, $format, $include_meta, $include_activity, true);
            
            wp_send_json_success(array(
                'download_url' => $download_url,
                'message' => __('Export completed successfully!', 'bp-export-import')
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX handler for export progress
     */
    public function ajax_export_progress() {
        check_ajax_referer('bp_export_import_ajax', 'nonce');
        
        $operation_id = isset($_POST['operation_id']) ? sanitize_text_field($_POST['operation_id']) : '';
        
        if (empty($operation_id)) {
            wp_send_json_error(__('Invalid operation ID.', 'bp-export-import'));
        }

        $progress = bp_export_import()->get_component('progress');
        if ($progress) {
            $progress_data = $progress->get_progress($operation_id);
            
            if ($progress_data && $progress_data['user_id'] === get_current_user_id()) {
                wp_send_json_success($progress_data);
            }
        }
        
        wp_send_json_error(__('Operation not found.', 'bp-export-import'));
    }

    /**
     * Export user profile data
     *
     * @param int $user_id User ID
     * @param string $format Export format
     * @param bool $include_meta Include user meta
     * @param bool $include_activity Include activity data
     * @param bool $return_url Return download URL instead of direct download
     * @return string|void Download URL if $return_url is true
     */
    private function export_user_profile($user_id, $format = 'csv', $include_meta = false, $include_activity = false, $return_url = false) {
        // Get user data
        $user = get_userdata($user_id);
        if (!$user) {
            throw new Exception(__('User not found.', 'bp-export-import'));
        }

        // Prepare export data
        $export_data = $this->prepare_user_export_data($user, $include_meta, $include_activity);
        
        // Create export file
        $filename = 'profile-export-' . $user_id . '-' . date('Y-m-d-H-i-s') . '.' . $format;
        
        if ($return_url) {
            // Save to downloads directory and return URL
            $upload_dir = wp_upload_dir();
            $downloads_dir = $upload_dir['basedir'] . '/bp-export-import-downloads/';
            
            if (!is_dir($downloads_dir)) {
                wp_mkdir_p($downloads_dir);
            }
            
            $file_path = $downloads_dir . $filename;
            $this->write_export_file($export_data, $file_path, $format);
            
            // Log the export
            if ($this->logger) {
                $this->logger->log_info('Frontend profile export completed', array(
                    'user_id' => $user_id,
                    'format' => $format,
                    'file' => $filename
                ));
            }
            
            return $upload_dir['baseurl'] . '/bp-export-import-downloads/' . $filename;
        } else {
            // Direct download
            $this->send_download_headers($filename, $format);
            $this->output_export_data($export_data, $format);
            exit;
        }
    }

    /**
     * Prepare user export data
     *
     * @param WP_User $user User object
     * @param bool $include_meta Include user meta
     * @param bool $include_activity Include activity data
     * @return array Export data
     */
    private function prepare_user_export_data($user, $include_meta = false, $include_activity = false) {
        $export_data = array();

        // Basic user information
        $user_info = array(
            'section' => 'User Information',
            'data' => array(
                'user_id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'nickname' => $user->nickname,
                'description' => $user->description,
                'website' => $user->user_url,
                'registered' => $user->user_registered,
                'roles' => implode(', ', $user->roles)
            )
        );
        $export_data[] = $user_info;

        // BuddyPress profile data
        if (function_exists('bp_is_active') && bp_is_active('xprofile')) {
            $profile_data = $this->get_xprofile_data($user->ID);
            if (!empty($profile_data)) {
                $export_data[] = array(
                    'section' => 'Profile Fields',
                    'data' => $profile_data
                );
            }
        }

        // User meta data
        if ($include_meta) {
            $meta_data = $this->get_user_meta_data($user->ID);
            if (!empty($meta_data)) {
                $export_data[] = array(
                    'section' => 'User Meta',
                    'data' => $meta_data
                );
            }
        }

        // Activity data
        if ($include_activity && function_exists('bp_is_active') && bp_is_active('activity')) {
            $activity_data = $this->get_user_activity_data($user->ID);
            if (!empty($activity_data)) {
                $export_data[] = array(
                    'section' => 'Recent Activity',
                    'data' => $activity_data
                );
            }
        }

        // Friends data
        if (function_exists('bp_is_active') && bp_is_active('friends')) {
            $friends_data = $this->get_user_friends_data($user->ID);
            if (!empty($friends_data)) {
                $export_data[] = array(
                    'section' => 'Friends',
                    'data' => $friends_data
                );
            }
        }

        // Groups data
        if (function_exists('bp_is_active') && bp_is_active('groups')) {
            $groups_data = $this->get_user_groups_data($user->ID);
            if (!empty($groups_data)) {
                $export_data[] = array(
                    'section' => 'Groups',
                    'data' => $groups_data
                );
            }
        }

        return apply_filters('bp_export_import_frontend_export_data', $export_data, $user);
    }

    /**
     * Get user XProfile data
     *
     * @param int $user_id User ID
     * @return array XProfile data
     */
    private function get_xprofile_data($user_id) {
        $profile_data = array();

        if (!function_exists('bp_xprofile_get_groups')) {
            return $profile_data;
        }

        $profile_groups = bp_xprofile_get_groups(array(
            'user_id' => $user_id,
            'fetch_fields' => true
        ));

        if (is_array($profile_groups)) {
            foreach ($profile_groups as $group) {
                if (isset($group->fields) && is_array($group->fields)) {
                    foreach ($group->fields as $field) {
                        $field_value = '';
                        if (function_exists('xprofile_get_field_data')) {
                            $field_value = xprofile_get_field_data($field->id, $user_id, 'comma');
                        }
                        
                        if (!empty($field_value)) {
                            $profile_data[$field->name] = $field_value;
                        }
                    }
                }
            }
        }

        return $profile_data;
    }

    /**
     * Get user meta data (filtered for privacy)
     *
     * @param int $user_id User ID
     * @return array User meta data
     */
    private function get_user_meta_data($user_id) {
        $all_meta = get_user_meta($user_id);
        $filtered_meta = array();

        // Define meta keys that are safe to export
        $safe_meta_keys = apply_filters('bp_export_import_frontend_safe_meta_keys', array(
            'nickname',
            'description',
            'rich_editing',
            'syntax_highlighting',
            'comment_shortcuts',
            'admin_color',
            'use_ssl',
            'show_admin_bar_front',
            'locale',
            'timezone',
            // BuddyPress specific
            'last_activity',
            'bp_latest_update',
            'total_friend_count',
            'total_group_count'
        ));

        foreach ($all_meta as $key => $value) {
            // Only include safe meta keys and exclude WordPress internal keys
            if (in_array($key, $safe_meta_keys) && !empty($value[0])) {
                $filtered_meta[$key] = maybe_unserialize($value[0]);
                
                // Convert arrays to readable format
                if (is_array($filtered_meta[$key])) {
                    $filtered_meta[$key] = implode(', ', $filtered_meta[$key]);
                }
            }
        }

        return $filtered_meta;
    }

    /**
     * Get user activity data
     *
     * @param int $user_id User ID
     * @return array Activity data
     */
    private function get_user_activity_data($user_id) {
        if (!function_exists('bp_activity_get')) {
            return array();
        }

        $activities = bp_activity_get(array(
            'filter' => array('user_id' => $user_id),
            'per_page' => 20,
            'page' => 1
        ));

        $activity_data = array();
        
        if (!empty($activities['activities'])) {
            foreach ($activities['activities'] as $activity) {
                $activity_data[] = array(
                    'date' => $activity->date_recorded,
                    'type' => $activity->type,
                    'content' => wp_strip_all_tags($activity->content),
                    'component' => $activity->component
                );
            }
        }

        return $activity_data;
    }

    /**
     * Get user friends data
     *
     * @param int $user_id User ID
     * @return array Friends data
     */
    private function get_user_friends_data($user_id) {
        if (!function_exists('friends_get_friend_user_ids')) {
            return array();
        }

        $friend_ids = friends_get_friend_user_ids($user_id);
        $friends_data = array();

        if (!empty($friend_ids)) {
            foreach ($friend_ids as $friend_id) {
                $friend = get_userdata($friend_id);
                if ($friend) {
                    $friends_data[] = array(
                        'username' => $friend->user_login,
                        'display_name' => $friend->display_name,
                        'friendship_date' => $this->get_friendship_date($user_id, $friend_id)
                    );
                }
            }
        }

        return $friends_data;
    }

    /**
     * Get user groups data
     *
     * @param int $user_id User ID
     * @return array Groups data
     */
    private function get_user_groups_data($user_id) {
        if (!function_exists('groups_get_user_groups')) {
            return array();
        }

        $groups = groups_get_user_groups($user_id);
        $groups_data = array();

        if (!empty($groups['groups'])) {
            foreach ($groups['groups'] as $group_id) {
                $group = groups_get_group($group_id);
                if ($group) {
                    $groups_data[] = array(
                        'name' => $group->name,
                        'description' => wp_strip_all_tags($group->description),
                        'status' => $group->status,
                        'joined_date' => $this->get_group_join_date($user_id, $group_id)
                    );
                }
            }
        }

        return $groups_data;
    }

    /**
     * Get friendship date
     *
     * @param int $user_id User ID
     * @param int $friend_id Friend ID
     * @return string Friendship date
     */
    private function get_friendship_date($user_id, $friend_id) {
        global $wpdb;
        $bp = buddypress();
        
        if (!isset($bp->friends->table_name)) {
            return '';
        }

        $date = $wpdb->get_var($wpdb->prepare(
            "SELECT date_created FROM {$bp->friends->table_name} 
             WHERE (initiator_user_id = %d AND friend_user_id = %d) 
             OR (initiator_user_id = %d AND friend_user_id = %d)",
            $user_id, $friend_id, $friend_id, $user_id
        ));

        return $date ? date_i18n(get_option('date_format'), strtotime($date)) : '';
    }

    /**
     * Get group join date
     *
     * @param int $user_id User ID
     * @param int $group_id Group ID
     * @return string Join date
     */
    private function get_group_join_date($user_id, $group_id) {
        global $wpdb;
        $bp = buddypress();
        
        if (!isset($bp->groups->table_name_members)) {
            return '';
        }

        $date = $wpdb->get_var($wpdb->prepare(
            "SELECT date_modified FROM {$bp->groups->table_name_members} 
             WHERE user_id = %d AND group_id = %d",
            $user_id, $group_id
        ));

        return $date ? date_i18n(get_option('date_format'), strtotime($date)) : '';
    }

    /**
     * Send download headers
     *
     * @param string $filename Filename
     * @param string $format File format
     */
    private function send_download_headers($filename, $format) {
        $content_types = array(
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'text/xml'
        );

        $content_type = isset($content_types[$format]) ? $content_types[$format] : 'text/plain';

        header('Content-Type: ' . $content_type . '; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    /**
     * Output export data
     *
     * @param array $export_data Export data
     * @param string $format Output format
     */
    private function output_export_data($export_data, $format) {
        switch ($format) {
            case 'json':
                echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
            case 'xml':
                $this->output_xml($export_data);
                break;
            case 'csv':
            default:
                $this->output_csv($export_data);
                break;
        }
    }

    /**
     * Write export file
     *
     * @param array $export_data Export data
     * @param string $file_path File path
     * @param string $format File format
     */
    private function write_export_file($export_data, $file_path, $format) {
        switch ($format) {
            case 'json':
                file_put_contents($file_path, json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;
            case 'xml':
                ob_start();
                $this->output_xml($export_data);
                $xml_content = ob_get_clean();
                file_put_contents($file_path, $xml_content);
                break;
            case 'csv':
            default:
                $handle = fopen($file_path, 'w');
                if ($handle) {
                    // Add BOM for UTF-8
                    fwrite($handle, "\xEF\xBB\xBF");
                    $this->write_csv($export_data, $handle);
                    fclose($handle);
                }
                break;
        }
    }

    /**
     * Output CSV format
     *
     * @param array $export_data Export data
     */
    private function output_csv($export_data) {
        // Add BOM for proper UTF-8 encoding
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        $this->write_csv($export_data, $output);
        fclose($output);
    }

    /**
     * Write CSV data
     *
     * @param array $export_data Export data
     * @param resource $handle File handle
     */
    private function write_csv($export_data, $handle) {
        // Write headers
        fputcsv($handle, array('Section', 'Field', 'Value'));
        
        foreach ($export_data as $section) {
            $section_name = $section['section'];
            
            if (is_array($section['data'])) {
                foreach ($section['data'] as $field => $value) {
                    if (is_array($value)) {
                        $value = implode('; ', $value);
                    }
                    fputcsv($handle, array($section_name, $field, $value));
                }
            }
        }
    }

    /**
     * Output XML format
     *
     * @param array $export_data Export data
     */
    private function output_xml($export_data) {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<profile_export>' . "\n";
        
        foreach ($export_data as $section) {
            $section_name = sanitize_title($section['section']);
            echo "  <{$section_name}>\n";
            
            if (is_array($section['data'])) {
                foreach ($section['data'] as $field => $value) {
                    $field_name = sanitize_title($field);
                    if (is_array($value)) {
                        $value = implode('; ', $value);
                    }
                    $safe_value = htmlspecialchars($value, ENT_XML1, 'UTF-8');
                    echo "    <{$field_name}>{$safe_value}</{$field_name}>\n";
                }
            }
            
            echo "  </{$section_name}>\n";
        }
        
        echo '</profile_export>';
    }

    /**
     * Get user's export history
     *
     * @param int $user_id User ID
     * @param int $limit Number of records to retrieve
     * @return array Export history
     */
    private function get_user_export_history($user_id, $limit = 5) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array();
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE user_id = %d AND operation_type = 'export' 
             ORDER BY started_at DESC 
             LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A);

        return $results ?: array();
    }

    /**
     * Get frontend export statistics for user
     *
     * @param int $user_id User ID
     * @return array Export statistics
     */
    public function get_user_export_stats($user_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bp_export_import_operations';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array(
                'total_exports' => 0,
                'last_export' => null,
                'successful_exports' => 0
            );
        }

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_exports,
                MAX(started_at) as last_export,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_exports
             FROM {$table_name} 
             WHERE user_id = %d AND operation_type = 'export'",
            $user_id
        ), ARRAY_A);

        return $stats ?: array(
            'total_exports' => 0,
            'last_export' => null,
            'successful_exports' => 0
        );
    }
}