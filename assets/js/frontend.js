/**
 * BP Export Import Frontend JavaScript
 * Handles frontend user export functionality
 */

(function($) {
    'use strict';

    // Frontend Export Handler
    window.BPExportImportFrontend = {
        // Configuration
        config: {
            ajaxUrl: bpExportImport?.ajaxUrl || ajaxurl,
            nonce: bpExportImport?.nonce || '',
            strings: bpExportImport?.strings || {}
        },

        // Current operation
        currentOperation: null,
        progressTimer: null,

        // Initialize
        init: function() {
            this.bindEvents();
            this.updateDataPreview();
            this.setupAccessibility();
        },

        // Bind event handlers
        bindEvents: function() {
            const self = this;

            // Checkbox dependencies
            $('input[name="include_meta"], input[name="include_activity"]').on('change', function() {
                self.updateDataPreview();
            });

            // Form submission
            $('#bp-frontend-export-form').on('submit', function(e) {
                e.preventDefault();
                self.handleExportSubmission($(this));
            });

            // Modal controls
            $('#bp-close-modal').on('click', function() {
                self.closeModal();
            });

            // Close modal on overlay click
            $('.bp-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });

            // Escape key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && $('.bp-modal-overlay:visible').length) {
                    self.closeModal();
                }
            });

            // Download link click tracking
            $(document).on('click', '#bp-download-link', function() {
                self.trackDownload();
            });

            // Format change handler
            $('#export_format').on('change', function() {
                self.updateFormatDescription($(this).val());
            });
        },

        // Update data preview based on checkbox states
        updateDataPreview: function() {
            const includeMetaChecked = $('input[name="include_meta"]').is(':checked');
            const includeActivityChecked = $('input[name="include_activity"]').is(':checked');

            $('.meta-dependent').css('opacity', includeMetaChecked ? 1 : 0.5);
            $('.activity-dependent').css('opacity', includeActivityChecked ? 1 : 0.5);

            // Update accessibility attributes
            $('.meta-dependent').attr('aria-disabled', !includeMetaChecked);
            $('.activity-dependent').attr('aria-disabled', !includeActivityChecked);
        },

        // Update format description
        updateFormatDescription: function(format) {
            const descriptions = {
                'csv': 'Spreadsheet format compatible with Excel and Google Sheets',
                'json': 'Structured data format ideal for developers and data analysis',
                'xml': 'Markup format suitable for data exchange and archival'
            };

            const $description = $('#export_format').siblings('.description');
            if ($description.length) {
                $description.text(descriptions[format] || '');
            }
        },

        // Handle export form submission
        handleExportSubmission: function($form) {
            const self = this;

            // Validate form
            if (!this.validateForm($form)) {
                return;
            }

            // Show confirmation if required
            if (!this.confirmExport()) {
                return;
            }

            // Prepare form data
            const formData = this.prepareFormData($form);

            // Show progress modal
            this.showProgressModal();

            // Start export
            this.startExport(formData);
        },

        // Validate export form
        validateForm: function($form) {
            let isValid = true;
            const errors = [];

            // Check format selection
            const format = $('#export_format').val();
            if (!format || !['csv', 'json', 'xml'].includes(format)) {
                errors.push('Please select a valid export format.');
                isValid = false;
            }

            // Show errors if any
            if (!isValid) {
                this.showFormErrors(errors);
            }

            return isValid;
        },

        // Show form validation errors
        showFormErrors: function(errors) {
            // Remove existing error messages
            $('.bp-error-message').remove();

            // Add new error messages
            const errorHtml = '<div class="bp-error-message">' + 
                             '<strong>Please correct the following errors:</strong><ul><li>' + 
                             errors.join('</li><li>') + '</li></ul></div>';

            $('#bp-frontend-export-form').prepend(errorHtml);

            // Scroll to top
            $('html, body').animate({
                scrollTop: $('#bp-frontend-export-form').offset().top - 50
            }, 500);

            // Focus on first error field
            setTimeout(function() {
                $('#export_format').focus();
            }, 100);
        },

        // Confirm export action
        confirmExport: function() {
            const message = 'Are you sure you want to export your profile data? ' +
                          'This will include all your profile information, activity, and connections.';
            
            return confirm(message);
        },

        // Prepare form data for submission
        prepareFormData: function($form) {
            return {
                action: 'bp_frontend_export',
                nonce: this.config.nonce,
                format: $('#export_format').val(),
                include_meta: $('input[name="include_meta"]').is(':checked'),
                include_activity: $('input[name="include_activity"]').is(':checked')
            };
        },

        // Show progress modal
        showProgressModal: function() {
            $('#bp-export-progress-modal').show().addClass('bp-fade-in');
            $('body').addClass('bp-modal-open');
            
            // Reset modal state
            this.resetModalState();
            
            // Focus management for accessibility
            $('#bp-export-progress-modal').focus();
        },

        // Reset modal to initial state
        resetModalState: function() {
            $('.bp-progress-bar').css('width', '0%');
            $('.bp-progress-percentage').text('0%');
            $('.bp-progress-status').text('Preparing export...');
            $('.bp-progress-container').show();
            $('.bp-export-result').hide();
        },

        // Start export process
        startExport: function(formData) {
            const self = this;

            // Disable form
            this.setFormState(false);

            // Start progress animation
            this.animateProgress();

            // Make AJAX request
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: formData,
                timeout: 300000, // 5 minutes timeout
                success: function(response) {
                    self.handleExportSuccess(response);
                },
                error: function(xhr, status, error) {
                    self.handleExportError(xhr, status, error);
                },
                complete: function() {
                    self.setFormState(true);
                    self.stopProgressAnimation();
                }
            });
        },

        // Animate progress bar
        animateProgress: function() {
            const self = this;
            let progress = 0;
            
            this.progressTimer = setInterval(function() {
                progress += Math.random() * 15;
                progress = Math.min(progress, 85); // Don't go to 100% until complete
                
                self.updateProgress(progress, 'Processing your data...');
            }, 1000);
        },

        // Stop progress animation
        stopProgressAnimation: function() {
            if (this.progressTimer) {
                clearInterval(this.progressTimer);
                this.progressTimer = null;
            }
        },

        // Update progress display
        updateProgress: function(percentage, status) {
            $('.bp-progress-bar').css('width', percentage + '%');
            $('.bp-progress-percentage').text(Math.round(percentage) + '%');
            
            if (status) {
                $('.bp-progress-status').text(status);
            }
        },

        // Handle successful export
        handleExportSuccess: function(response) {
            if (response.success) {
                // Complete progress
                this.updateProgress(100, 'Export completed successfully!');
                
                // Show download section
                setTimeout(() => {
                    this.showDownloadResult(response.data);
                }, 1000);
                
                // Track successful export
                this.trackExportCompletion('success');
                
            } else {
                this.handleExportError(null, 'error', response.data || 'Export failed');
            }
        },

        // Handle export error
        handleExportError: function(xhr, status, error) {
            let errorMessage = 'An error occurred during export. Please try again.';
            
            if (status === 'timeout') {
                errorMessage = 'Export timed out. Your data may be too large. Please try again or contact support.';
            } else if (error && typeof error === 'string') {
                errorMessage = error;
            } else if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                errorMessage = xhr.responseJSON.data;
            }

            // Show error in modal
            $('.bp-progress-container').hide();
            $('.bp-export-result').html(
                '<div class="bp-error-message">' +
                '<strong>Export Failed:</strong><br>' + errorMessage +
                '</div>'
            ).show();

            // Track failed export
            this.trackExportCompletion('error', errorMessage);

            console.error('Export error:', { xhr, status, error });
        },

        // Show download result
        showDownloadResult: function(data) {
            const downloadUrl = data.download_url;
            const message = data.message || 'Your data has been exported successfully!';

            $('#bp-download-link').attr('href', downloadUrl);
            $('.bp-success-message').text(message);
            
            $('.bp-progress-container').hide();
            $('.bp-export-result').show().addClass('bp-slide-up');

            // Auto-focus download button for accessibility
            setTimeout(function() {
                $('#bp-download-link').focus();
            }, 500);
        },

        // Close modal
        closeModal: function() {
            $('#bp-export-progress-modal').removeClass('bp-fade-in').hide();
            $('body').removeClass('bp-modal-open');
            
            // Reset state
            this.stopProgressAnimation();
            this.resetModalState();
            
            // Return focus to trigger element
            $('#bp-export-submit').focus();
        },

        // Set form enabled/disabled state
        setFormState: function(enabled) {
            const $form = $('#bp-frontend-export-form');
            const $submitBtn = $('#bp-export-submit');
            const $loader = $('.bp-ajax-loader');

            if (enabled) {
                $form.removeClass('bp-loading');
                $submitBtn.prop('disabled', false);
                $loader.hide();
            } else {
                $form.addClass('bp-loading');
                $submitBtn.prop('disabled', true);
                $loader.show();
            }
        },

        // Track download action
        trackDownload: function() {
            // Analytics tracking could go here
            console.log('Export download initiated');
            
            // Close modal after a delay
            setTimeout(() => {
                this.closeModal();
            }, 2000);
        },

        // Track export completion
        trackExportCompletion: function(status, error) {
            const data = {
                status: status,
                format: $('#export_format').val(),
                include_meta: $('input[name="include_meta"]').is(':checked'),
                include_activity: $('input[name="include_activity"]').is(':checked'),
                timestamp: new Date().toISOString()
            };

            if (error) {
                data.error = error;
            }

            // Store in localStorage for analytics
            try {
                const exports = JSON.parse(localStorage.getItem('bp_export_history') || '[]');
                exports.push(data);
                
                // Keep only last 10 exports
                if (exports.length > 10) {
                    exports.splice(0, exports.length - 10);
                }
                
                localStorage.setItem('bp_export_history', JSON.stringify(exports));
            } catch (e) {
                console.warn('Could not save export history:', e);
            }

            console.log('Export completed:', data);
        },

        // Setup accessibility features
        setupAccessibility: function() {
            // Add ARIA labels
            $('#export_format').attr('aria-describedby', 'export-format-description');
            $('input[name="include_meta"]').attr('aria-describedby', 'include-meta-description');
            $('input[name="include_activity"]').attr('aria-describedby', 'include-activity-description');

            // Add skip link
            if ($('.bp-skip-link').length === 0) {
                $('#bp-frontend-export-form').prepend(
                    '<a href="#bp-export-submit" class="bp-skip-link">Skip to export button</a>'
                );
            }

            // Improve modal accessibility
            $('#bp-export-progress-modal').attr({
                'role': 'dialog',
                'aria-modal': 'true',
                'aria-labelledby': 'modal-title',
                'aria-describedby': 'modal-description'
            });

            // Add live region for progress updates
            if ($('#bp-progress-live-region').length === 0) {
                $('body').append('<div id="bp-progress-live-region" aria-live="polite" aria-atomic="true" class="bp-hidden"></div>');
            }
        },

        // Announce progress to screen readers
        announceProgress: function(message) {
            $('#bp-progress-live-region').text(message);
        },

        // Handle keyboard navigation in modal
        handleModalKeydown: function(e) {
            const $modal = $('#bp-export-progress-modal');
            const $focusableElements = $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            const $firstElement = $focusableElements.first();
            const $lastElement = $focusableElements.last();

            if (e.keyCode === 9) { // Tab key
                if (e.shiftKey) {
                    if ($(e.target).is($firstElement)) {
                        e.preventDefault();
                        $lastElement.focus();
                    }
                } else {
                    if ($(e.target).is($lastElement)) {
                        e.preventDefault();
                        $firstElement.focus();
                    }
                }
            }
        },

        // Utility: Check if user prefers reduced motion
        prefersReducedMotion: function() {
            return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        // Utility: Format file size
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        // Utility: Debounce function
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    // Enhanced error handling
    window.addEventListener('error', function(e) {
        if (e.filename && e.filename.includes('frontend.js')) {
            console.error('BP Export Import Frontend Error:', e.error);
            
            // Show user-friendly error message
            if ($('#bp-export-progress-modal').is(':visible')) {
                $('.bp-progress-container').hide();
                $('.bp-export-result').html(
                    '<div class="bp-error-message">' +
                    'An unexpected error occurred. Please refresh the page and try again.' +
                    '</div>'
                ).show();
            }
        }
    });

    // Handle unhandled promise rejections
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Unhandled promise rejection in BP Export Import:', e.reason);
    });

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize if we're on the export page
        if ($('#bp-frontend-export-form').length) {
            BPExportImportFrontend.init();
        }
    });

    // Handle page visibility changes (pause/resume timers)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            // Pause any running timers
            if (BPExportImportFrontend.progressTimer) {
                BPExportImportFrontend.stopProgressAnimation();
            }
        } else {
            // Resume if needed
            if ($('#bp-export-progress-modal').is(':visible') && $('.bp-progress-container').is(':visible')) {
                BPExportImportFrontend.animateProgress();
            }
        }
    });

    // Expose for testing purposes
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        window.BPExportImportFrontendDebug = BPExportImportFrontend;
    }

})(jQuery);