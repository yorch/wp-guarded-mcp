<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
  die;
}

global $wpdb;

// Settings.
delete_option( 'gmcp_options' );
delete_option( 'gmcp_oauth_db_version' );

// Everything else this plugin writes to the options table.
//
// The journal is the one that matters. It holds what a setting or a post said before an
// agent changed it, which is site content sitting in a row nothing else knows about, and
// leaving it behind for a plugin that is gone means nobody will ever look at it again or
// know to remove it. The key table holds credential hashes, which have the same problem.
delete_option( 'gmcp_activity' );
delete_option( 'gmcp_audit_db_version' );
delete_option( 'gmcp_audit_hash_boundary' );
delete_option( 'gmcp_journal' );
delete_option( 'gmcp_tokens' );

// OAuth clients and grants. Dropping these revokes every connected app, which is the
// point: leaving live tokens behind for a plugin that no longer exists would mean
// credentials nobody can see or revoke.
$clients = $wpdb->prefix . 'gmcp_oauth_clients';
$tokens = $wpdb->prefix . 'gmcp_oauth_tokens';
$audit = $wpdb->prefix . 'gmcp_audit';
$wpdb->query( "DROP TABLE IF EXISTS {$tokens}, {$clients}, {$audit}" );

// The prune schedule outlives the plugin files otherwise, and WordPress will keep firing
// an action nothing listens to until somebody notices.
$next = wp_next_scheduled( 'gmcp_audit_prune' );
if ( $next ) {
  wp_unschedule_event( $next, 'gmcp_audit_prune' );
}

// Transients: pending authorization codes, consent state, message queue and one-time
// upload tokens. They are short-lived, but an uninstall should not leave them to age out.
$wpdb->query(
  "DELETE FROM {$wpdb->options}
   WHERE option_name LIKE '_transient_gmcp_%'
      OR option_name LIKE '_transient_timeout_gmcp_%'"
);
