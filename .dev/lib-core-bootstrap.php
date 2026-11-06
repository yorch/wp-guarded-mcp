<?php
/**
* Loads the real GMCP_Core, GMCP_Credential_Scan and GMCP_Diag_Util without WordPress, for the
* standalone tests that judge what the credential channels write down.
*
* Only the handful of WordPress functions those classes call are stood in for, and each stand-in
* is the identity or the plain PHP equivalent: nothing here decides a policy. Not shipped.
*/

if ( !defined( 'ABSPATH' ) ) {
  define( 'ABSPATH', __DIR__ . '/' );
}
if ( !function_exists( 'apply_filters' ) ) {
  function apply_filters( $tag, $value, ...$args ) {
    return $value;
  }
}
if ( !function_exists( 'add_action' ) ) {
  function add_action( ...$args ) {}
}
if ( !function_exists( 'add_filter' ) ) {
  function add_filter( ...$args ) {}
}
if ( !function_exists( 'wp_json_encode' ) ) {
  function wp_json_encode( $v, $flags = 0, $depth = 512 ) {
    return json_encode( $v, $flags, $depth );
  }
}
if ( !function_exists( 'is_serialized' ) ) {
  function is_serialized( $data ) {
    return is_string( $data ) && preg_match( '/^[aOsibd]:[0-9]+[:;{]|^N;/', trim( $data ) ) === 1;
  }
}
if ( !function_exists( 'wp_salt' ) ) {
  function wp_salt( $scheme = 'auth' ) {
    return 'test-salt-' . $scheme;
  }
}
if ( !function_exists( 'get_option' ) ) {
  function get_option( $k, $default = false ) {
    return $default;
  }
}

require_once __DIR__ . '/../includes/diagnostics-util.php';
require_once __DIR__ . '/../includes/credential-scan.php';
require_once __DIR__ . '/../includes/meta-key.php';
require_once __DIR__ . '/../includes/core.php';
