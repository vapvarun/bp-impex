<?php
// Handles the export functionality for the BP Export Import plugin.

class BP_Export_Import_Export
{

    /**
     * Constructor to set up hooks and initialize the export process.
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'handle_export_request'));
    }

    /**
     * Handle the export request when the user submits the export form.
     */
    public function handle_export_request()
    {
        // Only proceed if we're on the specific page for export
        if (isset($_POST['bp_export_import_export']) && check_admin_referer('bp_export_import_export_nonce', '_wpnonce_bp_export_import_export')) {
            // This condition ensures that the export request is being processed
            $this->export_users();
        }
    }


    /**
     * Export BuddyPress user data based on the selected options.
     */
    public function export_users()
    {
        $users = $this->get_users();

        if ($users === false) {
            wp_die('No users found for export.');
        }

        // Get selected fields from the form submission
        $selected_xprofile_fields = isset($_POST['xprofile_fields']) ? array_map('sanitize_text_field', $_POST['xprofile_fields']) : [];
        $selected_user_meta_keys = isset($_POST['user_meta_keys']) ? array_map('sanitize_text_field', $_POST['user_meta_keys']) : [];

        if (empty($selected_xprofile_fields) && empty($selected_user_meta_keys)) {
            wp_die('No fields selected for export.');
        }

        $format = isset($_POST['export_format']) ? sanitize_text_field($_POST['export_format']) : 'csv';

        switch ($format) {
            case 'json':
                $this->export_as_json($users);
                break;
            case 'xml':
                $this->export_as_xml($users);
                break;
            case 'csv':
            default:
                $this->export_as_csv($users);
                break;
        }
    }

    /**
     * Retrieve users and their BuddyPress data for export.
     *
     * @return array|bool List of users with their data, or false if no users found.
     */
    private function get_users()
    {
        $args = array(
            'fields' => 'all',
            'role__in' => isset($_POST['roles']) ? array_map('sanitize_text_field', $_POST['roles']) : array(),
        );

        $user_query = new WP_User_Query($args);
        $users = $user_query->get_results();

        if (! is_array($users) || empty($users)) {
            return false;
        }

        return $users;
    }

    /**
     * Export users as a CSV file.
     *
     * @param array $users List of users to export.
     */
    private function export_as_csv($users)
    {
        $filename = 'bp-users-export-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');

        // Get selected XProfile fields and user meta keys
        $selected_xprofile_fields = isset($_POST['xprofile_fields']) ? array_map('sanitize_text_field', $_POST['xprofile_fields']) : [];
        $selected_user_meta_keys = isset($_POST['user_meta_keys']) ? array_map('sanitize_text_field', $_POST['user_meta_keys']) : [];

        // Create CSV headers
        $headers = ['User ID', 'Username', 'Email'];
        $headers = array_merge($headers, $selected_xprofile_fields, $selected_user_meta_keys);

        fputcsv($output, $headers);

        foreach ($users as $user) {
            $row = [
                $user->ID,
                $user->user_login,
                $user->user_email
            ];

            // Add selected XProfile data to the row
            $profile_data = $this->get_user_profile_data($user->ID);
            foreach ($selected_xprofile_fields as $field_name) {
                $row[] = isset($profile_data[$field_name]) ? $profile_data[$field_name] : '';
            }

            // Add selected user meta data to the row
            $user_meta = $this->get_user_meta_data($user->ID);
            foreach ($selected_user_meta_keys as $meta_key) {
                $meta_value = isset($user_meta[$meta_key]) ? maybe_unserialize($user_meta[$meta_key][0]) : '';

                if (is_array($meta_value)) {
                    $meta_value = json_encode($meta_value);
                }

                $row[] = $meta_value;
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Export users as a JSON file.
     *
     * @param array $users List of users to export.
     */
    private function export_as_json($users)
    {
        $filename = 'bp-users-export-' . date('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $data = array();

        foreach ($users as $user) {
            $user_data = array(
                'user_id'      => $user->ID,
                'username'     => $user->user_login,
                'email'        => $user->user_email,
                'profile_data' => $this->get_user_profile_data($user->ID),
                'user_meta'    => $this->get_user_meta_data($user->ID),
            );
            $data[] = $user_data;
        }

        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Export users as an XML file.
     *
     * @param array $users List of users to export.
     */
    private function export_as_xml($users)
    {
        $filename = 'bp-users-export-' . date('Y-m-d') . '.xml';

        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $xml = new SimpleXMLElement('<users/>');

        foreach ($users as $user) {
            $user_xml = $xml->addChild('user');
            $user_xml->addChild('user_id', $user->ID);
            $user_xml->addChild('username', $user->user_login);
            $user_xml->addChild('email', $user->user_email);

            $profile_data = $this->get_user_profile_data($user->ID);
            foreach ($profile_data as $field_name => $field_value) {
                $user_xml->addChild(sanitize_title($field_name), $field_value);
            }

            $user_meta = $this->get_user_meta_data($user->ID);
            foreach ($user_meta as $meta_key => $meta_value) {
                $user_xml->addChild(sanitize_title($meta_key), maybe_unserialize($meta_value[0]));
            }
        }

        echo $xml->asXML();
        exit;
    }

    /**
     * Retrieve the names of all BuddyPress XProfile fields.
     *
     * @return array List of XProfile field names.
     */
    public function get_xprofile_field_names()
    {
        $field_names = [];

        if (bp_is_active('xprofile')) {
            $profile_groups = bp_xprofile_get_groups(array(
                'fetch_fields' => true,
            ));

            if (is_array($profile_groups)) {
                foreach ($profile_groups as $group) {
                    foreach ($group->fields as $field) {
                        $field_names[] = $field->name;
                    }
                }
            }
        }

        return $field_names;
    }

    /**
     * Retrieve user profile data for export.
     *
     * @param int $user_id The user ID.
     * @return array Associative array of profile field names and values.
     */
    private function get_user_profile_data($user_id)
    {
        $profile_data = [];

        if (bp_is_active('xprofile')) {
            $profile_groups = bp_xprofile_get_groups(array(
                'fetch_fields' => true,
            ));

            if (is_array($profile_groups)) {
                foreach ($profile_groups as $group) {
                    foreach ($group->fields as $field) {
                        $field_value = xprofile_get_field_data($field->id, $user_id, 'comma');
                        $profile_data[$field->name] = $field_value;
                    }
                }
            }
        }

        return $profile_data;
    }

    /**
     * Retrieve all unique user meta keys for a random user.
     *
     * @return array Unique user meta keys.
     */
    public function get_random_user_meta_keys()
    {
        // Get a random user ID
        $random_user = get_users(array(
            'number' => 1,
            'orderby' => 'rand',
            'fields' => 'ID',
        ));

        if (empty($random_user) || ! isset($random_user[0])) {
            return [];
        }

        // Get the meta keys for the random user
        $user_id = $random_user[0];
        $user_meta = get_user_meta($user_id);
        $meta_keys = array_keys($user_meta);

        return $meta_keys;
    }

    /**
     * Retrieve all user meta data for export.
     *
     * @param int $user_id The user ID.
     * @return array Associative array of user meta keys and values.
     */
    private function get_user_meta_data($user_id)
    {
        return get_user_meta($user_id);
    }
}

new BP_Export_Import_Export();
