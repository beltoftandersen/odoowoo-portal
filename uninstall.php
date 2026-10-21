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

global $wpdb;

// The support sessions (one meta key per license) and the prefix caches (one transient per user).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of keys with a variable suffix.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'odoowoo_support_until_' ) . '%' ) );

$odoowoo_portal_transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_odoowoo_portal_prefixes_' ) . '%' ) );
// phpcs:enable

foreach ( $odoowoo_portal_transients as $odoowoo_portal_name ) {
	delete_transient( substr( (string) $odoowoo_portal_name, strlen( '_transient_' ) ) );
}
