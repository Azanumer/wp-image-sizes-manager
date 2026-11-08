<?php
/**
 * Uninstall WP Image Sizes Manager.
 * Removes the plugin's single settings option. Image sizes registered by
 * the theme or other plugins, and thumbnails on disk, are untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wism_settings' );
