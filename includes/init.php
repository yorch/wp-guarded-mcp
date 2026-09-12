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
    'GMCP_Tools_Elementor' => '/includes/tools-elementor.php',
    'GMCP_Tools_Kirki' => '/includes/tools-kirki.php',
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
  require_once( GMCP_PATH . '/includes/journal.php' );
  GMCP_Audit::install();
  // The journal's meta snapshots. Created here as well as lazily from the journal's own
  // constructor, so a site that has the change journal switched off still has the table
  // waiting rather than creating it on the first request after somebody switches it on.
  GMCP_Journal::install();
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
