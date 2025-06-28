<?php
/**
 * BP Export Import CLI Class
 *
 * Implements WP CLI commands for the BP Export Import plugin
 *
 * @package BP_Export_Import
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Only load if WP CLI is available
if (defined('WP_CLI') && WP_CLI) {

    class BP_Export_Import_CLI {

        /**
         * Plugin components
         */
        private $exporter;
        private $importer;
        private $validator;
        private $logger;
        private $field_mapping;

        /**
         * Constructor
         */
        public function __construct() {
            $this->exporter = new BP_Export_Import_Export();
            $this->importer = new BP_Export_Import_Import();
            $this->validator = new BP_Export_Import_Validator();
            $this->logger = new BP_Export_Import_Logger();
            $this->field_mapping = new BP_Export_Import_Field_Mapping();

            $this->register_commands();
        }

        /**
         * Register WP CLI commands
         */
        private function register_commands() {
            WP_CLI::add_command('bp-export-import export', array($this, 'export_command'));
            WP_CLI::add_command('bp-export-import import', array($this, 'import_command'));
            WP_CLI::add_command('bp-export-import validate', array($this, 'validate_command'));
            WP_CLI::add_command('bp-export-import fields', array($this, 'fields_command'));
            WP_CLI::add_command('bp-export-import status', array($this, 'status_command'));
            WP_CLI::add_command('bp-export-import cleanup', array($this, 'cleanup_command'));
            WP_CLI::add_command('bp-export-import mapping', array($this, 'mapping_command'));
        }

        /**
         * Export users via WP CLI
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : The format of the export file. Options: 'csv', 'json', 'xml'. Default: 'csv'.
         *
         * [--roles=<roles>]
         * : Comma-separated list of roles to filter users by.
         *
         * [--xprofile-fields=<fields>]
         * : Comma-separated list of XProfile fields to include.
         *
         * [--user-meta=<meta>]
         * : Comma-separated list of user meta keys to include.
         *
         * [--date-from=<date>]
         * : Export users registered from this date (YYYY-MM-DD format).
         *
         * [--date-to=<date>]
         * : Export users registered until this date (YYYY-MM-DD format).
         *
         * [--output=<file>]
         * : Output file path. If not specified, outputs to current directory.
         *
         * [--batch-size=<size>]
         * : Number of users to process per batch. Default: 500.
         *
         * ## EXAMPLES
         *
         *     # Basic export
         *     wp bp-export-import export --format=csv
         *
         *     # Export specific roles
         *     wp bp-export-import export --format=json --roles=subscriber,contributor
         *
         *     # Export with custom fields
         *     wp bp-export-import export --xprofile-fields="Name,Location" --user-meta="first_name,last_name"
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function export_command($args, $assoc_args) {
            $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'csv');
            $roles = WP_CLI\Utils\get_flag_value($assoc_args, 'roles', '');
            $xprofile_fields = WP_CLI\Utils\get_flag_value($assoc_args, 'xprofile-fields', '');
            $user_meta = WP_CLI\Utils\get_flag_value($assoc_args, 'user-meta', '');
            $date_from = WP_CLI\Utils\get_flag_value($assoc_args, 'date-from', '');
            $date_to = WP_CLI\Utils\get_flag_value($assoc_args, 'date-to', '');
            $output = WP_CLI\Utils\get_flag_value($assoc_args, 'output', '');
            $batch_size = WP_CLI\Utils\get_flag_value($assoc_args, 'batch-size', 500);

            // Validate format
            if (!in_array($format, array('csv', 'json', 'xml'))) {
                WP_CLI::error("Invalid format. Supported formats: csv, json, xml");
            }

            // Prepare export settings
            $settings = array(
                'export_format' => $format,
                'batch_size' => intval($batch_size)
            );

            if ($roles) {
                $settings['roles'] = array_map('trim', explode(',', $roles));
            }

            if ($xprofile_fields) {
                $settings['xprofile_fields'] = array_map('trim', explode(',', $xprofile_fields));
            }

            if ($user_meta) {
                $settings['user_meta_keys'] = array_map('trim', explode(',', $user_meta));
            }

            if ($date_from) {
                $settings['date_from'] = $date_from;
            }

            if ($date_to) {
                $settings['date_to'] = $date_to;
            }

            // Validate export settings
            $validation_result = $this->validator->validate_export_options($settings);
            if (is_wp_error($validation_result)) {
                WP_CLI::error($validation_result->get_error_message());
            }

            // Get total user count
            $total_users = $this->get_total_user_count($settings);
            WP_CLI::log("Found {$total_users} users to export");

            if ($total_users === 0) {
                WP_CLI::warning("No users found matching the criteria");
                return;
            }

            // Determine output file
            if (empty($output)) {
                $output = 'bp-users-export-' . date('Y-m-d-H-i-s') . '.' . $format;
            }

            // Start export with progress
            $progress = WP_CLI\Utils\make_progress_bar("Exporting users", $total_users);

            try {
                $this->export_users_cli($settings, $output, $progress);
                $progress->finish();

                WP_CLI::success("Export completed successfully: {$output}");
                WP_CLI::log("Total users exported: {$total_users}");

            } catch (Exception $e) {
                $progress->finish();
                WP_CLI::error("Export failed: " . $e->getMessage());
            }
        }

        /**
         * Import users via WP CLI
         *
         * ## OPTIONS
         *
         * <file>
         * : The path to the import file.
         *
         * [--format=<format>]
         * : The format of the import file. Options: 'csv', 'json', 'xml'. Auto-detected if not specified.
         *
         * [--mode=<mode>]
         * : Import mode. Options: 'create-only', 'update-existing', 'create-and-update'. Default: 'create-only'.
         *
         * [--mapping=<file>]
         * : Path to field mapping JSON file.
         *
         * [--batch-size=<size>]
         * : Number of users to process per batch. Default: 100.
         *
         * [--notify-users]
         * : Send welcome emails to newly created users.
         *
         * [--dry-run]
         * : Validate the import without actually creating/updating users.
         *
         * ## EXAMPLES
         *
         *     # Basic import
         *     wp bp-export-import import users.csv --format=csv
         *
         *     # Import with field mapping
         *     wp bp-export-import import users.csv --mapping=mapping.json
         *
         *     # Dry run to validate data
         *     wp bp-export-import import users.csv --dry-run
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function import_command($args, $assoc_args) {
            if (empty($args[0])) {
                WP_CLI::error("Please specify the import file path");
            }

            $file_path = $args[0];
            
            if (!file_exists($file_path)) {
                WP_CLI::error("File not found: {$file_path}");
            }

            $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', '');
            $mode = WP_CLI\Utils\get_flag_value($assoc_args, 'mode', 'create-only');
            $mapping_file = WP_CLI\Utils\get_flag_value($assoc_args, 'mapping', '');
            $batch_size = WP_CLI\Utils\get_flag_value($assoc_args, 'batch-size', 100);
            $notify_users = WP_CLI\Utils\get_flag_value($assoc_args, 'notify-users', false);
            $dry_run = WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

            // Auto-detect format if not specified
            if (empty($format)) {
                $format = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            }

            if (!in_array($format, array('csv', 'json', 'xml'))) {
                WP_CLI::error("Invalid or unsupported format: {$format}");
            }

            // Validate file
            $file_array = array(
                'tmp_name' => $file_path,
                'name' => basename($file_path),
                'size' => filesize($file_path),
                'error' => UPLOAD_ERR_OK
            );

            $validation_result = $this->validator->validate_uploaded_file($file_array);
            if (is_wp_error($validation_result)) {
                WP_CLI::error("File validation failed: " . $validation_result->get_error_message());
            }

            // Load field mapping if specified
            $field_mapping = array();
            if (!empty($mapping_file)) {
                if (!file_exists($mapping_file)) {
                    WP_CLI::error("Mapping file not found: {$mapping_file}");
                }

                $mapping_json = file_get_contents($mapping_file);
                $mapping_data = json_decode($mapping_json, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    WP_CLI::error("Invalid mapping file format");
                }

                $field_mapping = $mapping_data['mapping'] ?? $mapping_data;
            }

            // Count total records
            $total_records = $this->count_file_records($file_path, $format);
            WP_CLI::log("Found {$total_records} records in import file");

            if ($total_records === 0) {
                WP_CLI::warning("No records found in import file");
                return;
            }

            if ($dry_run) {
                WP_CLI::log("Performing dry run validation...");
                $this->validate_import_data($file_path, $format, $field_mapping);
                return;
            }

            // Prepare import settings
            $settings = array(
                'import_format' => $format,
                'import_mode' => $mode,
                'batch_size' => intval($batch_size),
                'send_notification' => $notify_users,
                'field_mapping' => $field_mapping
            );

            // Start import with progress
            $progress = WP_CLI\Utils\make_progress_bar("Importing users", $total_records);

            try {
                $result = $this->import_users_cli($file_path, $settings, $progress);
                $progress->finish();

                WP_CLI::success("Import completed successfully");
                WP_CLI::log("Users processed: {$result['processed']}");
                
                if (!empty($result['errors'])) {
                    WP_CLI::log("Errors encountered: " . count($result['errors']));
                    foreach (array_slice($result['errors'], 0, 10) as $error) {
                        WP_CLI::warning($error);
                    }
                    if (count($result['errors']) > 10) {
                        WP_CLI::log("... and " . (count($result['errors']) - 10) . " more errors");
                    }
                }

            } catch (Exception $e) {
                $progress->finish();
                WP_CLI::error("Import failed: " . $e->getMessage());
            }
        }

        /**
         * Validate import file
         *
         * ## OPTIONS
         *
         * <file>
         * : The path to the file to validate.
         *
         * [--format=<format>]
         * : The format of the file. Auto-detected if not specified.
         *
         * [--detailed]
         * : Show detailed validation results.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import validate users.csv
         *     wp bp-export-import validate users.json --detailed
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function validate_command($args, $assoc_args) {
            if (empty($args[0])) {
                WP_CLI::error("Please specify the file path to validate");
            }

            $file_path = $args[0];
            
            if (!file_exists($file_path)) {
                WP_CLI::error("File not found: {$file_path}");
            }

            $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', '');
            $detailed = WP_CLI\Utils\get_flag_value($assoc_args, 'detailed', false);

            // Auto-detect format if not specified
            if (empty($format)) {
                $format = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            }

            WP_CLI::log("Validating file: {$file_path}");
            WP_CLI::log("Format: {$format}");

            // Basic file validation
            $file_array = array(
                'tmp_name' => $file_path,
                'name' => basename($file_path),
                'size' => filesize($file_path),
                'error' => UPLOAD_ERR_OK
            );

            $validation_result = $this->validator->validate_uploaded_file($file_array);
            if (is_wp_error($validation_result)) {
                WP_CLI::error("File validation failed: " . $validation_result->get_error_message());
            }

            // Content validation
            $content_validation = $this->importer->validate_import_data($file_path, $format);
            
            if ($content_validation['valid']) {
                WP_CLI::success("File validation passed");
            } else {
                WP_CLI::error("File validation failed");
            }

            if ($detailed || !$content_validation['valid']) {
                $this->display_validation_results($content_validation);
            }
        }

        /**
         * List available fields for export/import
         *
         * ## OPTIONS
         *
         * [--type=<type>]
         * : Field type to show. Options: 'all', 'standard', 'xprofile', 'meta'. Default: 'all'.
         *
         * [--format=<format>]
         * : Output format. Options: 'table', 'csv', 'json'. Default: 'table'.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import fields
         *     wp bp-export-import fields --type=xprofile
         *     wp bp-export-import fields --format=json
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function fields_command($args, $assoc_args) {
            $type = WP_CLI\Utils\get_flag_value($assoc_args, 'type', 'all');
            $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

            $all_fields = $this->field_mapping->get_available_bp_fields();
            $display_fields = array();

            foreach ($all_fields as $category => $fields) {
                if ($type === 'all' || $type === $category) {
                    foreach ($fields as $field_key => $field_label) {
                        $display_fields[] = array(
                            'key' => $field_key,
                            'label' => $field_label,
                            'category' => $category
                        );
                    }
                }
            }

            if (empty($display_fields)) {
                WP_CLI::warning("No fields found for type: {$type}");
                return;
            }

            WP_CLI\Utils\format_items($format, $display_fields, array('key', 'label', 'category'));
        }

        /**
         * Check operation status and statistics
         *
         * ## OPTIONS
         *
         * [--recent=<count>]
         * : Number of recent operations to show. Default: 5.
         *
         * [--format=<format>]
         * : Output format. Options: 'table', 'csv', 'json'. Default: 'table'.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import status
         *     wp bp-export-import status --recent=10
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function status_command($args, $assoc_args) {
            $recent_count = WP_CLI\Utils\get_flag_value($assoc_args, 'recent', 5);
            $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

            global $wpdb;
            $table_name = $wpdb->prefix . 'bp_export_import_operations';

            // Check if operations table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
                WP_CLI::warning("Operations table not found. No operations have been recorded yet.");
                return;
            }

            // Get recent operations
            $recent_operations = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY started_at DESC LIMIT %d",
                $recent_count
            ), ARRAY_A);

            if (empty($recent_operations)) {
                WP_CLI::log("No operations found");
                return;
            }

            WP_CLI::log("Recent Operations:");
            WP_CLI\Utils\format_items($format, $recent_operations, array(
                'id', 'operation_type', 'status', 'total_records', 'processed_records', 'error_count', 'started_at'
            ));

            // Show summary statistics
            $stats = $wpdb->get_row(
                "SELECT 
                    COUNT(*) as total_operations,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN operation_type = 'export' THEN 1 ELSE 0 END) as exports,
                    SUM(CASE WHEN operation_type = 'import' THEN 1 ELSE 0 END) as imports
                 FROM {$table_name}",
                ARRAY_A
            );

            if ($stats) {
                WP_CLI::log("\nSummary Statistics:");
                WP_CLI::log("Total Operations: " . $stats['total_operations']);
                WP_CLI::log("Completed: " . $stats['completed']);
                WP_CLI::log("Failed: " . $stats['failed']);
                WP_CLI::log("Exports: " . $stats['exports']);
                WP_CLI::log("Imports: " . $stats['imports']);
                
                if ($stats['total_operations'] > 0) {
                    $success_rate = round(($stats['completed'] / $stats['total_operations']) * 100, 2);
                    WP_CLI::log("Success Rate: {$success_rate}%");
                }
            }
        }

        /**
         * Clean up old files and data
         *
         * ## OPTIONS
         *
         * [--days=<days>]
         * : Remove files older than this many days. Default: 7.
         *
         * [--logs]
         * : Also clean up old log files.
         *
         * [--operations]
         * : Also clean up old operation records.
         *
         * [--dry-run]
         * : Show what would be cleaned up without actually doing it.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import cleanup
         *     wp bp-export-import cleanup --days=30 --logs --operations
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function cleanup_command($args, $assoc_args) {
            $days = WP_CLI\Utils\get_flag_value($assoc_args, 'days', 7);
            $clean_logs = WP_CLI\Utils\get_flag_value($assoc_args, 'logs', false);
            $clean_operations = WP_CLI\Utils\get_flag_value($assoc_args, 'operations', false);
            $dry_run = WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

            $cleanup_count = 0;

            WP_CLI::log("Cleaning up files older than {$days} days...");

            // Clean up temporary files
            $temp_files_cleaned = bp_export_import_cleanup_temp_files($days);
            $cleanup_count += $temp_files_cleaned;
            
            if ($dry_run) {
                WP_CLI::log("Would clean up {$temp_files_cleaned} temporary files");
            } else {
                WP_CLI::log("Cleaned up {$temp_files_cleaned} temporary files");
            }

            // Clean up log files if requested
            if ($clean_logs) {
                // Implementation would depend on logger cleanup method
                WP_CLI::log("Log cleanup not yet implemented");
            }

            // Clean up old operation records if requested
            if ($clean_operations && !$dry_run) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'bp_export_import_operations';
                
                if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
                    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                    $operations_cleaned = $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$table_name} WHERE started_at < %s",
                        $cutoff_date
                    ));
                    
                    WP_CLI::log("Cleaned up {$operations_cleaned} operation records");
                    $cleanup_count += $operations_cleaned;
                }
            }

            if ($dry_run) {
                WP_CLI::log("Dry run completed. Would clean up {$cleanup_count} items total");
            } else {
                WP_CLI::success("Cleanup completed. {$cleanup_count} items cleaned up");
            }
        }

        /**
         * Manage field mappings
         *
         * ## OPTIONS
         *
         * <action>
         * : Action to perform. Options: 'list', 'show', 'delete', 'export', 'import'.
         *
         * [<mapping-id>]
         * : Mapping ID for show, delete, and export actions.
         *
         * [--file=<file>]
         * : File path for export and import actions.
         *
         * [--format=<format>]
         * : Output format for list and show. Options: 'table', 'json'. Default: 'table'.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import mapping list
         *     wp bp-export-import mapping show mapping_123
         *     wp bp-export-import mapping export mapping_123 --file=mapping.json
         *     wp bp-export-import mapping import --file=mapping.json
         *
         * @param array $args Positional arguments
         * @param array $assoc_args Associated arguments
         */
        public function mapping_command($args, $assoc_args) {
            if (empty($args[0])) {
                WP_CLI::error("Please specify an action: list, show, delete, export, import");
            }

            $action = $args[0];
            $mapping_id = $args[1] ?? '';
            $file = WP_CLI\Utils\get_flag_value($assoc_args, 'file', '');
            $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

            switch ($action) {
                case 'list':
                    $this->list_mappings($format);
                    break;

                case 'show':
                    if (empty($mapping_id)) {
                        WP_CLI::error("Please specify a mapping ID");
                    }
                    $this->show_mapping($mapping_id, $format);
                    break;

                case 'delete':
                    if (empty($mapping_id)) {
                        WP_CLI::error("Please specify a mapping ID");
                    }
                    $this->delete_mapping($mapping_id);
                    break;

                case 'export':
                    if (empty($mapping_id) || empty($file)) {
                        WP_CLI::error("Please specify mapping ID and output file");
                    }
                    $this->export_mapping($mapping_id, $file);
                    break;

                case 'import':
                    if (empty($file)) {
                        WP_CLI::error("Please specify input file");
                    }
                    $this->import_mapping($file);
                    break;

                default:
                    WP_CLI::error("Invalid action. Supported actions: list, show, delete, export, import");
            }
        }

        /**
         * Helper method to export users via CLI
         */
        private function export_users_cli($settings, $output_file, $progress) {
            $page = 1;
            $processed = 0;
            $output = fopen($output_file, 'w');
            
            if (!$output) {
                throw new Exception("Cannot create output file: {$output_file}");
            }

            $format = $settings['export_format'];
            $headers_written = false;

            // Initialize output based on format
            if ($format === 'json') {
                fwrite($output, '{"users":[');
            } elseif ($format === 'xml') {
                fwrite($output, '<?xml version="1.0" encoding="UTF-8"?>' . "\n<users>\n");
            } elseif ($format === 'csv') {
                // Add BOM for proper UTF-8 encoding
                fwrite($output, "\xEF\xBB\xBF");
            }

            $first_record = true;

            do {
                $users = $this->get_users_batch($page, $settings);
                
                if (empty($users)) {
                    break;
                }

                foreach ($users as $user) {
                    $user_data = $this->exporter->prepare_user_data($user, $settings);
                    
                    switch ($format) {
                        case 'csv':
                            if (!$headers_written) {
                                fputcsv($output, array_keys($user_data));
                                $headers_written = true;
                            }
                            fputcsv($output, array_values($user_data));
                            break;
                            
                        case 'json':
                            if (!$first_record) {
                                fwrite($output, ',');
                            }
                            fwrite($output, json_encode($user_data, JSON_UNESCAPED_UNICODE));
                            $first_record = false;
                            break;
                            
                        case 'xml':
                            fwrite($output, "  <user>\n");
                            foreach ($user_data as $key => $value) {
                                $safe_key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                                $safe_value = htmlspecialchars($value, ENT_XML1, 'UTF-8');
                                fwrite($output, "    <{$safe_key}>{$safe_value}</{$safe_key}>\n");
                            }
                            fwrite($output, "  </user>\n");
                            break;
                    }
                    
                    $processed++;
                    $progress->tick();
                }

                $page++;

            } while (count($users) === $settings['batch_size']);

            // Finalize output based on format
            if ($format === 'json') {
                fwrite($output, ']}');
            } elseif ($format === 'xml') {
                fwrite($output, '</users>');
            }

            fclose($output);
            return $processed;
        }

        /**
         * Helper method to import users via CLI
         */
        private function import_users_cli($file_path, $settings, $progress) {
            $format = $settings['import_format'];
            
            switch ($format) {
                case 'json':
                    return $this->import_json_cli($file_path, $settings, $progress);
                case 'xml':
                    return $this->import_xml_cli($file_path, $settings, $progress);
                case 'csv':
                default:
                    return $this->import_csv_cli($file_path, $settings, $progress);
            }
        }

        /**
         * Import CSV via CLI
         */
        private function import_csv_cli($file_path, $settings, $progress) {
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                throw new Exception("Cannot read CSV file");
            }

            $headers = fgetcsv($handle);
            $processed = 0;
            $errors = array();
            $batch = array();

            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) === count($headers)) {
                    $user_data = array_combine($headers, $data);
                    
                    // Apply field mapping if provided
                    if (!empty($settings['field_mapping'])) {
                        $user_data = $this->field_mapping->apply_field_mapping($user_data, $settings['field_mapping']);
                    }
                    
                    $batch[] = $user_data;

                    if (count($batch) >= $settings['batch_size']) {
                        $batch_result = $this->importer->process_user_batch($batch);
                        $processed += $batch_result['processed'];
                        $errors = array_merge($errors, $batch_result['errors']);
                        
                        $progress->tick($batch_result['processed']);
                        $batch = array();
                    }
                }
            }

            // Process remaining batch
            if (!empty($batch)) {
                $batch_result = $this->importer->process_user_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                $progress->tick($batch_result['processed']);
            }

            fclose($handle);

            return array(
                'processed' => $processed,
                'errors' => $errors
            );
        }

        /**
         * Get users batch for CLI export
         */
        private function get_users_batch($page, $settings) {
            $args = array(
                'fields' => 'all',
                'number' => $settings['batch_size'],
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC'
            );

            if (!empty($settings['roles'])) {
                $args['role__in'] = $settings['roles'];
            }

            if (!empty($settings['date_from'])) {
                $args['date_query'] = array(
                    array(
                        'after' => $settings['date_from'],
                        'inclusive' => true,
                    ),
                );
            }

            if (!empty($settings['date_to'])) {
                if (!isset($args['date_query'])) {
                    $args['date_query'] = array();
                }
                $args['date_query'][] = array(
                    'before' => $settings['date_to'],
                    'inclusive' => true,
                );
            }

            $user_query = new WP_User_Query($args);
            return $user_query->get_results();
        }

        /**
         * Get total user count for export
         */
        private function get_total_user_count($settings) {
            $args = array(
                'count_total' => true,
                'fields' => 'ID'
            );

            if (!empty($settings['roles'])) {
                $args['role__in'] = $settings['roles'];
            }

            if (!empty($settings['date_from'])) {
                $args['date_query'] = array(
                    array(
                        'after' => $settings['date_from'],
                        'inclusive' => true,
                    ),
                );
            }

            if (!empty($settings['date_to'])) {
                if (!isset($args['date_query'])) {
                    $args['date_query'] = array();
                }
                $args['date_query'][] = array(
                    'before' => $settings['date_to'],
                    'inclusive' => true,
                );
            }

            $user_query = new WP_User_Query($args);
            return $user_query->get_total();
        }

        /**
         * Count records in import file
         */
        private function count_file_records($file_path, $format) {
            switch ($format) {
                case 'csv':
                    $lines = count(file($file_path));
                    return max(0, $lines - 1); // Subtract header
                case 'json':
                    $data = json_decode(file_get_contents($file_path), true);
                    if (isset($data['users'])) {
                        return count($data['users']);
                    }
                    return is_array($data) ? count($data) : 0;
                case 'xml':
                    $xml = simplexml_load_file($file_path);
                    return $xml ? count($xml->user) : 0;
                default:
                    return 0;
            }
        }

        /**
         * Display validation results
         */
        private function display_validation_results($results) {
            if (!empty($results['errors'])) {
                WP_CLI::log("\nValidation Errors:");
                foreach ($results['errors'] as $error) {
                    WP_CLI::warning("  " . $error);
                }
            }

            if (!empty($results['warnings'])) {
                WP_CLI::log("\nWarnings:");
                foreach ($results['warnings'] as $warning) {
                    WP_CLI::log("  " . $warning);
                }
            }

            if (!empty($results['stats'])) {
                WP_CLI::log("\nFile Statistics:");
                foreach ($results['stats'] as $key => $value) {
                    WP_CLI::log("  " . ucwords(str_replace('_', ' ', $key)) . ": " . $value);
                }
            }
        }

        /**
         * List all field mappings
         */
        private function list_mappings($format) {
            $mappings = $this->field_mapping->get_all_field_mappings();
            
            if (empty($mappings)) {
                WP_CLI::log("No field mappings found");
                return;
            }

            $display_mappings = array();
            foreach ($mappings as $id => $mapping) {
                $display_mappings[] = array(
                    'id' => $id,
                    'name' => $mapping['name'],
                    'fields' => count($mapping['mapping']),
                    'created_at' => $mapping['created_at']
                );
            }

            WP_CLI\Utils\format_items($format, $display_mappings, array('id', 'name', 'fields', 'created_at'));
        }

        /**
         * Show specific field mapping
         */
        private function show_mapping($mapping_id, $format) {
            $mapping = $this->field_mapping->get_field_mapping($mapping_id);
            
            if (!$mapping) {
                WP_CLI::error("Mapping not found: {$mapping_id}");
            }

            if ($format === 'json') {
                WP_CLI::log(json_encode($mapping, JSON_PRETTY_PRINT));
            } else {
                WP_CLI::log("Mapping ID: " . $mapping['id']);
                WP_CLI::log("Name: " . $mapping['name']);
                WP_CLI::log("Created: " . $mapping['created_at']);
                WP_CLI::log("Field Mappings:");
                
                foreach ($mapping['mapping'] as $bp_field => $import_field) {
                    WP_CLI::log("  {$bp_field} <- {$import_field}");
                }
            }
        }

        /**
         * Delete field mapping
         */
        private function delete_mapping($mapping_id) {
            if ($this->field_mapping->delete_field_mapping($mapping_id)) {
                WP_CLI::success("Mapping deleted: {$mapping_id}");
            } else {
                WP_CLI::error("Failed to delete mapping: {$mapping_id}");
            }
        }

        /**
         * Export field mapping to file
         */
        private function export_mapping($mapping_id, $file) {
            $json_data = $this->field_mapping->export_field_mapping($mapping_id);
            
            if (!$json_data) {
                WP_CLI::error("Failed to export mapping: {$mapping_id}");
            }

            if (file_put_contents($file, $json_data)) {
                WP_CLI::success("Mapping exported to: {$file}");
            } else {
                WP_CLI::error("Failed to write file: {$file}");
            }
        }

        /**
         * Import field mapping from file
         */
        private function import_mapping($file) {
            if (!file_exists($file)) {
                WP_CLI::error("File not found: {$file}");
            }

            $json_data = file_get_contents($file);
            $mapping_id = $this->field_mapping->import_field_mapping($json_data);
            
            if ($mapping_id) {
                WP_CLI::success("Mapping imported with ID: {$mapping_id}");
            } else {
                WP_CLI::error("Failed to import mapping from file");
            }
        }

        /**
         * Validate import data for dry run
         */
        private function validate_import_data($file_path, $format, $field_mapping) {
            $validation_result = $this->importer->validate_import_data($file_path, $format);
            
            WP_CLI::log("File Structure Validation:");
            if ($validation_result['valid']) {
                WP_CLI::success("✓ File structure is valid");
            } else {
                WP_CLI::error("✗ File structure validation failed");
            }

            $this->display_validation_results($validation_result);

            // If field mapping is provided, validate it
            if (!empty($field_mapping)) {
                WP_CLI::log("\nField Mapping Validation:");
                $mapping_validation = $this->field_mapping->validate_field_mapping($field_mapping);
                
                if (is_wp_error($mapping_validation)) {
                    WP_CLI::error("✗ Field mapping validation failed: " . $mapping_validation->get_error_message());
                } else {
                    WP_CLI::success("✓ Field mapping is valid");
                }
            }

            WP_CLI::success("Dry run validation completed");
        }

        /**
         * Import JSON via CLI
         */
        private function import_json_cli($file_path, $settings, $progress) {
            $json_data = file_get_contents($file_path);
            $data = json_decode($json_data, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON format: " . json_last_error_msg());
            }

            if (isset($data['users'])) {
                $users = $data['users'];
            } elseif (is_array($data)) {
                $users = $data;
            } else {
                throw new Exception("No user data found in JSON file");
            }

            $processed = 0;
            $errors = array();
            $batch = array();

            foreach ($users as $user_data) {
                // Apply field mapping if provided
                if (!empty($settings['field_mapping'])) {
                    $user_data = $this->field_mapping->apply_field_mapping($user_data, $settings['field_mapping']);
                }
                
                $batch[] = $user_data;

                if (count($batch) >= $settings['batch_size']) {
                    $batch_result = $this->importer->process_user_batch($batch);
                    $processed += $batch_result['processed'];
                    $errors = array_merge($errors, $batch_result['errors']);
                    
                    $progress->tick($batch_result['processed']);
                    $batch = array();
                }
            }

            // Process remaining batch
            if (!empty($batch)) {
                $batch_result = $this->importer->process_user_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                $progress->tick($batch_result['processed']);
            }

            return array(
                'processed' => $processed,
                'errors' => $errors
            );
        }

        /**
         * Import XML via CLI
         */
        private function import_xml_cli($file_path, $settings, $progress) {
            $xml_data = simplexml_load_file($file_path);
            if (!$xml_data || !isset($xml_data->user)) {
                throw new Exception("Invalid XML format or no user data found");
            }

            $processed = 0;
            $errors = array();
            $batch = array();

            foreach ($xml_data->user as $user_xml) {
                $user_data = array();
                
                // Convert XML to array
                foreach ($user_xml as $key => $value) {
                    $user_data[$key] = (string) $value;
                }
                
                // Apply field mapping if provided
                if (!empty($settings['field_mapping'])) {
                    $user_data = $this->field_mapping->apply_field_mapping($user_data, $settings['field_mapping']);
                }
                
                $batch[] = $user_data;

                if (count($batch) >= $settings['batch_size']) {
                    $batch_result = $this->importer->process_user_batch($batch);
                    $processed += $batch_result['processed'];
                    $errors = array_merge($errors, $batch_result['errors']);
                    
                    $progress->tick($batch_result['processed']);
                    $batch = array();
                }
            }

            // Process remaining batch
            if (!empty($batch)) {
                $batch_result = $this->importer->process_user_batch($batch);
                $processed += $batch_result['processed'];
                $errors = array_merge($errors, $batch_result['errors']);
                $progress->tick($batch_result['processed']);
            }

            return array(
                'processed' => $processed,
                'errors' => $errors
            );
        }
    }

    // Register the CLI commands
    new BP_Export_Import_CLI();
}