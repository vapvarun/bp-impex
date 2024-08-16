<?php
// Manages the field mapping for import/export in the BP Export Import plugin.

class BP_Export_Import_Field_Mapping
{

    /**
     * Constructor to set up hooks and initialize the field mapping process.
     */
    public function __construct()
    {
        // This can be hooked to admin screens or import process if needed.
    }

    /**
     * Display the field mapping interface in the admin panel.
     *
     * This function can be used to create a UI that allows the user to map
     * fields from the import file to BuddyPress profile fields.
     */
    public function display_field_mapping_interface()
    {
        // Retrieve available BuddyPress profile fields
        $bp_fields = $this->get_buddypress_fields();

        // Example: Display a simple form with dropdowns to map fields
        echo '<h2>' . __('Field Mapping', 'bp-export-import') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('bp_export_import_field_mapping_nonce', '_wpnonce_bp_export_import_field_mapping');

        foreach ($bp_fields as $field_id => $field_name) {
            echo '<label for="field_' . esc_attr($field_id) . '">' . esc_html($field_name) . '</label>';
            echo '<select name="field_mapping[' . esc_attr($field_id) . ']" id="field_' . esc_attr($field_id) . '">';
            echo '<option value="">' . __('Select field to map', 'bp-export-import') . '</option>';

            // Loop through import file fields and create options
            // This should be populated based on the import file structure
            // For example:
            echo '<option value="import_field_1">' . __('Import Field 1', 'bp-export-import') . '</option>';
            echo '<option value="import_field_2">' . __('Import Field 2', 'bp-export-import') . '</option>';

            echo '</select><br>';
        }

        echo '<input type="submit" value="' . __('Save Field Mapping', 'bp-export-import') . '" class="button button-primary">';
        echo '</form>';
    }

    /**
     * Retrieve BuddyPress profile fields.
     *
     * @return array Associative array of field IDs and names.
     */
    private function get_buddypress_fields()
    {
        global $wpdb;
        $fields = array();

        // Query to get all BuddyPress profile fields
        $results = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}bp_xprofile_fields WHERE parent_id = 0");

        if ($results) {
            foreach ($results as $field) {
                $fields[$field->id] = $field->name;
            }
        }

        return $fields;
    }

    /**
     * Map imported data fields to BuddyPress profile fields.
     *
     * @param array $imported_data The data imported from the file.
     * @param array $field_mapping The mapping configuration set by the user.
     * @return array Mapped profile data ready for saving.
     */
    public function map_fields($imported_data, $field_mapping)
    {
        $mapped_data = array();

        foreach ($field_mapping as $bp_field_id => $import_field_key) {
            if (isset($imported_data[$import_field_key])) {
                $mapped_data[$bp_field_id] = $imported_data[$import_field_key];
            }
        }

        return $mapped_data;
    }

    /**
     * Save the field mapping configuration.
     *
     * This can be called after the user submits the field mapping form.
     */
    public function save_field_mapping()
    {
        if (isset($_POST['field_mapping']) && check_admin_referer('bp_export_import_field_mapping_nonce', '_wpnonce_bp_export_import_field_mapping')) {
            $field_mapping = array_map('sanitize_text_field', $_POST['field_mapping']);
            update_option('bp_export_import_field_mapping', $field_mapping);
        }
    }

    /**
     * Retrieve the saved field mapping configuration.
     *
     * @return array The saved field mapping configuration.
     */
    public function get_saved_field_mapping()
    {
        return get_option('bp_export_import_field_mapping', array());
    }
}

new BP_Export_Import_Field_Mapping();
