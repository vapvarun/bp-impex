<?php
// Import options for the BP Export Import plugin.

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Import BuddyPress Data', 'bp-export-import'); ?></h1>
    <form method="post" action="" enctype="multipart/form-data">
        <?php wp_nonce_field('bp_export_import_import_nonce', '_wpnonce_bp_export_import_import'); ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php esc_html_e('Import File', 'bp-export-import'); ?></th>
                <td>
                    <input type="file" name="import_file" required />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php esc_html_e('Import Format', 'bp-export-import'); ?></th>
                <td>
                    <select name="import_format">
                        <option value="csv"><?php esc_html_e('CSV', 'bp-export-import'); ?></option>
                        <option value="json"><?php esc_html_e('JSON', 'bp-export-import'); ?></option>
                        <option value="xml"><?php esc_html_e('XML', 'bp-export-import'); ?></option>
                    </select>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Import', 'bp-export-import')); ?>
    </form>
</div>