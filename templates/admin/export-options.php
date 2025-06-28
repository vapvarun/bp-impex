<?php
/**
 * Export options template for BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get plugin components and ensure hooks are set up
$exporter = bp_export_import()->get_component('export');
$field_mapping = bp_export_import()->get_component('field_mapping');

// Set up hooks only when on this page
if ($exporter) {
    $exporter->setup_hooks();
}
if ($field_mapping) {
    $field_mapping->setup_hooks();
}

// Get available fields
$xprofile_fields = array();
$user_meta_keys = array();

if ($exporter) {
    $xprofile_fields = $exporter->get_xprofile_field_names();
    $user_meta_keys = $exporter->get_user_meta_keys_sample();
}

// Get export statistics
$export_stats = $exporter ? $exporter->get_export_stats() : array();

// Handle success/error messages
if (isset($_GET['export_success'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Export completed successfully!', 'bp-export-import') . '</p></div>';
}

if (isset($_GET['export_error'])) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode($_GET['export_error'])) . '</p></div>';
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Export BuddyPress Data', 'bp-export-import'); ?></h1>
    
    <div class="bp-export-import-container">
        <!-- Export Statistics -->
        <?php if (!empty($export_stats)) : ?>
        <div class="bp-export-stats-card">
            <h3><?php esc_html_e('Export Statistics', 'bp-export-import'); ?></h3>
            <div class="stats-grid">
                <?php if (isset($export_stats['total_users']['total_users'])) : ?>
                <div class="stat-item">
                    <span class="stat-number"><?php echo esc_html($export_stats['total_users']['total_users']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Total Users', 'bp-export-import'); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (isset($export_stats['recent_exports'])) : ?>
                <div class="stat-item">
                    <span class="stat-number"><?php echo count($export_stats['recent_exports']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Recent Exports', 'bp-export-import'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Export Form -->
        <div class="bp-export-form-card">
            <form method="post" action="" id="bp-export-form">
                <?php wp_nonce_field('bp_export_import_export_nonce', '_wpnonce_bp_export_import_export'); ?>

                <!-- User Filters -->
                <div class="form-section">
                    <h3><?php esc_html_e('User Filters', 'bp-export-import'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('User Roles', 'bp-export-import'); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e('Select user roles', 'bp-export-import'); ?></legend>
                                    <label>
                                        <input type="checkbox" id="select_all_roles" />
                                        <strong><?php esc_html_e('Select All Roles', 'bp-export-import'); ?></strong>
                                    </label><br />
                                    
                                    <?php 
                                    $roles = wp_roles()->get_names();
                                    foreach ($roles as $role_key => $role_name) : 
                                    ?>
                                        <label>
                                            <input type="checkbox" name="roles[]" value="<?php echo esc_attr($role_key); ?>" class="role-checkbox" />
                                            <?php echo esc_html($role_name); ?>
                                        </label><br />
                                    <?php endforeach; ?>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Date Range', 'bp-export-import'); ?></th>
                            <td>
                                <label for="date_from"><?php esc_html_e('From:', 'bp-export-import'); ?></label>
                                <input type="date" id="date_from" name="date_from" />
                                
                                <label for="date_to"><?php esc_html_e('To:', 'bp-export-import'); ?></label>
                                <input type="date" id="date_to" name="date_to" />
                                
                                <p class="description"><?php esc_html_e('Leave empty to export all users regardless of registration date.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- XProfile Fields -->
                <?php if (!empty($xprofile_fields)) : ?>
                <div class="form-section">
                    <h3><?php esc_html_e('BuddyPress Profile Fields', 'bp-export-import'); ?></h3>
                    
                    <div class="fields-selection">
                        <label>
                            <input type="checkbox" id="select_all_xprofile_fields" />
                            <strong><?php esc_html_e('Select All Profile Fields', 'bp-export-import'); ?></strong>
                        </label>
                        
                        <div class="fields-grid">
                            <?php foreach ($xprofile_fields as $field_name) : ?>
                                <label class="field-label">
                                    <input type="checkbox" name="xprofile_fields[]" value="<?php echo esc_attr($field_name); ?>" class="xprofile_field" />
                                    <?php echo esc_html($field_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- User Meta Fields -->
                <?php if (!empty($user_meta_keys)) : ?>
                <div class="form-section">
                    <h3><?php esc_html_e('User Meta Fields', 'bp-export-import'); ?></h3>
                    
                    <div class="fields-selection">
                        <label>
                            <input type="checkbox" id="select_all_user_meta_keys" />
                            <strong><?php esc_html_e('Select All Meta Fields', 'bp-export-import'); ?></strong>
                        </label>
                        
                        <div class="fields-grid">
                            <?php foreach ($user_meta_keys as $meta_key) : ?>
                                <label class="field-label">
                                    <input type="checkbox" name="user_meta_keys[]" value="<?php echo esc_attr($meta_key); ?>" class="user_meta_key" />
                                    <?php echo esc_html($meta_key); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Export Options -->
                <div class="form-section">
                    <h3><?php esc_html_e('Export Options', 'bp-export-import'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Export Format', 'bp-export-import'); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e('Select export format', 'bp-export-import'); ?></legend>
                                    <label>
                                        <input type="radio" name="export_format" value="csv" checked="checked" />
                                        <?php esc_html_e('CSV (Comma Separated Values)', 'bp-export-import'); ?>
                                        <span class="description"><?php esc_html_e('Best for Excel and spreadsheet applications', 'bp-export-import'); ?></span>
                                    </label><br />
                                    
                                    <label>
                                        <input type="radio" name="export_format" value="json" />
                                        <?php esc_html_e('JSON (JavaScript Object Notation)', 'bp-export-import'); ?>
                                        <span class="description"><?php esc_html_e('Best for web applications and APIs', 'bp-export-import'); ?></span>
                                    </label><br />
                                    
                                    <label>
                                        <input type="radio" name="export_format" value="xml" />
                                        <?php esc_html_e('XML (Extensible Markup Language)', 'bp-export-import'); ?>
                                        <span class="description"><?php esc_html_e('Best for data exchange between systems', 'bp-export-import'); ?></span>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Background Processing', 'bp-export-import'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="background_processing" value="1" />
                                    <?php esc_html_e('Process export in background', 'bp-export-import'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Recommended for large exports (1000+ users). You will receive an email when the export is complete.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Batch Size', 'bp-export-import'); ?></th>
                            <td>
                                <input type="number" name="batch_size" value="500" min="100" max="2000" step="100" />
                                <p class="description"><?php esc_html_e('Number of users to process per batch. Lower values use less memory but take longer.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Submit Button -->
                <div class="form-section">
                    <p class="submit">
                        <input type="submit" name="bp_export_import_export" id="export-submit" class="button button-primary" value="<?php esc_attr_e('Start Export', 'bp-export-import'); ?>" />
                        <span class="spinner" id="export-spinner"></span>
                    </p>
                </div>
            </form>
        </div>

        <!-- Progress Container (hidden by default) -->
        <div id="export-progress-container" class="bp-progress-container" style="display: none;">
            <h3><?php esc_html_e('Export Progress', 'bp-export-import'); ?></h3>
            <div class="progress-bar-container">
                <div class="progress-bar" id="export-progress-bar">
                    <div class="progress-fill" style="width: 0%;"></div>
                </div>
                <div class="progress-text">
                    <span id="export-progress-text"><?php esc_html_e('Preparing export...', 'bp-export-import'); ?></span>
                    <span id="export-progress-percentage">0%</span>
                </div>
            </div>
            <div class="progress-details">
                <p id="export-progress-details"></p>
            </div>
            <button type="button" id="cancel-export" class="button"><?php esc_html_e('Cancel Export', 'bp-export-import'); ?></button>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Select all checkboxes functionality
    $('#select_all_roles').on('change', function() {
        $('.role-checkbox').prop('checked', this.checked);
    });
    
    $('#select_all_xprofile_fields').on('change', function() {
        $('.xprofile_field').prop('checked', this.checked);
    });

    $('#select_all_user_meta_keys').on('change', function() {
        $('.user_meta_key').prop('checked', this.checked);
    });

    // Form validation
    $('#bp-export-form').on('submit', function(e) {
        var hasXProfileFields = $('.xprofile_field:checked').length > 0;
        var hasMetaFields = $('.user_meta_key:checked').length > 0;
        
        if (!hasXProfileFields && !hasMetaFields) {
            e.preventDefault();
            alert('<?php esc_js_e('Please select at least one field type to export.', 'bp-export-import'); ?>');
            return false;
        }
        
        // Show spinner
        $('#export-spinner').addClass('is-active');
        $('#export-submit').prop('disabled', true);
        
        // If background processing is enabled, show progress container
        if ($('input[name="background_processing"]:checked').length > 0) {
            setTimeout(function() {
                $('#export-progress-container').show();
                // Start progress polling (implementation depends on AJAX setup)
            }, 1000);
        }
    });
});
</script>

<style>
.bp-export-import-container {
    max-width: 1200px;
}

.bp-export-stats-card,
.bp-export-form-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: #f6f7f7;
    border-radius: 4px;
}

.stat-number {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #0073aa;
}

.stat-label {
    display: block;
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    margin-top: 5px;
}

.form-section {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e1e1e1;
}

.form-section:last-child {
    border-bottom: none;
}

.fields-selection {
    margin-top: 10px;
}

.fields-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
    margin-top: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.field-label {
    display: block;
    padding: 5px;
    font-size: 13px;
}

.field-label input {
    margin-right: 8px;
}

.bp-progress-container {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-top: 20px;
}

.progress-bar-container {
    margin: 15px 0;
}

.progress-bar {
    width: 100%;
    height: 20px;
    background: #e1e1e1;
    border-radius: 10px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #005a87);
    transition: width 0.3s ease;
}

.progress-text {
    display: flex;
    justify-content: space-between;
    margin-top: 10px;
    font-size: 14px;
}

.progress-details {
    margin: 15px 0;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
    font-size: 13px;
}

.description {
    font-style: italic;
    color: #666;
    display: block;
    margin-top: 3px;
}
</style>