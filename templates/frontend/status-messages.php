<?php
// Status messages for the BP Export Import plugin.

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Example: Display status messages for users
if (isset($_GET['bp_export_import_status'])) {
    $status = sanitize_text_field($_GET['bp_export_import_status']);

    switch ($status) {
        case 'export_success':
            echo '<div class="bp-export-import-message success">';
            esc_html_e('Your data has been successfully exported.', 'bp-export-import');
            echo '</div>';
            break;

        case 'import_success':
            echo '<div class="bp-export-import-message success">';
            esc_html_e('Your data has been successfully imported.', 'bp-export-import');
            echo '</div>';
            break;

        case 'error':
            echo '<div class="bp-export-import-message error">';
            esc_html_e('There was an error processing your request. Please try again later.', 'bp-export-import');
            echo '</div>';
            break;

        default:
            // No status or unknown status
            break;
    }
}
