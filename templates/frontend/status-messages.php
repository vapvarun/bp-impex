<?php
/**
 * Status messages template for BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display status messages for frontend operations
 * This template can be included in other templates or used standalone
 */

// Get current user ID
$user_id = get_current_user_id();

// Check for URL parameters first (legacy support)
$status_from_url = '';
$message_from_url = '';

if (isset($_GET['bp_export_import_status'])) {
    $status_from_url = sanitize_text_field($_GET['bp_export_import_status']);
}

if (isset($_GET['message'])) {
    $message_from_url = sanitize_text_field($_GET['message']);
}

// Get recent operation status from progress component
$progress = bp_export_import()->get_component('progress');
$recent_operations = array();

if ($progress && $user_id) {
    $recent_operations = $progress->get_operation_history($user_id, 3);
}

// Function to display a status message
function bp_export_import_display_message($type, $message, $dismissible = true) {
    $icon_map = array(
        'success' => '✓',
        'error' => '✗',
        'warning' => '⚠',
        'info' => 'ℹ'
    );
    
    $icon = isset($icon_map[$type]) ? $icon_map[$type] : 'ℹ';
    $dismissible_class = $dismissible ? ' is-dismissible' : '';
    
    echo '<div class="bp-export-import-message ' . esc_attr($type) . $dismissible_class . '" data-type="' . esc_attr($type) . '">';
    echo '<span class="message-icon">' . $icon . '</span>';
    echo '<span class="message-text">' . esc_html($message) . '</span>';
    if ($dismissible) {
        echo '<button type="button" class="message-dismiss">&times;</button>';
    }
    echo '</div>';
}

// Display URL-based status messages (legacy support)
if ($status_from_url) {
    switch ($status_from_url) {
        case 'export_success':
            $message = $message_from_url ?: __('Your data has been successfully exported.', 'bp-export-import');
            bp_export_import_display_message('success', $message);
            break;

        case 'import_success':
            $message = $message_from_url ?: __('Your data has been successfully imported.', 'bp-export-import');
            bp_export_import_display_message('success', $message);
            break;

        case 'export_error':
        case 'import_error':
        case 'error':
            $message = $message_from_url ?: __('There was an error processing your request. Please try again later.', 'bp-export-import');
            bp_export_import_display_message('error', $message);
            break;

        case 'export_started':
            $message = $message_from_url ?: __('Export has been started and will be processed in the background.', 'bp-export-import');
            bp_export_import_display_message('info', $message);
            break;

        case 'import_started':
            $message = $message_from_url ?: __('Import has been started and will be processed in the background.', 'bp-export-import');
            bp_export_import_display_message('info', $message);
            break;

        case 'operation_cancelled':
            $message = $message_from_url ?: __('Operation has been cancelled.', 'bp-export-import');
            bp_export_import_display_message('warning', $message);
            break;

        case 'file_too_large':
            $message = $message_from_url ?: __('The uploaded file is too large. Please try a smaller file.', 'bp-export-import');
            bp_export_import_display_message('error', $message);
            break;

        case 'invalid_file':
            $message = $message_from_url ?: __('The uploaded file format is not supported.', 'bp-export-import');
            bp_export_import_display_message('error', $message);
            break;

        case 'permission_denied':
            $message = $message_from_url ?: __('You do not have permission to perform this action.', 'bp-export-import');
            bp_export_import_display_message('error', $message);
            break;
    }
}

// Display recent operation status messages
if (!empty($recent_operations)) {
    foreach ($recent_operations as $operation) {
        // Only show recent operations (within last hour)
        $operation_time = strtotime($operation['completed_at'] ?: $operation['started_at']);
        $time_diff = current_time('timestamp') - $operation_time;
        
        if ($time_diff > HOUR_IN_SECONDS) {
            continue; // Skip older operations
        }
        
        $operation_type = ucfirst($operation['operation_type']);
        
        switch ($operation['status']) {
            case 'completed':
                $message = sprintf(
                    __('%s completed successfully. %d records processed.', 'bp-export-import'),
                    $operation_type,
                    $operation['processed_records']
                );
                
                if ($operation['error_count'] > 0) {
                    $message .= ' ' . sprintf(
                        __('(%d errors encountered)', 'bp-export-import'),
                        $operation['error_count']
                    );
                }
                
                bp_export_import_display_message('success', $message);
                break;
                
            case 'failed':
                $message = sprintf(
                    __('%s failed. Please check the logs for details.', 'bp-export-import'),
                    $operation_type
                );
                bp_export_import_display_message('error', $message);
                break;
                
            case 'cancelled':
                $message = sprintf(
                    __('%s was cancelled.', 'bp-export-import'),
                    $operation_type
                );
                bp_export_import_display_message('warning', $message);
                break;
                
            case 'running':
                $message = sprintf(
                    __('%s is currently in progress... (%d%% complete)', 'bp-export-import'),
                    $operation_type,
                    $operation['total_records'] > 0 ? round(($operation['processed_records'] / $operation['total_records']) * 100) : 0
                );
                bp_export_import_display_message('info', $message, false);
                break;
        }
    }
}

// Check for active operations and show persistent status
if ($progress && $user_id) {
    $active_operations = $progress->get_active_operations($user_id);
    
    if (!empty($active_operations)) {
        echo '<div class="bp-active-operations" id="active-operations-status">';
        echo '<h4>' . __('Active Operations', 'bp-export-import') . '</h4>';
        
        foreach ($active_operations as $operation_id) {
            $operation_progress = $progress->get_progress($operation_id);
            
            if ($operation_progress) {
                echo '<div class="active-operation" data-operation-id="' . esc_attr($operation_id) . '">';
                echo '<div class="operation-header">';
                echo '<span class="operation-type">' . esc_html(ucfirst($operation_progress['type'])) . '</span>';
                echo '<span class="operation-status">' . esc_html($operation_progress['status']) . '</span>';
                echo '</div>';
                
                $percentage = 0;
                if ($operation_progress['total_records'] > 0) {
                    $percentage = ($operation_progress['processed_records'] / $operation_progress['total_records']) * 100;
                }
                
                echo '<div class="operation-progress">';
                echo '<div class="progress-bar">';
                echo '<div class="progress-fill" style="width: ' . round($percentage) . '%;"></div>';
                echo '</div>';
                echo '<div class="progress-text">';
                echo '<span class="progress-percentage">' . round($percentage) . '%</span>';
                echo '<span class="progress-details">';
                echo sprintf(
                    __('%d of %d records processed', 'bp-export-import'),
                    $operation_progress['processed_records'],
                    $operation_progress['total_records']
                );
                echo '</span>';
                echo '</div>';
                echo '</div>';
                
                if (isset($operation_progress['current_step']) && !empty($operation_progress['current_step'])) {
                    echo '<div class="operation-step">' . esc_html($operation_progress['current_step']) . '</div>';
                }
                
                echo '<div class="operation-actions">';
                echo '<button type="button" class="button small cancel-operation" data-operation-id="' . esc_attr($operation_id) . '">';
                echo __('Cancel', 'bp-export-import');
                echo '</button>';
                echo '</div>';
                
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
}
?>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Auto-dismiss success messages after 5 seconds
    setTimeout(function() {
        $('.bp-export-import-message.success.is-dismissible').fadeOut();
    }, 5000);
    
    // Manual dismiss functionality
    $('.message-dismiss').on('click', function() {
        $(this).closest('.bp-export-import-message').fadeOut();
    });
    
    // Auto-refresh active operations status
    function refreshActiveOperations() {
        var $activeOps = $('#active-operations-status');
        if ($activeOps.length === 0) return;
        
        $('.active-operation').each(function() {
            var operationId = $(this).data('operation-id');
            var $operation = $(this);
            
            $.post(bp_export_import_ajax.ajax_url, {
                action: 'bp_get_progress',
                nonce: bp_export_import_ajax.nonce,
                operation_id: operationId
            }, function(response) {
                if (response.success) {
                    var progress = response.data;
                    var percentage = 0;
                    
                    if (progress.total_records > 0) {
                        percentage = (progress.processed_records / progress.total_records) * 100;
                    }
                    
                    // Update progress bar
                    $operation.find('.progress-fill').css('width', Math.round(percentage) + '%');
                    $operation.find('.progress-percentage').text(Math.round(percentage) + '%');
                    $operation.find('.progress-details').text(
                        progress.processed_records + ' <?php esc_js_e('of', 'bp-export-import'); ?> ' + 
                        progress.total_records + ' <?php esc_js_e('records processed', 'bp-export-import'); ?>'
                    );
                    
                    // Update status
                    $operation.find('.operation-status').text(progress.status);
                    
                    // Update current step if available
                    if (progress.current_step) {
                        $operation.find('.operation-step').text(progress.current_step);
                    }
                    
                    // If operation is complete, reload page to show final message
                    if (progress.status === 'completed' || progress.status === 'failed' || progress.status === 'cancelled') {
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                }
            });
        });
    }
    
    // Poll active operations every 3 seconds
    if ($('#active-operations-status').length > 0) {
        setInterval(refreshActiveOperations, 3000);
    }
    
    // Cancel operation functionality
    $('.cancel-operation').on('click', function() {
        if (!confirm('<?php esc_js_e('Are you sure you want to cancel this operation?', 'bp-export-import'); ?>')) {
            return;
        }
        
        var operationId = $(this).data('operation-id');
        var $button = $(this);
        
        $button.prop('disabled', true).text('<?php esc_js_e('Cancelling...', 'bp-export-import'); ?>');
        
        $.post(bp_export_import_ajax.ajax_url, {
            action: 'bp_cancel_operation',
            nonce: bp_export_import_ajax.nonce,
            operation_id: operationId
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('<?php esc_js_e('Failed to cancel operation:', 'bp-export-import'); ?> ' + response.data);
                $button.prop('disabled', false).text('<?php esc_js_e('Cancel', 'bp-export-import'); ?>');
            }
        });
    });
});
</script>

<style>
.bp-export-import-message {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    margin: 10px 0;
    border-radius: 4px;
    border-left: 4px solid;
    position: relative;
    font-size: 14px;
}

.bp-export-import-message.success {
    background: #f0f8e7;
    border-left-color: #46b450;
    color: #2e7d32;
}

.bp-export-import-message.error {
    background: #ffeaea;
    border-left-color: #dc3232;
    color: #c62828;
}

.bp-export-import-message.warning {
    background: #fff8e1;
    border-left-color: #ffb900;
    color: #f57c00;
}

.bp-export-import-message.info {
    background: #e7f5ff;
    border-left-color: #0073aa;
    color: #1565c0;
}

.message-icon {
    font-size: 16px;
    font-weight: bold;
    margin-right: 10px;
    min-width: 20px;
}

.message-text {
    flex: 1;
    line-height: 1.4;
}

.message-dismiss {
    background: none;
    border: none;
    font-size: 18px;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
    margin-left: 10px;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.message-dismiss:hover {
    opacity: 1;
}

.bp-active-operations {
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.bp-active-operations h4 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #0073aa;
}

.active-operation {
    border: 1px solid #e1e1e1;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 15px;
    background: #f9f9f9;
}

.active-operation:last-child {
    margin-bottom: 0;
}

.operation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.operation-type {
    font-weight: bold;
    color: #0073aa;
}

.operation-status {
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 3px;
    background: #fff8e1;
    color: #f57c00;
    text-transform: uppercase;
}

.operation-progress {
    margin-bottom: 10px;
}

.progress-bar {
    width: 100%;
    height: 16px;
    background: #e1e1e1;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #005a87);
    transition: width 0.3s ease;
}

.progress-text {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #666;
}

.progress-percentage {
    font-weight: bold;
}

.operation-step {
    font-size: 12px;
    color: #666;
    font-style: italic;
    margin-bottom: 10px;
}

.operation-actions {
    text-align: right;
}

.operation-actions .button {
    padding: 4px 12px;
    font-size: 12px;
}

/* Animation for new messages */
.bp-export-import-message {
    animation: slideInFromTop 0.3s ease-out;
}

@keyframes slideInFromTop {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive design */
@media (max-width: 600px) {
    .operation-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .operation-status {
        margin-top: 5px;
    }
    
    .progress-text {
        flex-direction: column;
        gap: 3px;
    }
}
</style>