<?php
// Utility functions for the BP Export Import plugin.

/**
 * Log a message to the WordPress debug log.
 *
 * @param string $message The message to log.
 */
function bp_export_import_log($message)
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[BP Export Import] ' . $message);
    }
}

/**
 * Check if a specific BuddyPress component is active.
 *
 * @param string $component The component slug (e.g., 'xprofile', 'groups').
 * @return bool True if the component is active, false otherwise.
 */
function bp_export_import_is_component_active($component)
{
    return bp_is_active($component);
}

/**
 * Get the list of all BuddyPress components.
 *
 * @return array List of active BuddyPress components.
 */
function bp_export_import_get_active_components()
{
    return buddypress()->active_components;
}

/**
 * Safely retrieve a value from an array.
 *
 * @param array  $array   The array to retrieve the value from.
 * @param string $key     The key of the value.
 * @param mixed  $default The default value if the key doesn't exist.
 * @return mixed The value from the array or the default value.
 */
function bp_export_import_array_get($array, $key, $default = null)
{
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * Get the full path of a template file in the plugin.
 *
 * @param string $template The template file name.
 * @return string The full path to the template file.
 */
function bp_export_import_get_template($template)
{
    return BP_EXPORT_IMPORT_PLUGIN_DIR . 'templates/' . $template;
}
