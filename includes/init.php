<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Explicit class map. The plugin has a handful of classes, so a map is clearer and
* cheaper than prefix matching, and a typo fails loudly instead of silently resolving
* to a file that does not exist.
*/
spl_autoload_register( function ( $class ) {
  static $map = [
    'GMCP_Core' => '/includes/core.php',
    'GMCP_Logging' => '/includes/logging.php',
    'GMCP_Settings' => '/includes/settings.php',
    'GMCP_Server' => '/includes/server.php',
    'GMCP_OAuth' => '/includes/oauth.php',
    'GMCP_Tools_Core' => '/includes/tools-core.php',
    'GMCP_Tools_Rest' => '/includes/tools-rest.php',
    'GMCP_Tools_Woo' => '/includes/tools-woo.php',
    'GMCP_Tools_Admin' => '/includes/tools-admin.php',
    'GMCP_Audit' => '/includes/audit.php',
    'GMCP_Audit_Table' => '/includes/audit-table.php',
    'GMCP_Backup' => '/includes/backup.php',
    'GMCP_Changes' => '/includes/changes.php',
    'GMCP_Journal' => '/includes/journal.php',
    'GMCP_Tokens' => '/includes/tokens.php',
    'GMCP_SelfTest' => '/includes/selftest.php',
    'GMCP_Prompts' => '/includes/prompts.php',
    'GMCP_Resources' => '/includes/resources.php',
    'Parsedown' => '/vendor/Parsedown.php',
  ];
  if ( isset( $map[$class] ) ) {
    require_once( GMCP_PATH . $map[$class] );
  }
} );

global $gmcp_core;
$gmcp_core = new GMCP_Core();

// Registered here rather than from the server class: on activation WordPress includes
// the plugin after plugins_loaded has already fired, so no instance exists to hook it.
// The OAuth discovery documents are cached, and their contents depend on the plugin
// being active, so an activation has to invalidate them.
register_activation_hook( GMCP_ENTRY, function () {
  require_once( GMCP_PATH . '/includes/oauth.php' );
  require_once( GMCP_PATH . '/includes/audit.php' );
  gmcp_adopt_previous_data();
  GMCP_Audit::install();
  GMCP_Audit::adopt_activity_option();
  GMCP_Audit::schedule();
  GMCP_OAuth::purge_discovery_cache();
} );

// A deactivated plugin should leave nothing running. The table and its rows stay, so
// reactivating picks up where it left off; only the schedule goes.
register_deactivation_hook( GMCP_ENTRY, function () {
  require_once( GMCP_PATH . '/includes/audit.php' );
  GMCP_Audit::unschedule();
} );

/**
* Carry settings and OAuth grants across from the plugin's previous name.
*
* This shipped once as Reeve, under a reeve_ prefix. Renaming the options and tables
* would otherwise reset a working install to defaults on upgrade: the bearer token gone,
* the access level back to admin, the tool groups back off, and every connected app
* silently disconnected with no way to tell why. That is a bad way to find out a plugin
* was renamed.
*
* Runs once, on activation, and only where the new rows do not already exist, so it
* cannot overwrite a fresh install that happens to sit beside an old one. The old rows
* are left alone rather than deleted: if this goes wrong, the previous version can still
* be reactivated and will find its own data.
*
* Delete this once no install of the old name plausibly remains.
*/
function gmcp_adopt_previous_data(): void {
  global $wpdb;

  // 'activity' is deliberately absent. That option is retired in favour of the audit
  // table, and carrying it across here resurrected it on every activation: the audit
  // adoption below declines to import once the table has rows, so nothing ever cleared
  // it again. GMCP_Audit::adopt_activity_option() reads the old name directly instead.
  foreach ( [ 'options', 'journal', 'tokens', 'oauth_db_version' ] as $name ) {
    $old = get_option( 'reeve_' . $name, null );
    if ( $old !== null && get_option( 'gmcp_' . $name, null ) === null ) {
      // autoload false everywhere except the settings row, matching how each is written.
      add_option( 'gmcp_' . $name, $old, '', $name === 'options' );
    }
  }

  // The OAuth tables carry live grants. Renaming rather than copying keeps the row ids,
  // which the tokens reference.
  foreach ( [ 'oauth_clients', 'oauth_tokens' ] as $table ) {
    $from = $wpdb->prefix . 'reeve_' . $table;
    $to = $wpdb->prefix . 'gmcp_' . $table;
    $have_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $from ) ) === $from;
    $have_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $to ) ) === $to;
    if ( $have_old && !$have_new ) {
      $wpdb->query( "RENAME TABLE `{$from}` TO `{$to}`" );
    }
  }
}
