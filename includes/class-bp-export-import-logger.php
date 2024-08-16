<?php
// Handles error logging and feedback for the BP Export Import plugin.

class BP_Export_Import_Logger
{

    /**
     * Log an error message to the WordPress debug log.
     *
     * @param string $message The error message to log.
     */
    public function log_error($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BP Export Import] ERROR: ' . $message);
        }
    }

    /**
     * Log a general message to the WordPress debug log.
     *
     * @param string $message The message to log.
     */
    public function log_info($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BP Export Import] INFO: ' . $message);
        }
    }

    /**
     * Display an admin notice for error messages.
     *
     * @param string $message The error message to display.
     */
    public function display_error_notice($message)
    {
        add_action('admin_notices', function () use ($message) {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * Display an admin notice for success messages.
     *
     * @param string $message The success message to display.
     */
    public function display_success_notice($message)
    {
        add_action('admin_notices', function () use ($message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        });
    }

    /**
     * Save error messages to a custom log file.
     *
     * @param string $message The error message to log.
     */
    public function save_error_to_logfile($message)
    {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/bp-export-import-errors.log';

        $formatted_message = date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL;
        file_put_contents($log_file, $formatted_message, FILE_APPEND);
    }

    /**
     * Clear the custom log file.
     */
    public function clear_logfile()
    {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/bp-export-import-errors.log';

        if (file_exists($log_file)) {
            unlink($log_file);
        }
    }

    /**
     * Retrieve the contents of the custom log file.
     *
     * @return string The contents of the log file.
     */
    public function get_logfile_contents()
    {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/bp-export-import-errors.log';

        if (file_exists($log_file)) {
            return file_get_contents($log_file);
        }

        return '';
    }
}

new BP_Export_Import_Logger();
