<?php
// Handles the import functionality for the BP Export Import plugin.

class BP_Export_Import_Import
{

    /**
     * Constructor to set up hooks and initialize the import process.
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'handle_import_request'));
    }

    /**
     * Handle the import request when the user submits the import form.
     */
    public function handle_import_request()
    {
        if (isset($_POST['bp_export_import_import']) && check_admin_referer('bp_export_import_import_nonce', '_wpnonce_bp_export_import_import')) {
            $this->import_users();
        }
    }

    /**
     * Import BuddyPress user data based on the uploaded file.
     */
    public function import_users()
    {
        if (! isset($_FILES['import_file']) || empty($_FILES['import_file']['tmp_name'])) {
            bp_export_import_log('No file uploaded for import.');
            return;
        }

        $file = $_FILES['import_file']['tmp_name'];
        $format = isset($_POST['import_format']) ? sanitize_text_field($_POST['import_format']) : 'csv';

        switch ($format) {
            case 'json':
                $this->import_from_json($file);
                break;
            case 'xml':
                $this->import_from_xml($file);
                break;
            case 'csv':
            default:
                $this->import_from_csv($file);
                break;
        }
    }

    /**
     * Import users from a CSV file.
     *
     * @param string $file The path to the uploaded file.
     */
    private function import_from_csv($file)
    {
        if (($handle = fopen($file, 'r')) !== false) {
            $header = fgetcsv($handle);

            while (($data = fgetcsv($handle)) !== false) {
                $user_data = array_combine($header, $data);
                $this->create_or_update_user($user_data);
            }

            fclose($handle);
        }
    }

    /**
     * Import users from a JSON file.
     *
     * @param string $file The path to the uploaded file.
     */
    private function import_from_json($file)
    {
        $json_data = file_get_contents($file);
        $users = json_decode($json_data, true);

        if (is_array($users)) {
            foreach ($users as $user_data) {
                $this->create_or_update_user($user_data);
            }
        }
    }

    /**
     * Import users from an XML file.
     *
     * @param string $file The path to the uploaded file.
     */
    private function import_from_xml($file)
    {
        $xml_data = simplexml_load_file($file);

        if ($xml_data && $xml_data->user) {
            foreach ($xml_data->user as $user) {
                $user_data = array(
                    'user_id' => (string) $user->user_id,
                    'username' => (string) $user->username,
                    'email' => (string) $user->email,
                    'profile_data' => maybe_unserialize((string) $user->profile_data),
                );
                $this->create_or_update_user($user_data);
            }
        }
    }

    /**
     * Create or update a user in the WordPress database.
     *
     * @param array $user_data The user data to create or update.
     */
    private function create_or_update_user($user_data)
    {
        $user_id = username_exists($user_data['username']);

        if (! $user_id && ! email_exists($user_data['email'])) {
            // Create a new user
            $user_id = wp_create_user($user_data['username'], wp_generate_password(), $user_data['email']);
        } else {
            // Update existing user
            $user_id = wp_update_user(array(
                'ID' => $user_id,
                'user_email' => $user_data['email'],
            ));
        }

        if (! is_wp_error($user_id)) {
            // Update BuddyPress profile data
            $this->update_user_profile_data($user_id, $user_data['profile_data']);
        }
    }

    /**
     * Update BuddyPress profile data for a user.
     *
     * @param int $user_id The user ID.
     * @param array $profile_data The profile data to update.
     */
    private function update_user_profile_data($user_id, $profile_data)
    {
        foreach ($profile_data as $field_id => $field_value) {
            xprofile_set_field_data($field_id, $user_id, $field_value);
        }
    }
}

new BP_Export_Import_Import();
