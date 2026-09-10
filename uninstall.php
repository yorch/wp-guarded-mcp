<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
  die;
}

global $wpdb;

// Settings.
delete_option( 'reeve_options' );
delete_option( 'reeve_oauth_db_version' );

// Everything else this plugin writes to the options table.
//
// The journal is the one that matters. It holds what a setting or a post said before an
// agent changed it, which is site content sitting in a row nothing else knows about, and
// leaving it behind for a plugin that is gone means nobody will ever look at it again or
// know to remove it. The key table holds credential hashes, which have the same problem.
delete_option( 'reeve_activity' );
delete_option( 'reeve_journal' );
delete_option( 'reeve_tokens' );

// OAuth clients and grants. Dropping these revokes every connected app, which is the
// point: leaving live tokens behind for a plugin that no longer exists would mean
// credentials nobody can see or revoke.
$clients = $wpdb->prefix . 'reeve_oauth_clients';
$tokens = $wpdb->prefix . 'reeve_oauth_tokens';
$wpdb->query( "DROP TABLE IF EXISTS {$tokens}, {$clients}" );

// Transients: pending authorization codes, consent state, message queue and one-time
// upload tokens. They are short-lived, but an uninstall should not leave them to age out.
$wpdb->query(
  "DELETE FROM {$wpdb->options}
   WHERE option_name LIKE '_transient_reeve_%'
      OR option_name LIKE '_transient_timeout_reeve_%'"
);
