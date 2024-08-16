<?php
// Cleanup on plugin uninstall.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit; // Exit if accessed directly.
}

// Cleanup tasks here (e.g., delete options, custom tables, etc.).
