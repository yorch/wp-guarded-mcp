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
    // Both retired, and kept only so the one-time migration can read what an older
    // install stored. A shared token is carried over into a named key and this row is
    // cleared; nothing writes either of them again. @see GMCP_Tokens::adopt_shared_token().
    // Delete them once no install of a shared-token version plausibly remains.
    'mcp_bearer_token' => '',
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
    // Elementor: theme-builder conditions, and putting a library template on a page.
    'mcp_tools_elementor' => false,
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
    // Backup plugins, by namespace rather than by row, and with their own refusal.
    //
    // None of these rows is credential-shaped by name, so the list above misses every one
    // of them. On a site with offsite storage configured, wp_get_option returned the FTP
    // password out of backuply_remote_backup_locs, the access key and secret key out of
    // updraft_s3, and the archive encryption passphrase out of updraft_encryptionphrase,
    // which is the single thing making a stored archive safe at rest. backuply_config_keys
    // holds the key authenticating Backuply's own self-call endpoints, and
    // updraft_backup_history hands over archive filenames including the job nonce, which
    // is precisely what wp_list_backups spends its existence withholding. Worse than a
    // reply: the audit log records a tool's response, so every one of those was also
    // written to the database in plaintext and readable afterwards through
    // wp_get_audit_log.
    //
    // By prefix on purpose. Listing the rows individually is the same mistake in a smaller
    // font, because these names change between plugin versions and a row added next
    // release would be unprotected until somebody noticed. This guard has already been
    // wrong twice that way.
    //
    // It gates writes too, which is worth having on its own: an agent that can rewrite
    // updraft_backup_history can erase a site's record of its own backups.
    $backup_prefixes = apply_filters( 'gmcp_backup_option_prefixes', [
      'updraft', 'backuply', 'backwpup', 'ai1wm', 'duplicator',
    ], $key );
    foreach ( (array) $backup_prefixes as $prefix ) {
      if ( $prefix !== '' && strpos( $needle, strtolower( (string) $prefix ) ) !== false ) {
        // Says what to do instead. A refusal an agent cannot act on gets worked around by
        // guessing at another row, which is the behaviour this is trying to stop.
        return "The option \"{$key}\" belongs to a backup plugin. Those rows hold storage credentials, archive encryption passphrases and archive filenames, so they are not readable or writable through the API. Ask wp_backup_status or wp_list_backups instead, which answer the same questions without handing over anything that would let the archives be fetched. A site can narrow this with the gmcp_backup_option_prefixes filter.";
      }
    }

    foreach ( (array) $patterns as $pattern ) {
      if ( $pattern !== '' && strpos( $needle, strtolower( $pattern ) ) !== false ) {
        return "The option \"{$key}\" looks like it holds a credential, so it is not readable or writable through the API. A site can allow specific keys with the gmcp_protected_option_patterns filter.";
      }
    }
    return true;
  }

  /**
  * Whether an option may be WRITTEN, and to this value.
  *
  * Separate from option_guard(), and the separation is the point. option_guard() asks
  * whether a row is a secret, and gates reads and writes alike. This asks whether a
  * write is safe, which is a different question with different answers: siteurl is not
  * a secret and reading it is ordinary, but writing it wrongly ends the conversation.
  *
  * It lives here because three callers have to agree, and until this existed they did
  * not. wp_update_settings enforced a policy; wp_update_option wrote the same rows with
  * no policy at all; and the change journal put previous values back with neither. So
  * every refusal the settings tool made was reachable by asking a different tool for the
  * same write, which is not a weaker guard but an absent one:
  *
  *   wp_update_settings {"default_role": "administrator"}   refused by construction
  *   wp_update_option   {"key": "default_role", ...}        went straight through
  *
  * The rule is attached to the option, not to the tool. A tool that writes options
  * consults this, and a tool added later gets the policy by asking rather than by
  * remembering to reimplement it.
  *
  * @param mixed $value The value about to be written, before coercion.
  * @return true|string True if the write is allowed, otherwise the refusal message.
  */
  public static function option_write_policy( string $key, $value ) {
    $key = strtolower( trim( $key ) );

    $refused = self::unwritable_options();
    if ( isset( $refused[ $key ] ) ) {
      return $refused[ $key ];
    }

    // Construction checks, for keys that are writable but not to every value. A value
    // this cannot judge is refused rather than waved through: the cost of getting that
    // wrong is a refusal, and the cost of the other way round is open registration
    // granting whatever the unjudged value turns out to mean.
    if ( $key === 'default_role' ) {
      return is_scalar( $value )
        ? self::default_role_objection( (string) $value )
        : 'The default role has to be the name of a role.';
    }

    return true;
  }

  /**
  * Options no value makes safe, and why. Returned to the caller rather than swallowed,
  * so an agent told no is told what to do instead.
  *
  * No filter, deliberately. option_guard() has one because its list is a heuristic that
  * will occasionally block something harmless and a site needs a way to say so. This
  * list is not a heuristic: each entry is a way to make the site unreachable or to
  * repoint account recovery, and a filter here would exist mainly to be found by
  * somebody looking for a way past it.
  *
  * @return array<string,string> option name => refusal message
  */
  public static function unwritable_options(): array {
    return [
      'siteurl' => 'Changing the site URL rewrites every generated URL including this API endpoint, and a wrong value locks wp-admin too, so there would be no way to undo it from here.',
      'home' => 'Same as siteurl: a wrong value makes the site and this endpoint unreachable with no recovery path through the API.',
      'admin_email' => 'Set "new_admin_email" instead. WordPress emails a confirmation link to the new address and only completes the change when someone clicks it. Writing admin_email directly skips that and silently repoints password recovery.',
    ];
  }

  /**
  * Whether a role is safe to hand to everyone who fills in the registration form.
  *
  * This started as "does the role have edit_posts", which was the wrong question and
  * failed open. A role can carry manage_options, promote_users, edit_users or
  * activate_plugins without carrying edit_posts, and a site with a hand-made
  * "api_admin" role holding only read and manage_options sailed through: one call
  * setting users_can_register with that default turned the public form into an
  * administrator factory.
  *
  * Naming the dangerous capabilities is the same mistake one level up, because the list
  * is open-ended and every plugin adds to it. Close it by construction instead: compare
  * against what a subscriber gets, and refuse anything extra. The failure mode of
  * getting that wrong is a refusal, not an escalation.
  *
  * The explicit list is a backstop for the case where the subscriber role itself has
  * been widened, which would otherwise raise the floor and let everything through.
  *
  * @return true|string True if safe, otherwise the refusal message.
  */
  public static function default_role_objection( string $role_name ) {
    $role = get_role( $role_name );
    if ( !$role ) {
      return "\"{$role_name}\" is not a role on this site.";
    }

    $granted = array_keys( array_filter( (array) $role->capabilities ) );

    // Backstop: never acceptable for an account created by an anonymous form.
    $never = [
      'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users',
      'activate_plugins', 'install_plugins', 'edit_plugins', 'delete_plugins',
      'switch_themes', 'install_themes', 'edit_themes', 'delete_themes',
      'edit_files', 'unfiltered_html', 'manage_network', 'import', 'export',
    ];
    $dangerous = array_values( array_intersect( $granted, $never ) );
    if ( $dangerous ) {
      return "Refusing to make \"{$role_name}\" the default role: it grants "
        . implode( ', ', $dangerous ) . ' to anyone who registers. Assign that role to specific accounts instead.';
    }

    // Anything beyond a subscriber is more than an anonymous signup should receive.
    $subscriber = get_role( 'subscriber' );
    if ( $subscriber ) {
      $baseline = array_keys( array_filter( (array) $subscriber->capabilities ) );
      $extra = array_values( array_diff( $granted, $baseline ) );
      if ( $extra ) {
        return "Refusing to make \"{$role_name}\" the default role: it grants "
          . implode( ', ', $extra ) . ' beyond what a subscriber gets, to anyone who registers.';
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

    // Before the server, which would otherwise look for a shared token that is on its way
    // to becoming a key. The check is an isset on an option row already in memory, so it
    // costs nothing on the requests where there is nothing to do, which is all of them
    // after the first.
    if ( (string) $this->get_option( 'mcp_bearer_token' ) !== '' ) {
      GMCP_Tokens::adopt_shared_token();
    }

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

    // Same reasoning as WooCommerce above: without the page builder these tools are a dozen
    // entries in front of a model that fail the moment it tries one.
    //
    // Elementor's own signal rather than class_exists(). Elementor registers its autoloader
    // at file load and only then decides whether to boot, so the class resolves on a site
    // where Elementor bailed out over an unsupported PHP version and never started. The
    // action fires only when it really did start. It fires during Elementor's own
    // plugins_loaded handler, which runs before this one because plugins load in directory
    // order and "elementor" sorts before "guarded-mcp".
    if ( $this->get_option( 'mcp_tools_elementor' ) && did_action( 'elementor/loaded' ) ) {
      new GMCP_Tools_Elementor();
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
