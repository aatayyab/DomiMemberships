<?php
/**
 * Runs when WP Memberships is deleted from the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove the membership roles. Users keep their accounts but lose these roles.
foreach ( array( 'wpm_pending', 'wpm_rejected', 'wpm_individual', 'wpm_corporate' ) as $role ) {
	remove_role( $role );
}

// Remove settings and flags.
delete_option( 'wpm_settings' );
delete_option( 'wpm_rewrite_version' );
delete_option( 'wpm_posts_migrated' );
delete_transient( 'wpm_just_activated' );

global $wpdb;

// Delete member photo files from disk.
$photo_paths = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
		'_wpm_photo_thumb_path',
		'_wpm_photo_full_path'
	)
);
foreach ( $photo_paths as $path ) {
	if ( $path && is_file( $path ) ) {
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}

// Remove the photo folder if it is now empty of files.
$upload    = wp_upload_dir();
$photo_dir = trailingslashit( $upload['basedir'] ) . 'wpm-avatars';
if ( is_dir( $photo_dir ) ) {
	foreach ( glob( $photo_dir . '/*' ) as $leftover ) {
		if ( is_file( $leftover ) ) {
			unlink( $leftover ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	@rmdir( $photo_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

// Remove member profile data (level, organization, country, bio, photo references, application date, posting permission).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_wpm_' ) . '%'
	)
);

// Remove member submissions that were never approved.
$wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'wpm_entry'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'wpm_entry'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Approved member posts stay on the site as regular posts. Only the plugin's tracking fields are removed.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wpm_member_post', '_wpm_visibility', '_wpm_format', '_wpm_markdown_source')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery