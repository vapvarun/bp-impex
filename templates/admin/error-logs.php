<?php
// Error logs for the BP Export Import plugin.

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Get the logger instance and retrieve log contents.
$logger = new BP_Export_Import_Logger();
$log_contents = $logger->get_logfile_contents();
?>

<div class="wrap">
    <h1><?php esc_html_e('Error Logs', 'bp-export-import'); ?></h1>
    <pre><?php echo esc_html($log_contents); ?></pre>
    <form method="post" action="">
        <?php wp_nonce_field('bp_export_import_clear_log_nonce', '_wpnonce_bp_export_import_clear_log'); ?>
        <?php submit_button(__('Clear Log', 'bp-export-import')); ?>
    </form>
</div>