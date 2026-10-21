<?php
/**
 * Removes the plugin's data, but only when "Delete the data when the plugin is deleted" is on.
 *
 * @package OdooWooPortal
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$odoowoo_portal_settings = get_option( 'odoowoo_portal_settings', array() );

if ( empty( $odoowoo_portal_settings['delete_data'] ) ) {
	return;
}

delete_option( 'odoowoo_portal_settings' );
delete_metadata( 'user', 0, 'odoowoo_portal_tasks', '', true );
