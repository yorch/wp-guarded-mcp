<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Shared state for the plugin: the options row, and the two helpers the MCP layer
* needs from outside itself.
*
* Deliberately small. The MCP server, the OAuth module and the tool providers only
* ever ask this class for get_option(), get_admin_user() and markdown_to_html().
*/
class REEVE_Core {

  const OPTION_NAME = 'reeve_options';

  /**
  * Defaults for every option the plugin reads. Anything absent from the stored row
  * falls back to the value here, so a fresh install needs no migration step.
  *
  * The keys keep the mcp_ prefix they had upstream so the server and OAuth classes
  * did not need editing during the extraction.
  */
  const DEFAULTS = [
    // Empty means "no static token": OAuth is then the only way in, which is the
    // safer default. The settings screen offers to generate one.
    'mcp_bearer_token' => '',
    // admin | readwrite | readonly. Applies to static-token callers. OAuth callers
    // are always admin-gated by user_can_authorize().
    'mcp_role' => 'admin',
    // The built-in WordPress tools (posts, media, users, terms, comments, options).
    'mcp_tools_core' => true,
    // Site administration: plugins, themes, menus, widgets, settings, site health.
    // These can install code and change how the site renders, so they are opt-in.
    'mcp_tools_admin' => false,
    // Expose the site's REST API routes as generated tools. Off by default: it is a
    // large, noisy surface next to the curated tools.
    'mcp_tools_rest' => false,
    'mcp_tools_woo' => false,
    'mcp_debug_mode' => false,
    // Keep a short history of tool calls, including refused ones, for the settings
    // screen. An agent otherwise operates with no visible record at all.
    'mcp_activity_log' => true,
    'mcp_change_journal' => true,
  ];

  private $options = null;

  /** @var REEVE_Server|null Kept so the settings screen can reach the OAuth module. */
  public $server = null;

  public function __construct() {
    add_action( 'plugins_loaded', [ $this, 'init' ] );
  }

  /**
  * Options an agent must not read, write, or have recorded anywhere.
  *
  * The plugin keeps its own configuration, including the static bearer token and the
  * access level that token gets, in a single option row. Without this, a caller could
  * read that token through wp_get_option and keep a permanent, identity-less key to the
  * site: one that outlives revoking their OAuth grant or resetting their password. The
  * write side is worse, since it can mint a token of the caller's choosing and raise
  * mcp_role to admin.
  *
  * The pattern list covers the same shape of secret in other plugins. It is a heuristic
  * and will occasionally block something harmless, which is the right way round: a
  * refused read is a sentence the agent can work around, a leaked API key is not.
  * Sites that need a specific one can allow it through the reeve_protected_options filter.
  *
  * This lives here rather than in the tool class because more than one caller has to
  * agree about it. The change journal records previous option values, and a journal
  * using its own idea of "sensitive" would happily write the bearer token into a second
  * option row the moment somebody changed it.
  *
  * @return true|string True if the key is allowed, otherwise the refusal message.
  */
  public static function option_guard( string $key ) {
    $exact = [
      self::OPTION_NAME,
      'reeve_oauth_db_version',
    ];
    $patterns = [ 'password', 'secret', 'token', 'private_key', 'api_key', 'apikey', 'auth_key', 'salt', 'nonce_key' ];

    $exact = apply_filters( 'reeve_protected_options', $exact, $key );
    $patterns = apply_filters( 'reeve_protected_option_patterns', $patterns, $key );

    $needle = strtolower( $key );
    if ( in_array( $needle, array_map( 'strtolower', (array) $exact ), true ) ) {
      return "The option \"{$key}\" holds this plugin's own credentials and is not readable or writable through the API.";
    }
    foreach ( (array) $patterns as $pattern ) {
      if ( $pattern !== '' && strpos( $needle, strtolower( $pattern ) ) !== false ) {
        return "The option \"{$key}\" looks like it holds a credential, so it is not readable or writable through the API. A site can allow specific keys with the reeve_protected_option_patterns filter.";
      }
    }
    return true;
  }

  public function init() {
    load_plugin_textdomain( REEVE_DOMAIN, false, basename( REEVE_PATH ) . '/languages' );

    // The server registers its own routes on rest_api_init, so it has to exist on
    // every request that might be a REST request. Constructed before the settings
    // screen, which borrows its OAuth instance.
    $this->server = new REEVE_Server( $this );

    if ( $this->get_option( 'mcp_activity_log' ) ) {
      new REEVE_Activity();
    }

    // What changed and how to put it back. Listens to WordPress rather than to the
    // tools, so it has to be constructed on every request a tool call might arrive on.
    if ( $this->get_option( 'mcp_change_journal' ) ) {
      new REEVE_Journal();
    }

    // Registered on every request, not just in admin: Site Health runs its direct
    // tests from an async admin-ajax call, and the filter has to be in place there.
    REEVE_SelfTest::register_site_health();

    // Ready-made workflows the client offers alongside its own commands.
    new REEVE_Prompts();

    if ( is_admin() ) {
      new REEVE_Settings( $this );
    }

    if ( $this->get_option( 'mcp_tools_core' ) ) {
      new REEVE_Tools_Core( $this );
    }

    if ( $this->get_option( 'mcp_tools_admin' ) ) {
      new REEVE_Tools_Admin( $this );
    }

    if ( $this->get_option( 'mcp_tools_rest' ) ) {
      new REEVE_Tools_Rest();
    }

    // Only when the shop is actually here. Registering the tools regardless would put
    // a dozen entries in front of a model that fail the moment it tries one.
    if ( $this->get_option( 'mcp_tools_woo' ) && class_exists( 'WooCommerce' ) ) {
      new REEVE_Tools_Woo();
    }
  }

  #region Options

  public function get_all_options( $force = false ) {
    if ( $force || is_null( $this->options ) ) {
      $stored = get_option( self::OPTION_NAME, [] );
      $stored = is_array( $stored ) ? $stored : [];
      $this->options = array_merge( self::DEFAULTS, $stored );
    }
    return $this->options;
  }

  public function get_option( $option, $default = null ) {
    $options = $this->get_all_options();
    if ( array_key_exists( $option, $options ) ) {
      return $options[$option];
    }
    return $default;
  }

  public function update_options( $options ) {
    $options = array_merge( self::DEFAULTS, is_array( $options ) ? $options : [] );
    update_option( self::OPTION_NAME, $options, false );
    $this->options = $options;
    return $this->options;
  }

  public function update_option( $option, $value ) {
    $options = $this->get_all_options( true );
    $options[$option] = $value;
    return $this->update_options( $options );
  }

  #endregion

  #region Helpers

  /**
  * The account a static-bearer-token caller acts as.
  *
  * A static token carries no identity of its own, so the request has to borrow one.
  * Picking the lowest-ID administrator is stable across calls and does not depend on
  * user 1 existing, which it often does not.
  */
  public function get_admin_user() {
    $users = get_users( [
      'role' => 'administrator',
      'number' => 1,
      'orderby' => 'ID',
      'order' => 'ASC',
    ] );
    return !empty( $users ) ? $users[0] : null;
  }

  public function markdown_to_html( $content ) {
    $parsedown = new Parsedown();
    return $parsedown->text( $content );
  }

  #endregion
}
