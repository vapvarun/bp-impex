<?php
/**
 * Frontend export profile template for BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current user ID
$user_id = bp_displayed_user_id();

// Security check
if (!bp_is_my_profile()) {
    echo '<div class="bp-feedback error">';
    echo '<span class="bp-icon" aria-hidden="true"></span>';
    echo '<p>' . __('You can only export your own profile data.', 'bp-export-import') . '</p>';
    echo '</div>';
    return;
}

// Get frontend component
$frontend = bp_export_import()->get_component('frontend');

// Get user's export history
$recent_exports = $frontend ? $frontend->get_user_export_stats($user_id) : array();

// Handle messages
if (isset($_GET['export_success'])) {
    echo '<div class="bp-feedback success">';
    echo '<span class="bp-icon" aria-hidden="true"></span>';
    echo '<p>' . __('Your profile data has been exported successfully!', 'bp-export-import') . '</p>';
    echo '</div>';
}

if (isset($_GET['export_error'])) {
    echo '<div class="bp-feedback error">';
    echo '<span class="bp-icon" aria-hidden="true"></span>';
    echo '<p>' . esc_html(urldecode($_GET['export_error'])) . '</p>';
    echo '</div>';
}
?>

<div class="bp-export-profile-container">
    <div class="bp-export-intro">
        <h3><?php esc_html_e('Export Your Profile Data', 'bp-export-import'); ?></h3>
        <p><?php esc_html_e('Download a copy of your profile information, including your basic details, profile fields, and activity data.', 'bp-export-import'); ?></p>
    </div>

    <!-- Export Statistics -->
    <?php if (!empty($recent_exports) && $recent_exports['total_exports'] > 0) : ?>
    <div class="bp-export-stats">
        <h4><?php esc_html_e('Your Export History', 'bp-export-import'); ?></h4>
        <div class="export-stats-grid">
            <div class="stat-item">
                <span class="stat-number"><?php echo esc_html($recent_exports['total_exports']); ?></span>
                <span class="stat-label"><?php esc_html_e('Total Exports', 'bp-export-import'); ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo esc_html($recent_exports['successful_exports']); ?></span>
                <span class="stat-label"><?php esc_html_e('Successful', 'bp-export-import'); ?></span>
            </div>
            <?php if ($recent_exports['last_export']) : ?>
            <div class="stat-item">
                <span class="stat-number"><?php echo esc_html(human_time_diff(strtotime($recent_exports['last_export']), current_time('timestamp'))); ?></span>
                <span class="stat-label"><?php esc_html_e('Last Export', 'bp-export-import'); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Export Form -->
    <div class="bp-export-form">
        <form method="post" id="bp-frontend-export-form">
            <?php wp_nonce_field('bp_frontend_export_nonce', '_wpnonce_bp_frontend_export'); ?>

            <!-- Export Options -->
            <div class="export-options">
                <h4><?php esc_html_e('Export Options', 'bp-export-import'); ?></h4>
                
                <div class="export-format-section">
                    <label class="section-label"><?php esc_html_e('Export Format:', 'bp-export-import'); ?></label>
                    <div class="format-options">
                        <label class="format-option">
                            <input type="radio" name="export_format" value="csv" checked="checked" />
                            <span class="format-details">
                                <strong><?php esc_html_e('CSV', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Best for Excel and spreadsheet applications', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                        
                        <label class="format-option">
                            <input type="radio" name="export_format" value="json" />
                            <span class="format-details">
                                <strong><?php esc_html_e('JSON', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Best for web applications and data processing', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                        
                        <label class="format-option">
                            <input type="radio" name="export_format" value="xml" />
                            <span class="format-details">
                                <strong><?php esc_html_e('XML', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Best for data exchange between systems', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="export-content-section">
                    <label class="section-label"><?php esc_html_e('What to Include:', 'bp-export-import'); ?></label>
                    <div class="content-options">
                        <label class="content-option">
                            <input type="checkbox" name="include_basic" value="1" checked="checked" disabled />
                            <span class="option-details">
                                <strong><?php esc_html_e('Basic Information', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Username, email, display name, registration date', 'bp-export-import'); ?></em>
                            </span>
                        </label>

                        <?php if (bp_is_active('xprofile')) : ?>
                        <label class="content-option">
                            <input type="checkbox" name="include_profile" value="1" checked="checked" disabled />
                            <span class="option-details">
                                <strong><?php esc_html_e('Profile Fields', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('All your BuddyPress profile field data', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                        <?php endif; ?>

                        <label class="content-option">
                            <input type="checkbox" name="include_meta" value="1" />
                            <span class="option-details">
                                <strong><?php esc_html_e('Additional Data', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Settings and preferences (privacy-filtered)', 'bp-export-import'); ?></em>
                            </span>
                        </label>

                        <?php if (bp_is_active('activity')) : ?>
                        <label class="content-option">
                            <input type="checkbox" name="include_activity" value="1" />
                            <span class="option-details">
                                <strong><?php esc_html_e('Recent Activity', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Your last 20 activity updates', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                        <?php endif; ?>

                        <?php if (bp_is_active('friends')) : ?>
                        <label class="content-option">
                            <input type="checkbox" name="include_friends" value="1" />
                            <span class="option-details">
                                <strong><?php esc_html_e('Friends List', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('List of your friends and connection dates', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                        <?php endif; ?>

                        <?php if (bp_is_active('groups')) : ?>
                        <label class="content-option">
                            <input type="checkbox" name="include_groups" value="1" />
                            <span class="option-details">
                                <strong><?php esc_html_e('Groups', 'bp-export-import'); ?></strong>
                                <em><?php esc_html_e('Groups you belong to and membership dates', 'bp-export-import'); ?></em>
                            </span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Privacy Notice -->
                <div class="privacy-notice">
                    <h5><?php esc_html_e('Privacy Information', 'bp-export-import'); ?></h5>
                    <p><?php esc_html_e('Your exported data will only include information that is already visible to you. Sensitive data like passwords, private messages, or admin-only information will not be included.', 'bp-export-import'); ?></p>
                    <p><?php esc_html_e('The exported file will be available for download immediately and will be automatically deleted after 24 hours for security.', 'bp-export-import'); ?></p>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="export-submit">
                <input type="submit" name="bp_frontend_export" class="button primary" value="<?php esc_attr_e('Export My Data', 'bp-export-import'); ?>" id="export-submit" />
                <span class="spinner" id="export-spinner"></span>
            </div>
        </form>
    </div>

    <!-- Export Progress -->
    <div id="export-progress" class="export-progress" style="display: none;">
        <h4><?php esc_html_e('Preparing Your Export...', 'bp-export-import'); ?></h4>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <p class="progress-text"><?php esc_html_e('Please wait while we gather your data...', 'bp-export-import'); ?></p>
    </div>

    <!-- Export Complete -->
    <div id="export-complete" class="export-complete" style="display: none;">
        <div class="success-icon">✓</div>
        <h4><?php esc_html_e('Export Complete!', 'bp-export-import'); ?></h4>
        <p><?php esc_html_e('Your data has been successfully exported.', 'bp-export-import'); ?></p>
        <a href="#" id="download-link" class="button primary" download><?php esc_html_e('Download Now', 'bp-export-import'); ?></a>
        <p class="download-note"><?php esc_html_e('Note: This download link will expire in 24 hours.', 'bp-export-import'); ?></p>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    $('#bp-frontend-export-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $('#export-submit');
        var $spinner = $('#export-spinner');
        var $progress = $('#export-progress');
        var $complete = $('#export-complete');
        
        // Show loading state
        $submitBtn.prop('disabled', true);
        $spinner.addClass('is-active');
        $form.hide();
        $progress.show();
        
        // Animate progress bar
        var progressFill = $('.progress-fill');
        var width = 0;
        var progressInterval = setInterval(function() {
            width += Math.random() * 15;
            if (width > 85) {
                clearInterval(progressInterval);
                width = 85;
            }
            progressFill.css('width', width + '%');
        }, 500);
        
        // Prepare form data
        var formData = new FormData();
        formData.append('action', 'bp_frontend_export');
        formData.append('nonce', bp_export_import_ajax.nonce);
        
        // Add form fields
        $form.find('input[type="radio"]:checked, input[type="checkbox"]:checked').each(function() {
            formData.append($(this).attr('name'), $(this).val());
        });
        
        // Submit via AJAX
        $.ajax({
            url: bp_export_import_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                clearInterval(progressInterval);
                progressFill.css('width', '100%');
                
                setTimeout(function() {
                    $progress.hide();
                    
                    if (response.success) {
                        $('#download-link').attr('href', response.data.download_url);
                        $complete.show();
                    } else {
                        alert('<?php esc_js_e('Export failed:', 'bp-export-import'); ?> ' + response.data);
                        $form.show();
                        $submitBtn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                }, 1000);
            },
            error: function() {
                clearInterval(progressInterval);
                alert('<?php esc_js_e('Export failed. Please try again.', 'bp-export-import'); ?>');
                $progress.hide();
                $form.show();
                $submitBtn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
});
</script>

<style>
.bp-export-profile-container {
    max-width: 700px;
    margin: 0 auto;
}

.bp-export-intro {
    background: #f9f9f9;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    text-align: center;
}

.bp-export-intro h3 {
    margin-top: 0;
    color: #0073aa;
}

.bp-export-stats {
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.bp-export-stats h4 {
    margin-top: 0;
    margin-bottom: 15px;
}

.export-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
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

.bp-export-form {
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.export-options {
    margin-bottom: 20px;
}

.export-options h4 {
    margin-top: 0;
    margin-bottom: 20px;
    color: #0073aa;
}

.export-format-section,
.export-content-section {
    margin-bottom: 25px;
}

.section-label {
    display: block;
    font-weight: bold;
    margin-bottom: 10px;
    color: #333;
}

.format-options,
.content-options {
    margin-left: 15px;
}

.format-option,
.content-option {
    display: block;
    margin-bottom: 12px;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.format-option:hover,
.content-option:hover {
    background: #f0f8ff;
}

.format-option input,
.content-option input {
    margin-right: 10px;
}

.format-details,
.option-details {
    display: block;
}

.format-details strong,
.option-details strong {
    display: block;
    margin-bottom: 3px;
    color: #333;
}

.format-details em,
.option-details em {
    font-size: 13px;
    color: #666;
}

.privacy-notice {
    background: #e7f5ff;
    border: 1px solid #b8e0ff;
    border-radius: 4px;
    padding: 15px;
    margin-top: 20px;
}

.privacy-notice h5 {
    margin-top: 0;
    margin-bottom: 10px;
    color: #0073aa;
}

.privacy-notice p {
    margin-bottom: 10px;
    font-size: 14px;
    line-height: 1.5;
}

.privacy-notice p:last-child {
    margin-bottom: 0;
}

.export-submit {
    text-align: center;
    padding-top: 20px;
    border-top: 1px solid #e1e1e1;
}

.export-submit .button {
    padding: 12px 30px;
    font-size: 16px;
    min-width: 180px;
}

.spinner {
    float: none;
    margin-left: 10px;
}

.export-progress,
.export-complete {
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
    padding: 30px;
    text-align: center;
    margin-bottom: 20px;
}

.export-progress h4,
.export-complete h4 {
    margin-top: 0;
    color: #0073aa;
}

.progress-bar {
    width: 100%;
    height: 20px;
    background: #e1e1e1;
    border-radius: 10px;
    overflow: hidden;
    margin: 20px 0;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #005a87);
    width: 0%;
    transition: width 0.3s ease;
}

.progress-text {
    color: #666;
    font-style: italic;
}

.success-icon {
    font-size: 48px;
    color: #46b450;
    margin-bottom: 15px;
}

.export-complete .button {
    margin: 20px 0 10px 0;
    padding: 12px 30px;
    font-size: 16px;
}

.download-note {
    font-size: 13px;
    color: #666;
    font-style: italic;
}

/* BuddyPress feedback styles compatibility */
.bp-feedback {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.bp-feedback.success {
    background: #f0f8e7;
    border-left: 4px solid #46b450;
    color: #46b450;
}

.bp-feedback.error {
    background: #ffeaea;
    border-left: 4px solid #dc3232;
    color: #dc3232;
}

.bp-feedback .bp-icon {
    margin-right: 8px;
}

.bp-feedback p {
    margin: 0;
}
</style>