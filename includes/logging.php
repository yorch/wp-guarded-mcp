<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Writes to the PHP error log and nothing else.
*
* The upstream logger opened WP_Filesystem and wrote into wp-content/uploads on every
* request, including guest front-end views, and did it before checking whether logging
* was even enabled. A log file under uploads is also web-reachable on any host that
* does not block it. error_log() is where a server operator already looks, needs no
* filesystem handle, and costs nothing when the calls are gated.
*/
class GMCP_Logging {

  private static $enabled = null;

  private static function enabled() {
    if ( self::$enabled === null ) {
      global $gmcp_core;
      self::$enabled = $gmcp_core ? (bool) $gmcp_core->get_option( 'mcp_debug_mode' ) : false;
    }
    return self::$enabled;
  }

  /**
  * Debug-level. Silent unless debug mode is on, because these fire per tool call.
  */
  public static function log( $message ) {
    if ( self::enabled() ) {
      self::write( '[Guarded MCP] ' . $message );
    }
  }

  /**
  * Something unexpected but recoverable. Always written: a warning that only appears
  * with debug mode on is a warning nobody sees when it matters.
  */
  public static function warn( $message ) {
    self::write( '[Guarded MCP] WARNING: ' . $message );
  }

  public static function error( $message ) {
    self::write( '[Guarded MCP] ERROR: ' . $message );
  }

  /**
  * The one writer for this class. Every message goes through the full log scrub here as well as
  * at the call sites that print client text, so a caller that forgets is still covered, and a
  * newline in a message cannot start a second log line. @see GMCP_Core::log_line()
  */
  private static function write( $line ) {
    error_log( GMCP_Core::log_line( (string) $line ) );
  }
}
