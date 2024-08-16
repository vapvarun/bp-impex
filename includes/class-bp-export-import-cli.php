<?php
// Implements WP CLI commands for the BP Export Import plugin.

if (defined('WP_CLI') && WP_CLI) {

    class BP_Export_Import_CLI
    {

        /**
         * Constructor to register WP CLI commands.
         */
        public function __construct()
        {
            WP_CLI::add_command('bp-export-import export', [$this, 'export_command']);
            WP_CLI::add_command('bp-export-import import', [$this, 'import_command']);
        }

        /**
         * Export users via WP CLI.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : The format of the export file. Options: 'csv', 'json', 'xml'. Default: 'csv'.
         *
         * [--roles=<roles>]
         * : Comma-separated list of roles to filter users by.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import export --format=csv --roles=subscriber,contributor
         *
         * @param array $args Command arguments.
         * @param array $assoc_args Associated arguments.
         */
        public function export_command($args, $assoc_args)
        {
            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'csv';
            $roles = isset($assoc_args['roles']) ? explode(',', $assoc_args['roles']) : [];

            // Get the export class instance
            $exporter = new BP_Export_Import_Export();

            // Mock the $_POST data for the export class
            $_POST['export_format'] = $format;
            $_POST['roles'] = $roles;

            // Run the export process
            $exporter->export_users();

            WP_CLI::success("Users exported successfully in {$format} format.");
        }

        /**
         * Import users via WP CLI.
         *
         * ## OPTIONS
         *
         * <file>
         * : The path to the import file.
         *
         * [--format=<format>]
         * : The format of the import file. Options: 'csv', 'json', 'xml'. Default: 'csv'.
         *
         * ## EXAMPLES
         *
         *     wp bp-export-import import path/to/file.csv --format=csv
         *
         * @param array $args Command arguments.
         * @param array $assoc_args Associated arguments.
         */
        public function import_command($args, $assoc_args)
        {
            $file = $args[0];
            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'csv';

            if (! file_exists($file)) {
                WP_CLI::error("The file {$file} does not exist.");
            }

            // Get the import class instance
            $importer = new BP_Export_Import_Import();

            // Mock the $_POST and $_FILES data for the import class
            $_POST['import_format'] = $format;
            $_FILES['import_file'] = [
                'tmp_name' => $file,
                'name' => basename($file),
                'error' => UPLOAD_ERR_OK,
            ];

            // Run the import process
            $importer->import_users();

            WP_CLI::success("Users imported successfully from {$file}.");
        }
    }

    new BP_Export_Import_CLI();
}
