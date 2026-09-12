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
delete_option( 'gmcp_journal_db_version' );
delete_option( 'gmcp_tokens' );
delete_option( 'gmcp_audit_last_full_verify' );
delete_option( 'gmcp_tools_revision' );
delete_option( 'gmcp_version' );

// OAuth clients and grants. Dropping these revokes every connected app, which is the
// point: leaving live tokens behind for a plugin that no longer exists would mean
// credentials nobody can see or revoke.
$gmcp_clients = $wpdb->prefix . 'gmcp_oauth_clients';
$gmcp_tokens = $wpdb->prefix . 'gmcp_oauth_tokens';
$gmcp_audit = $wpdb->prefix . 'gmcp_audit';
// The journal's meta snapshots. This one is content, not bookkeeping: it holds what a
// page design said before an agent changed it, which is the same reason the journal
// option above is deleted rather than left to age out.
$gmcp_snapshots = $wpdb->prefix . 'gmcp_meta_snapshots';
$wpdb->query( "DROP TABLE IF EXISTS {$gmcp_tokens}, {$gmcp_clients}, {$gmcp_audit}, {$gmcp_snapshots}" );

// The prune schedule outlives the plugin files otherwise, and WordPress will keep firing
// an action nothing listens to until somebody notices.
$gmcp_next = wp_next_scheduled( 'gmcp_audit_prune' );
if ( $gmcp_next ) {
  wp_unschedule_event( $gmcp_next, 'gmcp_audit_prune' );
}

// Transients: pending authorization codes, consent state, message queue and one-time
// upload tokens. They are short-lived, but an uninstall should not leave them to age out.
$wpdb->query(
  "DELETE FROM {$wpdb->options}
   WHERE option_name LIKE '_transient_gmcp_%'
      OR option_name LIKE '_transient_timeout_gmcp_%'"
);
