<?php
/**
 * Progress tracker template for BP Export Import plugin - FIXED VERSION
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get plugin components and ensure hooks are set up
$progress = bp_export_import()->get_component('progress');

// Set up AJAX hooks only when on this page
if ($progress) {
    $progress->setup_ajax_hooks();
}

// Get current user ID for filtering (admins can see all operations)
$user_id = current_user_can('manage_options') ? 0 : get_current_user_id();

// Get active operations
$active_operations = array();
$recent_operations = array();

if ($progress) {
    $active_operations = $progress->get_active_operations($user_id);
    $recent_operations = $progress->get_operation_history($user_id, 10);
}

// Filter recent operations to only show completed ones from last 24 hours
$recent_operations = array_filter($recent_operations, function($operation) {
    $completed_time = strtotime($operation['completed_at'] ?: $operation['started_at']);
    return $completed_time > (time() - DAY_IN_SECONDS);
});

// Handle operation actions
if (isset($_POST['cancel_operation']) && check_admin_referer('bp_export_import_progress_nonce', '_wpnonce_bp_export_import_progress')) {
    $operation_id = sanitize_text_field($_POST['operation_id']);
    
    if ($progress && $progress->cancel_operation($operation_id)) {
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Operation cancelled successfully.', 'bp-export-import') . '</p></div>';
        // Refresh to update the display
        echo '<script>setTimeout(function(){ location.reload(); }, 1000);</script>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . __('Failed to cancel operation.', 'bp-export-import') . '</p></div>';
    }
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Progress Tracker', 'bp-export-import'); ?></h1>
    <p><?php esc_html_e('Track the progress of your ongoing and recent import/export operations.', 'bp-export-import'); ?></p>

    <div class="bp-progress-tracker-container">
        
        <!-- Active Operations Section -->
        <?php if (!empty($active_operations)) : ?>
        <div class="active-operations-section">
            <h2><?php esc_html_e('Active Operations', 'bp-export-import'); ?></h2>
            <p class="description"><?php esc_html_e('Operations currently in progress. These will update automatically.', 'bp-export-import'); ?></p>
            
            <div class="active-operations-grid">
                <?php foreach ($active_operations as $operation_id) : ?>
                    <?php 
                    $operation_progress = $progress->get_progress($operation_id);
                    if (!$operation_progress) continue;
                    
                    // Calculate percentage
                    $percentage = 0;
                    if ($operation_progress['total_records'] > 0) {
                        $percentage = ($operation_progress['processed_records'] / $operation_progress['total_records']) * 100;
                    }
                    
                    // Estimate time remaining
                    $eta = isset($operation_progress['eta']) ? $operation_progress['eta'] : '';
                    $elapsed = isset($operation_progress['start_time']) ? 
                        human_time_diff($operation_progress['start_time'], current_time('timestamp')) : '';
                    ?>
                    
                    <div class="operation-card active-operation" data-operation-id="<?php echo esc_attr($operation_id); ?>">
                        <div class="operation-header">
                            <div class="operation-info">
                                <h3 class="operation-title">
                                    <?php echo esc_html(ucfirst($operation_progress['type'])); ?>
                                    <span class="operation-id">#<?php echo esc_html(substr($operation_id, -8)); ?></span>
                                </h3>
                                <div class="operation-meta">
                                    <span class="operation-status status-<?php echo esc_attr($operation_progress['status']); ?>">
                                        <?php echo esc_html(ucfirst($operation_progress['status'])); ?>
                                    </span>
                                    <?php if ($elapsed) : ?>
                                    <span class="operation-elapsed"><?php printf(__('Running for %s', 'bp-export-import'), $elapsed); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="operation-actions">
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('bp_export_import_progress_nonce', '_wpnonce_bp_export_import_progress'); ?>
                                    <input type="hidden" name="operation_id" value="<?php echo esc_attr($operation_id); ?>" />
                                    <button type="submit" name="cancel_operation" class="button button-small cancel-btn" 
                                            onclick="return confirm('<?php esc_attr_e('Are you sure you want to cancel this operation?', 'bp-export-import'); ?>')">
                                        <?php esc_html_e('Cancel', 'bp-export-import'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="progress-section">
                            <div class="progress-bar-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo round($percentage); ?>%;"></div>
                                </div>
                                <div class="progress-stats">
                                    <span class="progress-percentage"><?php echo round($percentage); ?>%</span>
                                    <span class="progress-records">
                                        <?php printf(
                                            __('%s of %s records', 'bp-export-import'),
                                            number_format($operation_progress['processed_records']),
                                            number_format($operation_progress['total_records'])
                                        ); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (isset($operation_progress['current_step']) && !empty($operation_progress['current_step'])) : ?>
                            <div class="current-step">
                                <strong><?php esc_html_e('Current Step:', 'bp-export-import'); ?></strong>
                                <span class="step-text"><?php echo esc_html($operation_progress['current_step']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($operation_progress['errors']) && $operation_progress['error_count'] > 0) : ?>
                            <div class="operation-errors">
                                <strong><?php esc_html_e('Errors:', 'bp-export-import'); ?></strong>
                                <span class="error-count"><?php echo esc_html($operation_progress['error_count']); ?></span>
                                <button type="button" class="button-link show-errors" data-operation-id="<?php echo esc_attr($operation_id); ?>">
                                    <?php esc_html_e('Show Details', 'bp-export-import'); ?>
                                </button>
                                
                                <div class="error-details" style="display: none;">
                                    <ul>
                                        <?php foreach (array_slice($operation_progress['errors'], -5) as $error) : ?>
                                        <li><?php echo esc_html($error); ?></li>
                                        <?php endforeach; ?>
                                        <?php if (count($operation_progress['errors']) > 5) : ?>
                                        <li><em><?php printf(__('... and %d more errors', 'bp-export-import'), count($operation_progress['errors']) - 5); ?></em></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php else : ?>
        
        <!-- No Active Operations -->
        <div class="no-active-operations">
            <div class="no-operations-icon">📊</div>
            <h2><?php esc_html_e('No Active Operations', 'bp-export-import'); ?></h2>
            <p><?php esc_html_e('There are currently no import or export operations running.', 'bp-export-import'); ?></p>
            <div class="quick-actions">
                <a href="<?php echo admin_url('tools.php?page=bp-export-import'); ?>" class="button button-primary">
                    <?php esc_html_e('Start Export', 'bp-export-import'); ?>
                </a>
                <a href="<?php echo admin_url('tools.php?page=bp-export-import-import'); ?>" class="button">
                    <?php esc_html_e('Start Import', 'bp-export-import'); ?>
                </a>
            </div>
        </div>
        
        <?php endif; ?>

        <!-- Recent Operations Section -->
        <?php if (!empty($recent_operations)) : ?>
        <div class="recent-operations-section">
            <h2><?php esc_html_e('Recent Operations (Last 24 Hours)', 'bp-export-import'); ?></h2>
            
            <div class="operations-table-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="column-type"><?php esc_html_e('Type', 'bp-export-import'); ?></th>
                            <th class="column-status"><?php esc_html_e('Status', 'bp-export-import'); ?></th>
                            <th class="column-records"><?php esc_html_e('Records', 'bp-export-import'); ?></th>
                            <th class="column-errors"><?php esc_html_e('Errors', 'bp-export-import'); ?></th>
                            <th class="column-duration"><?php esc_html_e('Duration', 'bp-export-import'); ?></th>
                            <th class="column-completed"><?php esc_html_e('Completed', 'bp-export-import'); ?></th>
                            <?php if (current_user_can('manage_options')) : ?>
                            <th class="column-user"><?php esc_html_e('User', 'bp-export-import'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_operations as $operation) : ?>
                        <tr>
                            <td class="column-type">
                                <span class="operation-type operation-<?php echo esc_attr($operation['operation_type']); ?>">
                                    <?php echo esc_html(ucfirst($operation['operation_type'])); ?>
                                </span>
                            </td>
                            <td class="column-status">
                                <span class="operation-status status-<?php echo esc_attr($operation['status']); ?>">
                                    <?php echo esc_html(ucfirst($operation['status'])); ?>
                                </span>
                            </td>
                            <td class="column-records">
                                <?php echo esc_html($operation['processed_records']) . '/' . esc_html($operation['total_records']); ?>
                                <?php if ($operation['total_records'] > 0) : ?>
                                    <small>(<?php echo round(($operation['processed_records'] / $operation['total_records']) * 100); ?>%)</small>
                                <?php endif; ?>
                            </td>
                            <td class="column-errors">
                                <?php if ($operation['error_count'] > 0) : ?>
                                    <span class="error-count"><?php echo esc_html($operation['error_count']); ?></span>
                                <?php else : ?>
                                    <span class="no-errors">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="column-duration">
                                <?php 
                                if ($operation['completed_at']) {
                                    $duration = strtotime($operation['completed_at']) - strtotime($operation['started_at']);
                                    echo esc_html(human_time_diff(0, $duration));
                                } else {
                                    echo '<em>' . esc_html__('N/A', 'bp-export-import') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-completed">
                                <?php if ($operation['completed_at']) : ?>
                                    <?php echo esc_html(human_time_diff(strtotime($operation['completed_at']), current_time('timestamp'))) . ' ' . __('ago', 'bp-export-import'); ?>
                                <?php else : ?>
                                    <em><?php esc_html_e('Not completed', 'bp-export-import'); ?></em>
                                <?php endif; ?>
                            </td>
                            <?php if (current_user_can('manage_options')) : ?>
                            <td class="column-user">
                                <?php 
                                $user = get_userdata($operation['user_id']);
                                echo $user ? esc_html($user->display_name) : esc_html__('Unknown', 'bp-export-import');
                                ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Progress Statistics -->
        <div class="progress-stats-section">
            <h2><?php esc_html_e('Operation Statistics', 'bp-export-import'); ?></h2>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($active_operations); ?></div>
                    <div class="stat-label"><?php esc_html_e('Active Operations', 'bp-export-import'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($recent_operations); ?></div>
                    <div class="stat-label"><?php esc_html_e('Completed Today', 'bp-export-import'); ?></div>
                </div>
                
                <?php 
                $success_count = array_reduce($recent_operations, function($carry, $op) {
                    return $carry + ($op['status'] === 'completed' ? 1 : 0);
                }, 0);
                $success_rate = count($recent_operations) > 0 ? round(($success_count / count($recent_operations)) * 100) : 0;
                ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $success_rate; ?>%</div>
                    <div class="stat-label"><?php esc_html_e('Success Rate', 'bp-export-import'); ?></div>
                </div>
                
                <?php
                $total_processed = array_reduce($recent_operations, function($carry, $op) {
                    return $carry + $op['processed_records'];
                }, 0);
                ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_processed); ?></div>
                    <div class="stat-label"><?php esc_html_e('Records Processed', 'bp-export-import'); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var activeOperationIds = <?php echo json_encode($active_operations); ?>;
    
    // Auto-refresh active operations every 3 seconds
    if (activeOperationIds.length > 0) {
        var refreshInterval = setInterval(function() {
            refreshActiveOperations();
        }, 3000);
        
        // Stop refreshing when there are no more active operations
        function checkAndStopRefresh() {
            if ($('.active-operation').length === 0) {
                clearInterval(refreshInterval);
                location.reload(); // Reload to show final results
            }
        }
    }
    
    function refreshActiveOperations() {
        $('.active-operation').each(function() {
            var $operation = $(this);
            var operationId = $operation.data('operation-id');
            
            $.post(ajaxurl, {
                action: 'bp_get_progress',
                nonce: '<?php echo wp_create_nonce('bp_export_import_ajax'); ?>',
                operation_id: operationId
            }, function(response) {
                if (response.success) {
                    updateOperationDisplay($operation, response.data);
                    
                    // Check if operation is complete
                    if (response.data.status !== 'running' && response.data.status !== 'paused') {
                        setTimeout(function() {
                            $operation.fadeOut(function() {
                                $(this).remove();
                                checkAndStopRefresh();
                            });
                        }, 2000);
                    }
                }
            });
        });
    }
    
    function updateOperationDisplay($operation, progressData) {
        var percentage = 0;
        if (progressData.total_records > 0) {
            percentage = (progressData.processed_records / progressData.total_records) * 100;
        }
        
        // Update progress bar
        $operation.find('.progress-fill').css('width', Math.round(percentage) + '%');
        $operation.find('.progress-percentage').text(Math.round(percentage) + '%');
        
        // Update records count
        $operation.find('.progress-records').text(
            progressData.processed_records.toLocaleString() + ' <?php esc_js_e('of', 'bp-export-import'); ?> ' + 
            progressData.total_records.toLocaleString() + ' <?php esc_js_e('records', 'bp-export-import'); ?>'
        );
        
        // Update status
        $operation.find('.operation-status')
            .removeClass('status-running status-paused status-completed status-failed status-cancelled')
            .addClass('status-' + progressData.status)
            .text(progressData.status.charAt(0).toUpperCase() + progressData.status.slice(1));
        
        // Update current step
        if (progressData.current_step) {
            var $stepContainer = $operation.find('.current-step');
            if ($stepContainer.length === 0) {
                $operation.find('.progress-section').append(
                    '<div class="current-step"><strong><?php esc_js_e('Current Step:', 'bp-export-import'); ?></strong> <span class="step-text"></span></div>'
                );
                $stepContainer = $operation.find('.current-step');
            }
            $stepContainer.find('.step-text').text(progressData.current_step);
        }
        
        // Update error count
        if (progressData.error_count > 0) {
            var $errorContainer = $operation.find('.operation-errors');
            if ($errorContainer.length === 0) {
                $operation.find('.progress-section').append(
                    '<div class="operation-errors"><strong><?php esc_js_e('Errors:', 'bp-export-import'); ?></strong> <span class="error-count"></span></div>'
                );
                $errorContainer = $operation.find('.operation-errors');
            }
            $errorContainer.find('.error-count').text(progressData.error_count);
        }
    }
    
    // Show/hide error details
    $(document).on('click', '.show-errors', function() {
        var $details = $(this).siblings('.error-details');
        if ($details.is(':visible')) {
            $details.hide();
            $(this).text('<?php esc_js_e('Show Details', 'bp-export-import'); ?>');
        } else {
            $details.show();
            $(this).text('<?php esc_js_e('Hide Details', 'bp-export-import'); ?>');
        }
    });
    
    // Cancel operation confirmation
    $('.cancel-btn').on('click', function(e) {
        if (!confirm('<?php esc_js_e('Are you sure you want to cancel this operation? This action cannot be undone.', 'bp-export-import'); ?>')) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<style>
.bp-progress-tracker-container {
    max-width: 1200px;
}

.active-operations-section,
.recent-operations-section,
.progress-stats-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.active-operations-section h2,
.recent-operations-section h2,
.progress-stats-section h2 {
    margin-top: 0;
    color: #0073aa;
}

.active-operations-grid {
    display: grid;
    gap: 20px;
    margin-top: 15px;
}

.operation-card {
    border: 1px solid #e1e1e1;
    border-radius: 6px;
    padding: 20px;
    background: #f9f9f9;
}

.operation-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e1e1e1;
}

.operation-title {
    margin: 0 0 5px 0;
    font-size: 18px;
    color: #0073aa;
}

.operation-id {
    font-size: 12px;
    color: #666;
    font-weight: normal;
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: 10px;
}

.operation-meta {
    display: flex;
    gap: 15px;
    font-size: 13px;
}

.operation-status {
    padding: 2px 8px;
    border-radius: 3px;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 11px;
}

.status-running {
    background: #fff8e1;
    color: #f57c00;
}

.status-completed {
    background: #e8f5e8;
    color: #2e7d32;
}

.status-failed {
    background: #ffebee;
    color: #c62828;
}

.no-active-operations {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin-bottom: 20px;
}

.no-operations-icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.quick-actions {
    margin-top: 20px;
}

.quick-actions .button {
    margin: 0 5px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.stat-card {
    text-align: center;
    padding: 20px;
    background: #f9f9f9;
    border: 1px solid #e1e1e1;
    border-radius: 6px;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #0073aa;
    display: block;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 13px;
    color: #666;
    text-transform: uppercase;
}

.progress-section {
    margin-top: 15px;
}

.progress-bar-container {
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

.progress-stats {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #666;
}

.progress-percentage {
    font-weight: bold;
}

.current-step {
    margin-bottom: 10px;
    font-size: 13px;
    color: #666;
}

.operation-errors {
    margin-top: 10px;
    padding: 10px;
    background: #fff3f3;
    border-radius: 4px;
    font-size: 13px;
}

.error-count {
    color: #dc3232;
    font-weight: bold;
}

.show-errors {
    color: #dc3232;
    text-decoration: none;
    margin-left: 10px;
}

.error-details {
    margin-top: 10px;
    padding: 10px;
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
}

.error-details ul {
    margin: 0;
    padding-left: 20px;
}

.error-details li {
    margin-bottom: 5px;
}

.operations-table-container {
    margin-top: 15px;
}

.operation-type {
    text-transform: capitalize;
}

.operation-export {
    color: #0073aa;
}

.operation-import {
    color: #46b450;
}

.no-errors {
    color: #46b450;
}

/* Responsive design */
@media (max-width: 768px) {
    .operation-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .operation-actions {
        margin-top: 10px;
    }
    
    .progress-stats {
        flex-direction: column;
        gap: 5px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
}
</style>