<?php
// Export options for the BP Export Import plugin.

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$exporter = new BP_Export_Import_Export();
$xprofile_fields = $exporter->get_xprofile_field_names();
$random_user_meta_keys = $exporter->get_random_user_meta_keys();
?>

<div class="wrap">
    <h1><?php esc_html_e('Export BuddyPress Data', 'bp-export-import'); ?></h1>
    <form method="post" action="">
        <?php wp_nonce_field('bp_export_import_export_nonce', '_wpnonce_bp_export_import_export'); ?>

        <h2><?php esc_html_e('XProfile Fields', 'bp-export-import'); ?></h2>
        <label><input type="checkbox" id="select_all_xprofile_fields" /> <?php esc_html_e('Select All XProfile Fields', 'bp-export-import'); ?></label>
        <br />
        <?php foreach ($xprofile_fields as $field_name) : ?>
            <label>
                <input type="checkbox" name="xprofile_fields[]" value="<?php echo esc_attr($field_name); ?>" class="xprofile_field" />
                <?php echo esc_html($field_name); ?>
            </label><br />
        <?php endforeach; ?>

        <h2><?php esc_html_e('User Meta Fields', 'bp-export-import'); ?></h2>
        <label><input type="checkbox" id="select_all_user_meta_keys" /> <?php esc_html_e('Select All User Meta Fields', 'bp-export-import'); ?></label>
        <br />
        <?php if (! empty($random_user_meta_keys)) : ?>
            <?php foreach ($random_user_meta_keys as $meta_key) : ?>
                <label>
                    <input type="checkbox" name="user_meta_keys[]" value="<?php echo esc_attr($meta_key); ?>" class="user_meta_key" />
                    <?php echo esc_html($meta_key); ?>
                </label><br />
            <?php endforeach; ?>
        <?php else : ?>
            <p><?php esc_html_e('No user meta fields found.', 'bp-export-import'); ?></p>
        <?php endif; ?>

        <h2><?php esc_html_e('Export Format', 'bp-export-import'); ?></h2>
        <select name="export_format">
            <option value="csv"><?php esc_html_e('CSV', 'bp-export-import'); ?></option>
            <option value="json"><?php esc_html_e('JSON', 'bp-export-import'); ?></option>
            <option value="xml"><?php esc_html_e('XML', 'bp-export-import'); ?></option>
        </select>

        <input type="submit" name="bp_export_import_export" value="<?php esc_attr_e('Export', 'bp-export-import'); ?>" />
    </form>
</div>

<script type="text/javascript">
    document.getElementById('select_all_xprofile_fields').addEventListener('click', function() {
        var checkboxes = document.querySelectorAll('.xprofile_field');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
    });

    document.getElementById('select_all_user_meta_keys').addEventListener('click', function() {
        var checkboxes = document.querySelectorAll('.user_meta_key');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
    });
</script>