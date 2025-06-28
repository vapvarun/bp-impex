<?php
/**
 * Utility functions for the BP Export Import plugin.
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log a message to the WordPress debug log.
 *
 * @param string $message The message to log.
 */
function bp_export_import_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[BP Export Import] ' . $message);
    }
}

/**
 * Check if a specific BuddyPress component is active.
 *
 * @param string $component The component slug (e.g., 'xprofile', 'groups').
 * @return bool True if the component is active, false otherwise.
 */
function bp_export_import_is_component_active($component) {
    return function_exists('bp_is_active') && bp_is_active($component);
}

/**
 * Get the list of all BuddyPress components.
 *
 * @return array List of active BuddyPress components.
 */
function bp_export_import_get_active_components() {
    if (function_exists('buddypress')) {
        $bp = buddypress();
        return isset($bp->active_components) ? $bp->active_components : array();
    }
    return array();
}

/**
 * Safely retrieve a value from an array.
 *
 * @param array  $array   The array to retrieve the value from.
 * @param string $key     The key of the value.
 * @param mixed  $default The default value if the key doesn't exist.
 * @return mixed The value from the array or the default value.
 */
function bp_export_import_array_get($array, $key, $default = null) {
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * Get the full path of a template file in the plugin.
 *
 * @param string $template The template file name.
 * @return string The full path to the template file.
 */
function bp_export_import_get_template($template) {
    return BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/' . $template;
}

/**
 * Increase memory limit for large operations
 *
 * @param string $limit Memory limit (e.g., '512M', '1G')
 */
function bp_export_import_increase_memory_limit($limit = '512M') {
    if (function_exists('ini_set')) {
        $current_limit = ini_get('memory_limit');
        $current_bytes = wp_convert_hr_to_bytes($current_limit);
        $new_bytes = wp_convert_hr_to_bytes($limit);
        
        if ($new_bytes > $current_bytes) {
            ini_set('memory_limit', $limit);
        }
    }
}

/**
 * Increase time limit for long operations
 *
 * @param int $seconds Time limit in seconds (0 for unlimited)
 */
function bp_export_import_increase_time_limit($seconds = 300) {
    if (function_exists('set_time_limit') && !ini_get('safe_mode')) {
        set_time_limit($seconds);
    }
}

/**
 * Clean up temporary files older than specified days
 *
 * @param int $days_old Number of days old files to delete
 * @return int Number of files deleted
 */
function bp_export_import_cleanup_temp_files($days_old = 7) {
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/bp-export-import-temp/';
    
    if (!is_dir($temp_dir)) {
        return 0;
    }
    
    $files = glob($temp_dir . '*');
    $count = 0;
    $cutoff = time() - ($days_old * DAY_IN_SECONDS);
    
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            if (unlink($file)) {
                $count++;
            }
        }
    }
    
    return $count;
}

/**
 * Clean up old download files
 *
 * @param int $days_old Number of days old files to delete
 * @return int Number of files deleted
 */
function bp_export_import_cleanup_download_files($days_old = 30) {
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/bp-export-import-downloads/';
    
    if (!is_dir($downloads_dir)) {
        return 0;
    }
    
    $files = glob($downloads_dir . '*');
    $count = 0;
    $cutoff = time() - ($days_old * DAY_IN_SECONDS);
    
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff && basename($file) !== '.htaccess') {
            if (unlink($file)) {
                $count++;
            }
        }
    }
    
    return $count;
}

/**
 * Format file size in human readable format
 *
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function bp_export_import_format_bytes($bytes) {
    return size_format($bytes);
}

/**
 * Validate file extension
 *
 * @param string $filename Filename to check
 * @param array $allowed_extensions Array of allowed extensions
 * @return bool True if extension is allowed
 */
function bp_export_import_validate_file_extension($filename, $allowed_extensions = array('csv', 'json', 'xml')) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowed_extensions);
}

/**
 * Get file extension from filename
 *
 * @param string $filename Filename
 * @return string File extension
 */
function bp_export_import_get_file_extension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Sanitize filename for safe storage
 *
 * @param string $filename Original filename
 * @return string Sanitized filename
 */
function bp_export_import_sanitize_filename($filename) {
    // Remove directory traversal attempts
    $filename = basename($filename);
    
    // Remove special characters except dots, dashes, and underscores
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    
    // Remove multiple consecutive dots
    $filename = preg_replace('/\.{2,}/', '.', $filename);
    
    // Ensure filename isn't empty
    if (empty($filename)) {
        $filename = 'file_' . time();
    }
    
    return $filename;
}

/**
 * Check if user can perform import/export operations
 *
 * @param string $operation Operation type ('import' or 'export')
 * @return bool True if user has permission
 */
function bp_export_import_user_can($operation = 'export') {
    if (!is_user_logged_in()) {
        return false;
    }
    
    // Administrators can always perform operations
    if (current_user_can('manage_options')) {
        return true;
    }
    
    // Check specific capabilities
    switch ($operation) {
        case 'import':
            return current_user_can('import');
        case 'export':
            return current_user_can('export');
        default:
            return false;
    }
}

/**
 * Generate unique operation ID
 *
 * @param string $prefix Operation prefix
 * @return string Unique operation ID
 */
function bp_export_import_generate_operation_id($prefix = 'operation') {
    return $prefix . '_' . uniqid() . '_' . time();
}

/**
 * Convert relative time to human readable format
 *
 * @param int $timestamp Unix timestamp
 * @return string Human readable time difference
 */
function bp_export_import_time_ago($timestamp) {
    return human_time_diff($timestamp, current_time('timestamp')) . ' ' . __('ago', 'bp-export-import');
}

/**
 * Get upload directory for plugin files
 *
 * @param string $subdir Subdirectory name
 * @return array Upload directory info
 */
function bp_export_import_get_upload_dir($subdir = '') {
    $upload_dir = wp_upload_dir();
    
    if (!empty($subdir)) {
        $upload_dir['path'] = $upload_dir['basedir'] . '/bp-export-import-' . $subdir;
        $upload_dir['url'] = $upload_dir['baseurl'] . '/bp-export-import-' . $subdir;
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir['path'])) {
            wp_mkdir_p($upload_dir['path']);
        }
    }
    
    return $upload_dir;
}

/**
 * Check if string is valid JSON
 *
 * @param string $string String to check
 * @return bool True if valid JSON
 */
function bp_export_import_is_json($string) {
    if (!is_string($string)) {
        return false;
    }
    
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

/**
 * Truncate string to specified length
 *
 * @param string $string String to truncate
 * @param int $length Maximum length
 * @param string $suffix Suffix to append if truncated
 * @return string Truncated string
 */
function bp_export_import_truncate_string($string, $length = 100, $suffix = '...') {
    if (strlen($string) <= $length) {
        return $string;
    }
    
    return substr($string, 0, $length - strlen($suffix)) . $suffix;
}

/**
 * Check if current request is AJAX
 *
 * @return bool True if AJAX request
 */
function bp_export_import_is_ajax() {
    return wp_doing_ajax();
}

/**
 * Get BuddyPress user profile URL
 *
 * @param int $user_id User ID
 * @return string Profile URL
 */
function bp_export_import_get_user_profile_url($user_id) {
    if (function_exists('bp_core_get_user_domain')) {
        return bp_core_get_user_domain($user_id);
    }
    
    return get_author_posts_url($user_id);
}

/**
 * Schedule cleanup cron job
 */
function bp_export_import_schedule_cleanup() {
    if (!wp_next_scheduled('bp_export_import_cleanup')) {
        wp_schedule_event(time(), 'daily', 'bp_export_import_cleanup');
    }
}

/**
 * Handle cleanup cron job
 */
function bp_export_import_handle_cleanup() {
    $cleanup_days = get_option('bp_export_import_cleanup_days', 7);
    
    // Clean up temporary files
    bp_export_import_cleanup_temp_files($cleanup_days);
    
    // Clean up old download files (keep for 30 days)
    bp_export_import_cleanup_download_files(30);
    
    // Clean up old operation records
    $progress = bp_export_import()->get_component('progress');
    if ($progress) {
        $progress->cleanup_old_operations($cleanup_days);
    }
}
add_action('bp_export_import_cleanup', 'bp_export_import_handle_cleanup');

/**
 * Get plugin status information
 *
 * @return array Status information
 */
function bp_export_import_get_status() {
    return bp_export_import()->get_status();
}

/**
 * Check if BuddyPress is active and required components are available
 *
 * @return bool|WP_Error True if requirements met, WP_Error otherwise
 */
function bp_export_import_check_requirements() {
    return bp_export_import()->check_requirements();
}

/**
 * Display admin notice
 *
 * @param string $message Notice message
 * @param string $type Notice type (success, error, warning, info)
 */
function bp_export_import_admin_notice($message, $type = 'info') {
    add_action('admin_notices', function() use ($message, $type) {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    });
}

/**
 * Get localized string
 *
 * @param string $key String key
 * @param string $default Default value if key not found
 * @return string Localized string
 */
function bp_export_import_get_string($key, $default = '') {
    $strings = array(
        'processing' => __('Processing...', 'bp-export-import'),
        'completed' => __('Completed', 'bp-export-import'),
        'failed' => __('Failed', 'bp-export-import'),
        'cancelled' => __('Cancelled', 'bp-export-import'),
        'export_success' => __('Export completed successfully!', 'bp-export-import'),
        'import_success' => __('Import completed successfully!', 'bp-export-import'),
        'operation_not_found' => __('Operation not found.', 'bp-export-import'),
        'permission_denied' => __('Permission denied.', 'bp-export-import'),
        'invalid_file' => __('Invalid file format.', 'bp-export-import'),
        'file_too_large' => __('File is too large.', 'bp-export-import'),
        'no_file_selected' => __('No file selected.', 'bp-export-import'),
    );
    
    return isset($strings[$key]) ? $strings[$key] : $default;
}