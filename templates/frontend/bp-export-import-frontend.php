<?php
// Handles the frontend export options for BuddyPress profiles.

// Add the Export Tab Under Settings
function bp_export_import_add_settings_tab()
{
    global $bp;

    bp_core_new_subnav_item(array(
        'name' => __('Export Profile Data', 'bp-export-import'),
        'slug' => 'export',
        'parent_slug' => 'settings',
        'parent_url' => trailingslashit(bp_loggedin_user_domain() . 'settings'),
        'screen_function' => 'bp_export_import_settings_export_screen',
        'position' => 30,
        'user_has_access' => bp_is_my_profile(), // Only allow the logged-in user to see this tab
    ));
}
add_action('bp_setup_nav', 'bp_export_import_add_settings_tab', 100);

// Create the Screen Function for the Export Tab
function bp_export_import_settings_export_screen()
{
    add_action('bp_template_content', 'bp_export_import_settings_export_content');
    bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
}

function bp_export_import_settings_export_content()
{
?>
    <h3><?php _e('Export Your Profile Data', 'bp-export-import'); ?></h3>
    <form method="post">
        <?php wp_nonce_field('bp_profile_export_nonce', '_wpnonce_bp_profile_export'); ?>
        <p><?php _e('Click the button below to export your profile data.', 'bp-export-import'); ?></p>
        <input type="submit" name="bp_profile_export_request" value="<?php esc_attr_e('Export Data', 'bp-export-import'); ?>" />
    </form>
<?php
}

// Handle the Export Request
function bp_export_import_handle_settings_export_request()
{
    if (isset($_POST['bp_profile_export_request']) && check_admin_referer('bp_profile_export_nonce', '_wpnonce_bp_profile_export')) {
        $user_id = bp_loggedin_user_id();
        bp_export_import_export_user_data($user_id);
    }
}
add_action('bp_actions', 'bp_export_import_handle_settings_export_request');

// Function to Export User Data
function bp_export_import_export_user_data($user_id)
{
    // Fetch user data
    $user = get_userdata($user_id);
    $xprofile_data = bp_get_profile_field_data(array('user_id' => $user_id, 'multi_format' => 'array'));
    $user_meta = get_user_meta($user_id);

    // Generate CSV file
    $filename = 'bp-profile-export-' . $user_id . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // Write CSV headers
    fputcsv($output, array('Field', 'Value'));

    // Write XProfile data
    if ($xprofile_data && is_array($xprofile_data)) {
        foreach ($xprofile_data as $field => $value) {
            if (!empty($value)) {
                if (is_array($value)) {
                    // If value is an array, convert it to a string
                    $value = implode(', ', $value);
                }
                fputcsv($output, array($field, $value));
            }
        }
    }

    // Write user meta data
    if ($user_meta && is_array($user_meta)) {
        foreach ($user_meta as $key => $value) {
            if (!empty($value)) {
                if (is_array($value)) {
                    // If value is an array, convert it to a string
                    $value = maybe_unserialize($value[0]);
                    if (is_array($value)) {
                        $value = json_encode($value); // Convert array to JSON for better readability
                    }
                }
                fputcsv($output, array($key, $value));
            }
        }
    }

    fclose($output);
    exit;
}
