<?php
/**
 * Import options template for BP Export Import plugin - FIXED VERSION
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get plugin components and ensure hooks are set up
$importer = bp_export_import()->get_component('import');
$field_mapping = bp_export_import()->get_component('field_mapping');

// Set up hooks only when on this page
if ($importer) {
    $importer->setup_hooks();
}
if ($field_mapping) {
    $field_mapping->setup_hooks();
}

// Get import statistics
$import_stats = $importer ? $importer->get_import_stats() : array();

// Get saved field mappings
$saved_mappings = $field_mapping ? $field_mapping->get_all_field_mappings() : array();

// Handle success/error messages
if (isset($_GET['import_success'])) {
    $processed = isset($_GET['processed']) ? intval($_GET['processed']) : 0;
    $errors = isset($_GET['errors']) ? intval($_GET['errors']) : 0;
    
    echo '<div class="notice notice-success is-dismissible">';
    echo '<p>' . sprintf(__('Import completed! %d users processed with %d errors.', 'bp-export-import'), $processed, $errors) . '</p>';
    echo '</div>';
}

if (isset($_GET['import_error'])) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode($_GET['import_error'])) . '</p></div>';
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Import BuddyPress Data', 'bp-export-import'); ?></h1>
    
    <div class="bp-export-import-container">
        <!-- Import Statistics -->
        <?php if (!empty($import_stats)) : ?>
        <div class="bp-import-stats-card">
            <h3><?php esc_html_e('Import Statistics', 'bp-export-import'); ?></h3>
            <div class="stats-grid">
                <?php if (isset($import_stats['recent_imports'])) : ?>
                <div class="stat-item">
                    <span class="stat-number"><?php echo count($import_stats['recent_imports']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Recent Imports', 'bp-export-import'); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (isset($import_stats['success_rate'])) : ?>
                <div class="stat-item">
                    <span class="stat-number"><?php echo esc_html($import_stats['success_rate']); ?>%</span>
                    <span class="stat-label"><?php esc_html_e('Success Rate', 'bp-export-import'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Import Form -->
        <div class="bp-import-form-card">
            <form method="post" action="" enctype="multipart/form-data" id="bp-import-form">
                <?php wp_nonce_field('bp_export_import_import_nonce', '_wpnonce_bp_export_import_import'); ?>

                <!-- File Upload Section -->
                <div class="form-section">
                    <h3><?php esc_html_e('Select Import File', 'bp-export-import'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Import File', 'bp-export-import'); ?></th>
                            <td>
                                <div class="file-upload-container">
                                    <input type="file" name="import_file" id="import_file" accept=".csv,.json,.xml" required />
                                    <p class="description">
                                        <?php esc_html_e('Supported formats: CSV, JSON, XML. Maximum file size: 50MB.', 'bp-export-import'); ?>
                                    </p>
                                </div>
                                
                                <div class="file-info" id="file-info" style="display: none;">
                                    <h4><?php esc_html_e('File Information', 'bp-export-import'); ?></h4>
                                    <div id="file-details"></div>
                                    <button type="button" id="preview-file" class="button"><?php esc_html_e('Preview File', 'bp-export-import'); ?></button>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Import Format', 'bp-export-import'); ?></th>
                            <td>
                                <select name="import_format" id="import_format">
                                    <option value=""><?php esc_html_e('Auto-detect', 'bp-export-import'); ?></option>
                                    <option value="csv"><?php esc_html_e('CSV', 'bp-export-import'); ?></option>
                                    <option value="json"><?php esc_html_e('JSON', 'bp-export-import'); ?></option>
                                    <option value="xml"><?php esc_html_e('XML', 'bp-export-import'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Leave as auto-detect to determine format from file extension.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- File Preview Section -->
                <div id="file-preview-section" class="form-section" style="display: none;">
                    <h3><?php esc_html_e('File Preview', 'bp-export-import'); ?></h3>
                    <div id="file-preview-content"></div>
                </div>

                <!-- Field Mapping Section -->
                <div id="field-mapping-section" class="form-section" style="display: none;">
                    <h3><?php esc_html_e('Field Mapping', 'bp-export-import'); ?></h3>
                    
                    <?php if (!empty($saved_mappings)) : ?>
                    <div class="saved-mappings">
                        <label for="saved_mapping"><?php esc_html_e('Use Saved Mapping:', 'bp-export-import'); ?></label>
                        <select id="saved_mapping" name="saved_mapping">
                            <option value=""><?php esc_html_e('Create new mapping', 'bp-export-import'); ?></option>
                            <?php foreach ($saved_mappings as $mapping_id => $mapping) : ?>
                                <option value="<?php echo esc_attr($mapping_id); ?>">
                                    <?php echo esc_html($mapping['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="load-mapping" class="button"><?php esc_html_e('Load Mapping', 'bp-export-import'); ?></button>
                    </div>
                    <?php endif; ?>
                    
                    <div id="field-mapping-table" class="field-mapping-container">
                        <!-- Field mapping table will be populated via JavaScript -->
                    </div>
                    
                    <div class="mapping-actions">
                        <button type="button" id="auto-map-fields" class="button"><?php esc_html_e('Auto-Map Fields', 'bp-export-import'); ?></button>
                        <button type="button" id="save-mapping" class="button"><?php esc_html_e('Save Mapping', 'bp-export-import'); ?></button>
                    </div>
                </div>

                <!-- Import Options Section -->
                <div class="form-section">
                    <h3><?php esc_html_e('Import Options', 'bp-export-import'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Import Mode', 'bp-export-import'); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e('Select import mode', 'bp-export-import'); ?></legend>
                                    <label>
                                        <input type="radio" name="import_mode" value="create_only" checked="checked" />
                                        <?php esc_html_e('Create new users only', 'bp-export-import'); ?>
                                        <span class="description"><?php esc_html_e('Skip existing users', 'bp-export-import'); ?></span>
                                    </label><br />
                                    
                                    <label>
                                        <input type="radio" name="import_mode" value="update_existing" />
                                        <?php esc_html_e('Update existing users only', 'bp-export-import'); ?>
                                        <span class="description"><?php esc_html_e('Skip users that don\'t exist', 'bp-export-import'); ?></span>
                                    </label><br />
                                    
                                    <label>
                                        <input type="radio" name="import_mode" value="create_and_update" />
                                        <?php esc_html_e('Create new and update existing', 'bp-export-import'); ?>
                                        <span class="description"><?php esc_html_e('Create new users and update existing ones', 'bp-export-import'); ?></span>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Notifications', 'bp-export-import'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="send_notification" value="1" />
                                    <?php esc_html_e('Send welcome emails to new users', 'bp-export-import'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('New users will receive WordPress welcome emails with login credentials.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Batch Size', 'bp-export-import'); ?></th>
                            <td>
                                <input type="number" name="batch_size" value="100" min="10" max="500" step="10" />
                                <p class="description"><?php esc_html_e('Number of users to process per batch. Lower values are safer for large imports.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Validation', 'bp-export-import'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dry_run" value="1" />
                                    <?php esc_html_e('Dry run (validate only, don\'t import)', 'bp-export-import'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Check for errors without actually importing users.', 'bp-export-import'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Submit Button -->
                <div class="form-section">
                    <p class="submit">
                        <input type="submit" name="bp_export_import_import" id="import-submit" class="button button-primary" value="<?php esc_attr_e('Start Import', 'bp-export-import'); ?>" />
                        <span class="spinner" id="import-spinner"></span>
                    </p>
                </div>
            </form>
        </div>

        <!-- Progress Container -->
        <div id="import-progress-container" class="bp-progress-container" style="display: none;">
            <h3><?php esc_html_e('Import Progress', 'bp-export-import'); ?></h3>
            <div class="progress-bar-container">
                <div class="progress-bar" id="import-progress-bar">
                    <div class="progress-fill" style="width: 0%;"></div>
                </div>
                <div class="progress-text">
                    <span id="import-progress-text"><?php esc_html_e('Preparing import...', 'bp-export-import'); ?></span>
                    <span id="import-progress-percentage">0%</span>
                </div>
            </div>
            <div class="progress-details">
                <p id="import-progress-details"></p>
                <div id="import-errors" class="import-errors" style="display: none;">
                    <h4><?php esc_html_e('Import Errors', 'bp-export-import'); ?></h4>
                    <ul id="import-errors-list"></ul>
                </div>
            </div>
            <button type="button" id="cancel-import" class="button"><?php esc_html_e('Cancel Import', 'bp-export-import'); ?></button>
        </div>
    </div>
</div>

<!-- Save Mapping Modal -->
<div id="save-mapping-modal" class="bp-modal" style="display: none;">
    <div class="bp-modal-content">
        <div class="bp-modal-header">
            <h3><?php esc_html_e('Save Field Mapping', 'bp-export-import'); ?></h3>
            <button type="button" class="bp-modal-close">&times;</button>
        </div>
        <div class="bp-modal-body">
            <label for="mapping-name"><?php esc_html_e('Mapping Name:', 'bp-export-import'); ?></label>
            <input type="text" id="mapping-name" placeholder="<?php esc_attr_e('Enter mapping name', 'bp-export-import'); ?>" />
        </div>
        <div class="bp-modal-footer">
            <button type="button" id="save-mapping-confirm" class="button button-primary"><?php esc_html_e('Save', 'bp-export-import'); ?></button>
            <button type="button" class="button bp-modal-close"><?php esc_html_e('Cancel', 'bp-export-import'); ?></button>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var filePreviewData = null;
    var currentOperationId = null;

    // File upload handling
    $('#import_file').on('change', function() {
        var file = this.files[0];
        if (file) {
            var fileSize = (file.size / 1024 / 1024).toFixed(2); // Convert to MB
            var fileName = file.name;
            var fileExtension = fileName.split('.').pop().toLowerCase();
            
            $('#file-details').html(
                '<p><strong><?php esc_js_e('File:', 'bp-export-import'); ?></strong> ' + fileName + '</p>' +
                '<p><strong><?php esc_js_e('Size:', 'bp-export-import'); ?></strong> ' + fileSize + ' MB</p>' +
                '<p><strong><?php esc_js_e('Type:', 'bp-export-import'); ?></strong> ' + fileExtension.toUpperCase() + '</p>'
            );
            
            $('#file-info').show();
            
            // Auto-detect format
            if (['csv', 'json', 'xml'].includes(fileExtension)) {
                $('#import_format').val(fileExtension);
            }
        }
    });

    // Form validation
    $('#bp-import-form').on('submit', function(e) {
        if (!document.getElementById('import_file').files.length) {
            e.preventDefault();
            alert('<?php esc_js_e('Please select a file to import.', 'bp-export-import'); ?>');
            return false;
        }
        
        // Show spinner
        $('#import-spinner').addClass('is-active');
        $('#import-submit').prop('disabled', true);
        
        // Show progress container
        setTimeout(function() {
            $('#import-progress-container').show();
        }, 1000);
    });

    // Modal functionality
    $('.bp-modal-close').on('click', function() {
        $(this).closest('.bp-modal').hide();
    });

    $('#save-mapping').on('click', function() {
        $('#save-mapping-modal').show();
    });
});
</script>

<style>
/* Import specific styles */
.bp-export-import-container {
    max-width: 1200px;
}

.bp-import-stats-card,
.bp-import-form-card {
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

.file-upload-container {
    border: 2px dashed #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    text-align: center;
    transition: border-color 0.3s ease;
}

.file-upload-container:hover {
    border-color: #0073aa;
}

.file-info {
    margin-top: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.bp-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bp-modal-content {
    background: #fff;
    border-radius: 4px;
    max-width: 500px;
    width: 90%;
    max-height: 90%;
    overflow: auto;
}

.bp-modal-header {
    padding: 20px;
    border-bottom: 1px solid #e1e1e1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.bp-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.bp-modal-body {
    padding: 20px;
}

.bp-modal-footer {
    padding: 20px;
    border-top: 1px solid #e1e1e1;
    text-align: right;
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