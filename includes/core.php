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
class GMCP_Core {

  const OPTION_NAME = 'gmcp_options';

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
    'mcp_audit_days' => 90,
    'mcp_change_journal' => true,
  ];

  private $options = null;

  /** @var GMCP_Server|null Kept so the settings screen can reach the OAuth module. */
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
  * Sites that need a specific one can allow it through the gmcp_protected_options filter.
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
      'gmcp_oauth_db_version',
    ];
    $patterns = [
      // This plugin's own rows, whatever they are called and however they are wrapped.
      //
      // Matched as a substring, not a prefix, and that is the point rather than a
      // shortcut: the one-time plaintext of a newly minted key lives at
      // _transient_gmcp_new_key_<user>, so a rule anchored to the start of the name
      // would miss the row it most needs to catch.
      //
      // It replaced a list of exact names, because the list was already wrong twice:
      // the change journal was readable until it was named, and the one-time plaintext of
      // a new key sits in _transient_gmcp_new_key, which no exact entry matched. A rule
      // that covers rows added later is the only kind that stays correct, and the cost is
      // that an agent cannot read this plugin's own bookkeeping, which is not its business.
      'gmcp_',
      'password', 'secret', 'token', 'private_key', 'api_key', 'apikey', 'auth_key', 'salt', 'nonce_key',
    ];

    $exact = apply_filters( 'gmcp_protected_options', $exact, $key );
    $patterns = apply_filters( 'gmcp_protected_option_patterns', $patterns, $key );

    $needle = strtolower( $key );
    if ( in_array( $needle, array_map( 'strtolower', (array) $exact ), true ) ) {
      return "The option \"{$key}\" holds this plugin's own credentials and is not readable or writable through the API.";
    }
    foreach ( (array) $patterns as $pattern ) {
      if ( $pattern !== '' && strpos( $needle, strtolower( $pattern ) ) !== false ) {
        return "The option \"{$key}\" looks like it holds a credential, so it is not readable or writable through the API. A site can allow specific keys with the gmcp_protected_option_patterns filter.";
      }
    }
    return true;
  }

  /**
  * Field names that mark a value as credential-shaped.
  *
  * Lives here rather than in one subsystem because two of them need to agree: the change
  * journal decides whether a previous value is safe to keep, and the audit log decides
  * whether an argument is safe to write down. Two lists would drift, and the direction
  * they drift in is a secret being recorded by whichever one was not updated.
  *
  * Separate from option_guard()'s list, which matches whole OPTION names and gates reads
  * and writes. These match FIELD names inside a value, where conventions are shorter and
  * the cost of a false positive is only that one value is not recorded.
  */
  public static function credential_field_patterns(): array {
    return apply_filters( 'gmcp_credential_field_patterns', [
      'pass', 'pwd', 'secret', 'token', 'key', 'auth', 'salt', 'nonce',
      'credential', 'bearer', 'signature', 'licence', 'license', 'private',
    ] );
  }

  public static function field_looks_secret( string $field ): bool {
    $needle = strtolower( $field );
    foreach ( (array) self::credential_field_patterns() as $pattern ) {
      if ( $pattern !== '' && strpos( $needle, strtolower( (string) $pattern ) ) !== false ) {
        return true;
      }
    }
    // Honour the option-name guard too, so a site that protects a name through
    // gmcp_protected_options also has that name redacted when it appears as a field.
    return self::option_guard( $field ) !== true;
  }

  /**
  * Whether a value carries something credential-shaped, judged by the names inside it.
  *
  * The all-or-nothing counterpart to redact(): this refuses a whole value if any part of
  * it looks secret, which is what a caller wants when it is deciding whether to keep the
  * value at all rather than how to print it.
  *
  * Deliberately about structure rather than content: guessing whether a bare string is a
  * secret means guessing, and guessing wrong in the permissive direction stores the
  * secret. Settings hold these under named fields, and the names say so.
  *
  * It has to walk more than arrays. An option holding a stdClass and an option holding a
  * JSON string are two of the three commonest ways plugins store settings, and checking
  * only arrays left both unguarded. Serialized strings are unpacked for the same reason.
  *
  * Running past the depth limit redacts rather than permits. Returning false there meant
  * a credential nested deeply enough was stored, which is a limit that fails open.
  *
  * It lives here rather than in the change journal, where it was written, because the
  * journal is no longer the only caller: the audit log decides the same thing about the
  * same values when it summarises what changed. Two copies would drift, and the direction
  * they drift in is a secret recorded by whichever one was not updated.
  */
  public static function holds_credential( $value, int $depth = 0 ): bool {
    if ( $depth > 6 ) {
      return true;
    }

    if ( is_string( $value ) ) {
      $trimmed = trim( $value );
      if ( $trimmed === '' ) {
        return false;
      }
      if ( function_exists( 'is_serialized' ) && is_serialized( $trimmed ) ) {
        $unpacked = @unserialize( $trimmed, [ 'allowed_classes' => false ] );
        return $unpacked === false ? false : self::holds_credential( $unpacked, $depth + 1 );
      }
      if ( $trimmed[0] === '{' || $trimmed[0] === '[' ) {
        $decoded = json_decode( $trimmed, true );
        return is_array( $decoded ) ? self::holds_credential( $decoded, $depth + 1 ) : false;
      }
      return false;
    }

    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) ) {
      return false;
    }

    foreach ( $value as $key => $inner ) {
      if ( is_string( $key ) && self::field_looks_secret( $key ) ) {
        return true;
      }
      if ( self::holds_credential( $inner, $depth + 1 ) ) {
        return true;
      }
    }
    return false;
  }

  /**
  * A copy of a value with anything credential-shaped replaced.
  *
  * Unlike holds_credential() above, which refuses the whole value if any part of it
  * looks secret, this keeps the shape and blanks the leaves. An audit entry is worth
  * far more with the harmless arguments intact, and the redaction marker is itself
  * information: it records that a secret was passed without recording the secret.
  *
  * Walks arrays and objects. Does not try to parse strings: a JSON blob under an
  * innocuous field name is left whole, and callers that care should test it with
  * field_looks_secret() on the field it arrived under.
  */
  public static function redact( $value, int $depth = 0 ) {
    if ( $depth > 8 ) {
      return '[too deeply nested to record]';
    }
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) ) {
      return $value;
    }
    $out = [];
    foreach ( $value as $key => $inner ) {
      if ( is_string( $key ) && self::field_looks_secret( $key ) ) {
        $out[ $key ] = '[redacted]';
        continue;
      }
      $out[ $key ] = self::redact( $inner, $depth + 1 );
    }
    return $out;
  }

  public function init() {
    load_plugin_textdomain( GMCP_DOMAIN, false, basename( GMCP_PATH ) . '/languages' );

    // The server registers its own routes on rest_api_init, so it has to exist on
    // every request that might be a REST request. Constructed before the settings
    // screen, which borrows its OAuth instance.
    $this->server = new GMCP_Server( $this );

    // What changed, worked out once for both of the things that want to know. Listens to
    // WordPress rather than to the tools, so it has to be constructed on every request a
    // tool call might arrive on, and before its two consumers so its listeners are in
    // place first.
    if ( $this->get_option( 'mcp_activity_log' ) || $this->get_option( 'mcp_change_journal' ) ) {
      new GMCP_Changes();
    }

    if ( $this->get_option( 'mcp_activity_log' ) ) {
      // Constructed on every request a tool call might arrive on, and it registers the
      // prune cron handler as well as the recorder, so a scheduled prune fires even on a
      // request that never touches the API.
      new GMCP_Audit();
    }

    // How to put back what changed.
    if ( $this->get_option( 'mcp_change_journal' ) ) {
      new GMCP_Journal();
    }

    // Registered on every request, not just in admin: Site Health runs its direct
    // tests from an async admin-ajax call, and the filter has to be in place there.
    GMCP_SelfTest::register_site_health();

    // Ready-made workflows the client offers alongside its own commands.
    new GMCP_Prompts();

    if ( is_admin() ) {
      new GMCP_Settings( $this );
    }

    if ( $this->get_option( 'mcp_tools_core' ) ) {
      new GMCP_Tools_Core( $this );
    }

    if ( $this->get_option( 'mcp_tools_admin' ) ) {
      new GMCP_Tools_Admin( $this );
    }

    if ( $this->get_option( 'mcp_tools_rest' ) ) {
      new GMCP_Tools_Rest();
    }

    // Only when the shop is actually here. Registering the tools regardless would put
    // a dozen entries in front of a model that fail the moment it tries one.
    if ( $this->get_option( 'mcp_tools_woo' ) && class_exists( 'WooCommerce' ) ) {
      new GMCP_Tools_Woo();
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
