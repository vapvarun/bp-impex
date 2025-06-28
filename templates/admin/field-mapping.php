<?php
// Field mapping interface for the BP Export Import plugin.

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Get BuddyPress fields and saved field mappings.
$field_mapping_instance = new BP_Export_Import_Field_Mapping();
$bp_fields = $field_mapping_instance->get_buddypress_fields();
$saved_mapping = $field_mapping_instance->get_saved_field_mapping();
?>

<div class="wrap">
    <h1><?php esc_html_e('Field Mapping', 'bp-export-import'); ?></h1>
    <form method="post" action="">
        <?php wp_nonce_field('bp_export_import_field_mapping_nonce', '_wpnonce_bp_export_import_field_mapping'); ?>

        <table class="form-table">
            <?php foreach ($bp_fields as $field_id => $field_name) : ?>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html($field_name); ?></th>
                    <td>
                        <select name="field_mapping[<?php echo esc_attr($field_id); ?>]">
                            <option value=""><?php esc_html_e('Select field to map', 'bp-export-import'); ?></option>
                            <?php
                            // Example import fields, replace with dynamic options based on the imported file structure.
                            $import_fields = ['import_field_1', 'import_field_2'];
                            foreach ($import_fields as $import_field) {
                                $selected = (isset($saved_mapping[$field_id]) && $saved_mapping[$field_id] === $import_field) ? 'selected' : '';
                                echo '<option value="' . esc_attr($import_field) . '" ' . esc_attr($selected) . '>' . esc_html($import_field) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php submit_button(__('Save Mapping', 'bp-export-import')); ?>
    </form>
</div>