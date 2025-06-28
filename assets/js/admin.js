/**
 * BP Export Import Admin JavaScript
 * Production-ready JavaScript for admin interface
 */

(function($) {
    'use strict';

    // Global object for the plugin
    window.BPExportImport = {
        // Configuration
        config: {
            ajaxUrl: bpExportImport.ajaxUrl,
            nonce: bpExportImport.nonce,
            strings: bpExportImport.strings
        },

        // Current operation tracking
        currentOperation: null,
        progressTimer: null,

        // Initialize the plugin
        init: function() {
            this.initFileUpload();
            this.initFieldSelection();
            this.initFormHandlers();
            this.initProgressTracking();
            this.initModals();
            this.initTooltips();
            
            // Check for active operations on page load
            this.checkActiveOperations();
        },

        // File upload functionality
        initFileUpload: function() {
            const self = this;
            const $uploadArea = $('.bp-file-upload-area');
            const $fileInput = $('#import_file');
            
            if (!$uploadArea.length || !$fileInput.length) return;

            // Drag and drop functionality
            $uploadArea.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('dragover');
            });

            $uploadArea.on('dragleave dragend drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('dragover');
            });

            $uploadArea.on('drop', function(e) {
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    $fileInput[0].files = files;
                    self.handleFileSelect(files[0]);
                }
            });

            // File input change
            $fileInput.on('change', function() {
                const file = this.files[0];
                if (file) {
                    self.handleFileSelect(file);
                }
            });

            // Click to upload
            $uploadArea.on('click', function() {
                $fileInput.click();
            });
        },

        // Handle file selection
        handleFileSelect: function(file) {
            const $uploadArea = $('.bp-file-upload-area');
            const $fileInfo = $('.bp-file-info');
            const $previewBtn = $('#preview-import');
            const $submitBtn = $('#submit-import');
            const $formatSelect = $('#import_format');

            // Update upload area
            $uploadArea.addClass('has-file');
            $uploadArea.find('.bp-file-upload-text').text(file.name);

            // Show file information
            const fileInfo = this.getFileInfo(file);
            this.displayFileInfo(fileInfo);
            $fileInfo.show();

            // Enable buttons
            $previewBtn.prop('disabled', false);
            $submitBtn.prop('disabled', false);

            // Auto-detect format
            const extension = this.getFileExtension(file.name);
            if (['csv', 'json', 'xml'].includes(extension)) {
                $formatSelect.val(extension);
            }

            // Validate file
            this.validateFile(file);
        },

        // Get file information
        getFileInfo: function(file) {
            return {
                name: file.name,
                size: this.formatFileSize(file.size),
                type: file.type || 'Unknown',
                lastModified: new Date(file.lastModified).toLocaleString()
            };
        },

        // Display file information
        displayFileInfo: function(info) {
            const $fileDetails = $('.bp-file-info ul');
            $fileDetails.html(
                '<li><strong>Name:</strong> ' + this.escapeHtml(info.name) + '</li>' +
                '<li><strong>Size:</strong> ' + info.size + '</li>' +
                '<li><strong>Type:</strong> ' + this.escapeHtml(info.type) + '</li>' +
                '<li><strong>Modified:</strong> ' + info.lastModified + '</li>'
            );
        },

        // Validate uploaded file
        validateFile: function(file) {
            const maxSize = 50 * 1024 * 1024; // 50MB
            const allowedTypes = ['csv', 'json', 'xml'];
            const extension = this.getFileExtension(file.name);

            let isValid = true;
            const errors = [];

            // Size validation
            if (file.size > maxSize) {
                errors.push('File size exceeds 50MB limit');
                isValid = false;
            }

            // Type validation
            if (!allowedTypes.includes(extension)) {
                errors.push('Invalid file type. Allowed: CSV, JSON, XML');
                isValid = false;
            }

            // Show validation results
            if (!isValid) {
                this.showValidationErrors(errors);
                $('#preview-import, #submit-import').prop('disabled', true);
            } else {
                this.hideValidationErrors();
            }

            return isValid;
        },

        // Field selection functionality
        initFieldSelection: function() {
            const self = this;

            // Select all checkboxes
            $('.bp-select-all input[type="checkbox"]').on('change', function() {
                const $group = $(this).closest('.bp-field-selection');
                const isChecked = $(this).is(':checked');
                
                $group.find('.bp-field-list input[type="checkbox"]').prop('checked', isChecked);
                self.updateFieldCount($group);
            });

            // Individual field checkboxes
            $('.bp-field-list input[type="checkbox"]').on('change', function() {
                const $group = $(this).closest('.bp-field-selection');
                self.updateFieldCount($group);
                self.updateSelectAllState($group);
            });

            // Initialize counts
            $('.bp-field-selection').each(function() {
                self.updateFieldCount($(this));
            });
        },

        // Update field count display
        updateFieldCount: function($group) {
            const total = $group.find('.bp-field-list input[type="checkbox"]').length;
            const selected = $group.find('.bp-field-list input[type="checkbox"]:checked').length;
            
            let $counter = $group.find('.bp-field-counter');
            if (!$counter.length) {
                $counter = $('<span class="bp-field-counter"></span>');
                $group.find('h3').append(' ').append($counter);
            }
            
            $counter.text('(' + selected + '/' + total + ')');
        },

        // Update select all checkbox state
        updateSelectAllState: function($group) {
            const $selectAll = $group.find('.bp-select-all input[type="checkbox"]');
            const $fields = $group.find('.bp-field-list input[type="checkbox"]');
            const checkedCount = $fields.filter(':checked').length;
            
            if (checkedCount === 0) {
                $selectAll.prop('indeterminate', false).prop('checked', false);
            } else if (checkedCount === $fields.length) {
                $selectAll.prop('indeterminate', false).prop('checked', true);
            } else {
                $selectAll.prop('indeterminate', true);
            }
        },

        // Form submission handlers
        initFormHandlers: function() {
            const self = this;

            // Export form
            $('#bp-export-form').on('submit', function(e) {
                if (!self.validateExportForm()) {
                    e.preventDefault();
                    return false;
                }
                
                if (!confirm(self.config.strings.confirmExport)) {
                    e.preventDefault();
                    return false;
                }

                self.startOperation('export');
            });

            // Import form
            $('#bp-import-form').on('submit', function(e) {
                e.preventDefault();
                
                if (!self.validateImportForm()) {
                    return false;
                }
                
                if (!confirm(self.config.strings.confirmImport)) {
                    return false;
                }

                self.startImport();
            });

            // Preview button
            $('#preview-import').on('click', function() {
                self.previewImport();
            });

            // Background process buttons
            $('#start-background-export').on('click', function() {
                self.startBackgroundOperation('export');
            });

            $('#start-background-import').on('click', function() {
                self.startBackgroundOperation('import');
            });
        },

        // Validate export form
        validateExportForm: function() {
            const $form = $('#bp-export-form');
            let isValid = true;
            const errors = [];

            // Check if at least one field type is selected
            const hasXProfileFields = $form.find('input[name="xprofile_fields[]"]:checked').length > 0;
            const hasMetaFields = $form.find('input[name="user_meta_keys[]"]:checked').length > 0;

            if (!hasXProfileFields && !hasMetaFields) {
                errors.push('Please select at least one field to export');
                isValid = false;
            }

            if (!isValid) {
                this.showFormErrors(errors);
            }

            return isValid;
        },

        // Validate import form
        validateImportForm: function() {
            const $fileInput = $('#import_file');
            let isValid = true;
            const errors = [];

            // Check if file is selected
            if (!$fileInput[0].files || $fileInput[0].files.length === 0) {
                errors.push('Please select a file to import');
                isValid = false;
            } else {
                // Validate the selected file
                const file = $fileInput[0].files[0];
                if (!this.validateFile(file)) {
                    isValid = false;
                }
            }

            if (!isValid) {
                this.showFormErrors(errors);
            }

            return isValid;
        },

        // Start operation tracking
        startOperation: function(type) {
            this.currentOperation = {
                type: type,
                id: 'operation_' + Date.now(),
                startTime: Date.now()
            };

            // Show progress modal for imports
            if (type === 'import') {
                this.showProgressModal();
                this.startProgressTracking();
            }
        },

        // Start import process
        startImport: function() {
            const self = this;
            const $form = $('#bp-import-form');
            const formData = new FormData($form[0]);
            
            formData.append('action', 'bp_import_users');
            formData.append('nonce', this.config.nonce);

            this.startOperation('import');
            this.showProgressModal();

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        self.handleImportSuccess(response.data);
                    } else {
                        self.handleImportError(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    self.handleImportError('Network error: ' + error);
                }
            });
        },

        // Preview import data
        previewImport: function() {
            const self = this;
            const $fileInput = $('#import_file');
            
            if (!$fileInput[0].files || $fileInput[0].files.length === 0) {
                alert('Please select a file first');
                return;
            }

            const file = $fileInput[0].files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                const content = e.target.result;
                const format = $('#import_format').val();
                
                try {
                    const previewData = self.parseFileContent(content, format);
                    self.showPreviewModal(previewData);
                } catch (error) {
                    alert('Error parsing file: ' + error.message);
                }
            };

            reader.readAsText(file);
        },

        // Parse file content for preview
        parseFileContent: function(content, format) {
            switch (format) {
                case 'csv':
                    return this.parseCSV(content);
                case 'json':
                    return JSON.parse(content);
                case 'xml':
                    return this.parseXML(content);
                default:
                    throw new Error('Unsupported format');
            }
        },

        // Parse CSV content
        parseCSV: function(content) {
            const lines = content.split('\n');
            const headers = this.parseCSVLine(lines[0]);
            const data = [];
            
            for (let i = 1; i < Math.min(6, lines.length); i++) {
                if (lines[i].trim()) {
                    const values = this.parseCSVLine(lines[i]);
                    const row = {};
                    headers.forEach((header, index) => {
                        row[header] = values[index] || '';
                    });
                    data.push(row);
                }
            }
            
            return { 
                headers: headers, 
                data: data, 
                total: lines.length - 1 
            };
        },

        // Parse CSV line (simple implementation)
        parseCSVLine: function(line) {
            const result = [];
            let current = '';
            let inQuotes = false;
            
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                
                if (char === '"') {
                    inQuotes = !inQuotes;
                } else if (char === ',' && !inQuotes) {
                    result.push(current.trim());
                    current = '';
                } else {
                    current += char;
                }
            }
            
            result.push(current.trim());
            return result.map(item => item.replace(/^"|"$/g, ''));
        },

        // Parse XML content
        parseXML: function(content) {
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(content, "text/xml");
            const users = xmlDoc.getElementsByTagName('user');
            const data = [];
            
            for (let i = 0; i < Math.min(5, users.length); i++) {
                const user = users[i];
                const userData = {};
                
                for (let j = 0; j < user.children.length; j++) {
                    const child = user.children[j];
                    userData[child.tagName] = child.textContent;
                }
                
                data.push(userData);
            }
            
            return { 
                data: data, 
                total: users.length 
            };
        },

        // Progress tracking
        initProgressTracking: function() {
            // Initialize progress tracking components
            this.setupProgressModal();
        },

        // Setup progress modal
        setupProgressModal: function() {
            if ($('#bp-progress-modal').length) return;

            const modalHtml = `
                <div id="bp-progress-modal" class="bp-modal-overlay" style="display: none;">
                    <div class="bp-modal-content">
                        <div class="bp-modal-header">
                            <h3>Operation in Progress</h3>
                        </div>
                        <div class="bp-modal-body">
                            <div class="bp-progress-container">
                                <div class="bp-progress-bar-container">
                                    <div class="bp-progress-bar" style="width: 0%;"></div>
                                </div>
                                <div class="bp-progress-text">
                                    <span class="bp-progress-percentage">0%</span> - 
                                    <span class="bp-progress-status">Starting...</span>
                                </div>
                                <div class="bp-progress-details">
                                    <span class="bp-processed-count">0</span> / 
                                    <span class="bp-total-count">0</span> records processed
                                </div>
                                <div class="bp-progress-eta"></div>
                            </div>
                            <div class="bp-progress-errors" style="display: none;">
                                <h4>Errors:</h4>
                                <div class="bp-error-list"></div>
                            </div>
                        </div>
                        <div class="bp-modal-footer">
                            <button type="button" class="button" id="cancel-operation">Cancel</button>
                            <button type="button" class="button button-primary" id="close-progress" style="display: none;">Close</button>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);

            // Cancel operation
            $('#cancel-operation').on('click', () => {
                this.cancelOperation();
            });

            // Close modal
            $('#close-progress').on('click', () => {
                this.hideProgressModal();
            });
        },

        // Show progress modal
        showProgressModal: function() {
            $('#bp-progress-modal').show();
        },

        // Hide progress modal
        hideProgressModal: function() {
            $('#bp-progress-modal').hide();
            this.stopProgressTracking();
        },

        // Start progress tracking
        startProgressTracking: function() {
            if (!this.currentOperation) return;

            const self = this;
            this.progressTimer = setInterval(function() {
                self.updateProgress();
            }, 2000); // Update every 2 seconds
        },

        // Stop progress tracking
        stopProgressTracking: function() {
            if (this.progressTimer) {
                clearInterval(this.progressTimer);
                this.progressTimer = null;
            }
        },

        // Update progress
        updateProgress: function() {
            if (!this.currentOperation) return;

            const self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bp_' + this.currentOperation.type + '_progress',
                    operation_id: this.currentOperation.id,
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.displayProgress(response.data);
                        
                        // Check if operation is complete
                        if (['completed', 'failed', 'cancelled'].includes(response.data.status)) {
                            self.stopProgressTracking();
                            self.showOperationComplete(response.data);
                        }
                    }
                },
                error: function() {
                    // Continue trying to get progress
                }
            });
        },

        // Display progress information
        displayProgress: function(data) {
            const $modal = $('#bp-progress-modal');
            
            $modal.find('.bp-progress-bar').css('width', data.percentage + '%');
            $modal.find('.bp-progress-percentage').text(data.percentage + '%');
            $modal.find('.bp-progress-status').text(data.current_step || 'Processing...');
            $modal.find('.bp-processed-count').text(data.processed);
            $modal.find('.bp-total-count').text(data.total);
            
            if (data.eta) {
                $modal.find('.bp-progress-eta').text('ETA: ' + data.eta).show();
            }
            
            if (data.errors > 0) {
                $modal.find('.bp-progress-errors').show();
                
                if (data.recent_errors) {
                    const errorHtml = data.recent_errors.map(error => 
                        '<div class="bp-error-item error">' + this.escapeHtml(error) + '</div>'
                    ).join('');
                    $modal.find('.bp-error-list').html(errorHtml);
                }
            }
        },

        // Show operation complete
        showOperationComplete: function(data) {
            const $modal = $('#bp-progress-modal');
            
            $modal.find('.bp-modal-header h3').text('Operation Complete');
            $modal.find('#cancel-operation').hide();
            $modal.find('#close-progress').show();
            
            if (data.status === 'completed') {
                this.showSuccess('Operation completed successfully!');
            } else if (data.status === 'failed') {
                this.showError('Operation failed. Please check the error log.');
            } else if (data.status === 'cancelled') {
                this.showWarning('Operation was cancelled.');
            }
        },

        // Modal functionality
        initModals: function() {
            const self = this;

            // Close modal on overlay click
            $(document).on('click', '.bp-modal-overlay', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Close modal on close button click
            $(document).on('click', '.bp-modal-close', function() {
                $(this).closest('.bp-modal-overlay').hide();
            });

            // Escape key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) { // Escape key
                    $('.bp-modal-overlay:visible').hide();
                }
            });
        },

        // Show preview modal
        showPreviewModal: function(previewData) {
            const modalHtml = this.buildPreviewModalHtml(previewData);
            
            // Remove existing preview modal
            $('#bp-preview-modal').remove();
            
            // Add new modal
            $('body').append(modalHtml);
            $('#bp-preview-modal').show();
        },

        // Build preview modal HTML
        buildPreviewModalHtml: function(data) {
            let contentHtml = '<p><strong>Total records:</strong> ' + data.total + '</p>';
            
            if (data.data && data.data.length > 0) {
                contentHtml += '<p><strong>Preview (first 5 records):</strong></p>';
                contentHtml += '<div style="max-height: 400px; overflow: auto;">';
                contentHtml += this.buildPreviewTable(data.data);
                contentHtml += '</div>';
            }
            
            return `
                <div id="bp-preview-modal" class="bp-modal-overlay">
                    <div class="bp-modal-content">
                        <div class="bp-modal-header">
                            <h3>Import Preview</h3>
                            <button type="button" class="bp-modal-close">&times;</button>
                        </div>
                        <div class="bp-modal-body">
                            ${contentHtml}
                        </div>
                        <div class="bp-modal-footer">
                            <button type="button" class="button bp-modal-close">Close</button>
                        </div>
                    </div>
                </div>
            `;
        },

        // Build preview table
        buildPreviewTable: function(data) {
            if (!data || data.length === 0) return '<p>No data to preview.</p>';
            
            const headers = Object.keys(data[0]);
            let html = '<table class="bp-preview-table">';
            
            // Headers
            html += '<thead><tr>';
            headers.forEach(header => {
                html += '<th>' + this.escapeHtml(header) + '</th>';
            });
            html += '</tr></thead>';
            
            // Data rows
            html += '<tbody>';
            data.forEach(row => {
                html += '<tr>';
                headers.forEach(header => {
                    html += '<td>' + this.escapeHtml(row[header] || '') + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody>';
            
            html += '</table>';
            return html;
        },

        // Tooltip functionality
        initTooltips: function() {
            // Simple tooltip implementation
            $('[data-tooltip]').on('mouseenter', function() {
                const tooltipText = $(this).data('tooltip');
                const $tooltip = $('<div class="bp-tooltip">' + tooltipText + '</div>');
                
                $('body').append($tooltip);
                
                const offset = $(this).offset();
                $tooltip.css({
                    position: 'absolute',
                    top: offset.top - $tooltip.outerHeight() - 5,
                    left: offset.left + ($(this).outerWidth() / 2) - ($tooltip.outerWidth() / 2),
                    zIndex: 999999
                });
            });
            
            $('[data-tooltip]').on('mouseleave', function() {
                $('.bp-tooltip').remove();
            });
        },

        // Background operation handlers
        startBackgroundOperation: function(type) {
            const self = this;
            const formData = this.getFormData(type);
            
            formData.append('action', 'bp_export_import_background_process');
            formData.append('action_type', 'start_background_' + type);
            formData.append('nonce', this.config.nonce);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        self.showSuccess(response.data.message);
                        self.currentOperation = {
                            type: type,
                            id: response.data.operation_id,
                            startTime: Date.now()
                        };
                        self.startProgressTracking();
                    } else {
                        self.showError(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    self.showError('Network error: ' + error);
                }
            });
        },

        // Get form data for operation
        getFormData: function(type) {
            const $form = $('#bp-' + type + '-form');
            return new FormData($form[0]);
        },

        // Check for active operations
        checkActiveOperations: function() {
            const self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bp_check_active_operations',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        // Show notification about active operations
                        self.showInfo('You have ' + response.data.length + ' active operation(s) running.');
                        
                        // Start tracking the first active operation
                        if (response.data.length > 0) {
                            self.currentOperation = {
                                type: response.data[0].type,
                                id: response.data[0].id,
                                startTime: Date.now()
                            };
                            self.startProgressTracking();
                        }
                    }
                }
            });
        },

        // Cancel current operation
        cancelOperation: function() {
            if (!this.currentOperation) return;
            
            const self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bp_cancel_operation',
                    operation_id: this.currentOperation.id,
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.stopProgressTracking();
                        self.hideProgressModal();
                        self.showWarning('Operation cancelled.');
                        self.currentOperation = null;
                    }
                }
            });
        },

        // Utility functions
        getFileExtension: function(filename) {
            return filename.split('.').pop().toLowerCase();
        },

        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        // Show validation errors
        showValidationErrors: function(errors) {
            const $container = $('.bp-validation-errors');
            if ($container.length === 0) {
                $('.wrap').prepend('<div class="bp-validation-errors bp-status-message error"></div>');
            }
            
            $('.bp-validation-errors').html(
                '<strong>Validation Error:</strong><ul><li>' + 
                errors.join('</li><li>') + 
                '</li></ul>'
            ).show();
        },

        hideValidationErrors: function() {
            $('.bp-validation-errors').hide();
        },

        // Show form errors
        showFormErrors: function(errors) {
            this.showValidationErrors(errors);
            $('html, body').animate({ scrollTop: 0 }, 500);
        },

        // Import result handlers
        handleImportSuccess: function(data) {
            this.hideProgressModal();
            this.showSuccess('Import completed successfully! ' + data.processed + ' users processed.');
            
            // Refresh page after a delay
            setTimeout(() => {
                location.reload();
            }, 3000);
        },

        handleImportError: function(error) {
            this.hideProgressModal();
            this.showError('Import failed: ' + error);
        },

        // Notification methods
        showSuccess: function(message) {
            this.showNotification(message, 'success');
        },

        showError: function(message) {
            this.showNotification(message, 'error');
        },

        showWarning: function(message) {
            this.showNotification(message, 'warning');
        },

        showInfo: function(message) {
            this.showNotification(message, 'info');
        },

        showNotification: function(message, type) {
            // Remove existing notifications
            $('.bp-notification').remove();
            
            const $notification = $('<div class="bp-notification bp-status-message ' + type + '">' + 
                                   this.escapeHtml(message) + '</div>');
            
            $('.wrap').prepend($notification);
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                $notification.fadeOut(() => {
                    $notification.remove();
                });
            }, 5000);
            
            // Scroll to top
            $('html, body').animate({ scrollTop: 0 }, 500);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BPExportImport.init();
    });

})(jQuery);