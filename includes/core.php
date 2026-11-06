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

  // The build the stored data was last seen under, so an upgrade can be noticed on the
  // first request after it rather than on a reactivation that an in-place upgrade never
  // performs. Carries the gmcp_ prefix, so option_guard() keeps it off the API like the
  // rest of this plugin's bookkeeping.
  const VERSION_OPTION = 'gmcp_version';

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
    // WooCommerce Subscriptions: reading recurring revenue, and the few status changes that
    // are safe to make. Its own switch rather than part of the shop group, so a site that
    // already runs the shop tools is not handed billing control by an update.
    'mcp_tools_woo_subscriptions' => false,
    // Elementor: theme-builder conditions, and putting a library template on a page.
    'mcp_tools_elementor' => false,
    // Elementor Pro: its own switch, because the free group is switched on sites running
    // free Elementor and Pro tools there would be entries that fail when tried. The status
    // tool is registered regardless of Pro being loaded, so it can answer with Pro off.
    'mcp_tools_elementor_pro' => false,
    // Kirki: customizer framework field discovery, value get/set, CSS cache.
    'mcp_tools_kirki' => false,
    // Yoast SEO: per-post SEO metadata through the Surfaces API + indexable rebuild.
    'mcp_tools_yoast' => false,
    // ACF: custom field values through update_field/get_field with key references.
    'mcp_tools_acf' => false,
    // Gravity Forms: form schema, submissions, notes. Submissions are personal data
    // written by members of the public, so the entry tools are admin and an entry edit
    // takes the same two-step as a deletion.
    'mcp_tools_gravityforms' => false,
    // LearnDash: course structure, enrolment and progress. Enrolment is user meta and the
    // student tools are admin; removing an enrolment takes the same two-step as a deletion
    // because re-enrolling does not restore the same enrolment date.
    'mcp_tools_learndash' => false,
    // WP Activity Log: read a third-party security log. Its own switch, defaulting off, so
    // a site is not handed a reader for a log it may not have agreed to keep. Read-only:
    // there is no write tool here and the option writer that could reach the log's own
    // settings refuses them (see option_write_policy()).
    'mcp_tools_wp_activity_log' => false,
    // Redirection: redirects are an open-redirect and self-lockout primitive, so the writer
    // keeps a target and source policy of its own (see GMCP_Tools_Redirection) and the group
    // is off until someone switches it on.
    'mcp_tools_redirection' => false,
    // Polylang (free): read only. Languages and a post's translation group.
    'mcp_tools_polylang' => false,
    // Rank Math and All in One SEO: per-post SEO metadata. Its own switch, separate from
    // Yoast's, so a site is not handed a writer for a plugin it has not chosen. Readers at
    // read, writers at write; see the header of tools-seo.php for why write.
    'mcp_tools_seo' => false,
    // Contact Form 7 (through Flamingo), Fluent Forms and Ninja Forms: form submissions,
    // READ ONLY. Submissions are personal data a member of the public wrote, so the tools
    // that return one are admin and the briefings are counts at read.
    'mcp_tools_forms' => false,
    // Wordfence: read the security plugin's own state — firewall mode, scan history, issue
    // counts and blocks — because "is this site being watched" is a different question from
    // "what happened", and a count of zero findings means nothing without it. Read-only: the
    // firewall, the scanner and the issue list are the watcher, and an agent that can quiet
    // the watcher and then read it back can confirm its own silence.
    'mcp_tools_wordfence' => false,
    // Stream: a second third-party activity log, read from its own tables. Its own switch for
    // the same reason WP Activity Log has one, and read-only for the same reason: an audit log
    // an agent can shorten the retention of is not an audit log, so wp_stream is refused by
    // option_write_policy() (see audit_log_option_prefixes()).
    'mcp_tools_stream' => false,
    // Beaver Builder: the layout lives in post meta, not in post content, so a generic write
    // to the body appears to work and leaves the page rendering as before. Read only apart
    // from clearing the generated CSS/JS, and no tool writes layout data: the html module
    // makes that persistent script injection with no restore behind it.
    'mcp_tools_beaver' => false,
    // Beaver Builder WRITES: creating a page's layout. A separate switch from the read group, so
    // a site can have the reader without the writer. Admin level, create-only, and every value
    // is sanitised by the tool because the builder's own write path applies no sanitisation.
    'mcp_tools_beaver_write' => false,
    // Site files: a directory manifest, and an archive export that always waits for a person
    // on the Approvals screen. Off by default because a listing is reconnaissance and an
    // archive of uploads can be a customer export; the switch is the site owner's decision,
    // and no approval mode can turn the pull leg on without a person.
    'mcp_tools_export' => false,
    // Out-of-band human approval. 'never' | 'deletes' | 'site': which tool calls are held
    // until a logged-in administrator approves them on the Approvals screen. Global rather
    // than per key, so that a key, an OAuth grant and a key minted next week are all
    // covered without anyone remembering to switch it on. @see GMCP_Approvals.
    'mcp_approval_mode' => 'never',
    'mcp_debug_mode' => false,
    // Keep a short history of tool calls, including refused ones, for the settings
    // screen. An agent otherwise operates with no visible record at all.
    'mcp_activity_log' => true,
    'mcp_audit_days' => 90,
    'mcp_change_journal' => true,
  ];

  /**
  * Set when confirm_gate() answered a call with a token instead of letting it proceed.
  * Reset by the dispatcher before each tool runs and read once after it returns.
  */
  public static $gate_withheld = false;

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
      // The fourth of WordPress's cookie keys. wp_salt() stores {scheme}_key and
      // {scheme}_salt as options whenever wp-config.php does not define them, or defines the
      // placeholder phrase. auth_key (which also matches secure_auth_key), nonce_key and
      // every *_salt were covered; logged_in_key was not, so half of the secret that signs
      // logged-in cookies was readable on exactly those sites, and writable, which logs
      // everyone out.
      'logged_in_key',
      // Recovery-mode keys. Hashed, but writing the hash of a key the caller chose is how a
      // recovery link it can mint would come to validate.
      'recovery_keys',
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

    // Payment gateways, by namespace, and for the same reason the backup rows above get
    // their own rule: the NAME gives nothing away. Measured on a site with PayPal
    // configured, woocommerce_paypal_settings was allowed by every pattern above while its
    // value held api_password, api_signature, sandbox_api_password and
    // sandbox_api_signature. A gateway's row is the row that can move money, and the write
    // side matters as much as the read: rewriting api_password is how a payment goes to
    // somebody else's account.
    //
    // Listed by prefix because these names change between WooCommerce releases and a
    // gateway added later would otherwise be unprotected until somebody noticed, which is
    // the mistake this guard has already made twice.
    $gateway_prefixes = apply_filters( 'gmcp_gateway_option_prefixes', [
      'woocommerce_paypal', 'woocommerce_stripe', 'woocommerce_square', 'woocommerce_braintree',
      'woocommerce_authorize', 'woocommerce_eway', 'woocommerce_payfast', 'woocommerce_paystack',
      'woocommerce_razorpay', 'woocommerce_mollie', 'woocommerce_klarna', 'woocommerce_amazon_payments',
    ], $key );
    foreach ( (array) $gateway_prefixes as $prefix ) {
      if ( $prefix !== '' && strpos( $needle, strtolower( (string) $prefix ) ) !== false ) {
        // Says what to do instead, because a refusal an agent cannot act on is one it
        // works around by guessing another row.
        return "The option \"{$key}\" holds payment gateway credentials: an API password, a signature, a secret key or a webhook secret. Those rows are not readable or writable through the API, because reading one hands over the ability to move money and writing one redirects it. Configure the gateway on its own settings screen. A site can extend this list with the gmcp_gateway_option_prefixes filter.";
      }
    }

    // Mail transport, same shape and same reason: the row is named after the plugin, not
    // after the credential inside it. mailserver_pass is WordPress core's own row,
    // registered for the mail server password, and the rest are the popular SMTP plugins.
    $mailer_prefixes = apply_filters( 'gmcp_mailer_option_prefixes', [
      'mailserver', 'wp_mail_smtp', 'wpmailsmtp', 'wp-smtp', 'smtp_', 'postman', 'post_smtp',
      'sendgrid', 'mailgun', 'postmark', 'mailjet', 'elasticemail', 'sparkpost',
      'mailpress', 'wp-mail-smtp', 'smtp-mailer', 'easy_wp_smtp', 'fluentmail',
    ], $key );
    foreach ( (array) $mailer_prefixes as $prefix ) {
      if ( $prefix !== '' && strpos( $needle, strtolower( (string) $prefix ) ) !== false ) {
        return "The option \"{$key}\" belongs to a mail transport plugin and holds the credentials that authenticate this site's outgoing mail. Changing them silently breaks password resets and order emails, and reading them hands over an account that can send mail as this domain. Configure mail on the plugin's own screen. A site can extend this list with the gmcp_mailer_option_prefixes filter.";
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

    $refused = self::option_refusal( $key );
    if ( $refused !== true ) {
      return $refused;
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

    // The other half of the same door. The default_role rule above runs only when
    // default_role itself is written, so a site where a person once set the default to
    // editor or shop_manager was opened to the public by one call setting this, and the
    // rule never ran. Judged by PHP truthiness because that is how WordPress reads the
    // option: the string "false" turns registration on.
    if ( $key === 'users_can_register' && $value ) {
      $objection = self::default_role_objection( (string) get_option( 'default_role', 'subscriber' ) );
      if ( $objection !== true ) {
        return 'Opening registration is refused while the current default role is not safe to hand to anyone who signs up. ' . $objection
          . ' Set default_role to subscriber first, then open registration.';
      }
    }

    return true;
  }

  /**
  * The refusals that hold whatever the value, shared by writing and deleting.
  *
  * Separate from option_write_policy() so a deletion, which has no value to judge, can be
  * asked exactly these and not the construction checks: deleting default_role is not
  * "an invalid role name", it is a different question with a different answer.
  *
  * @param string $key Already lowercased and trimmed.
  * @return true|string
  */
  private static function option_refusal( string $key ) {
    $refused = self::unwritable_options();
    if ( isset( $refused[ $key ] ) ) {
      return $refused[ $key ];
    }

    // The role definitions. default_role_objection() exists so registration cannot hand out
    // administrator rights, and it judges a role by its capabilities, which live here: one
    // write adding manage_options to subscriber turned the public form into an administrator
    // factory without default_role ever being written, and deleting the row left every
    // account, the key's own owner included, with no capabilities at all. By suffix, because
    // the name is the table prefix plus user_roles (wp_user_roles, abc_user_roles,
    // wp_3_user_roles on a network), and a rule built on the literal wp_ name misses a custom
    // prefix. Over-matching an unrelated *_user_roles row costs a refusal.
    if ( self::is_role_definitions_option( $key ) ) {
      return 'This option holds every role on the site and the capabilities each one grants. Rewriting it can give subscribers manage_options, which with open registration makes the sign-up form an administrator factory, and removing it leaves every account, this key\'s owner included, with no capabilities at all. Change roles in wp-admin with a role editor.';
    }

    // An audit log this plugin does not own is still not writable through it. The rule is
    // about the LOG rather than about WP Activity Log, so a site that switches to another
    // logging plugin is covered by adding its prefix to the list rather than by finding
    // every caller.
    //
    // Measured on WP Activity Log 5.6.7: wsal_disabled-alerts is the list of events the log
    // does not record, and replacing it silences whatever the writer chooses — 2001 is
    // "published a post", so one write stops the record of content changes and a second
    // call confirms the silence. wsal_delete-data arms dropping the whole table on
    // uninstall, wsal_pruning-date shortens the retention window to a day, and
    // wsal_excluded-users stops recording named accounts. Each is one call, none is
    // reversible from here, and all four are exactly the settings an agent would change if
    // it wanted its own activity to stop being written down. Deleting one is the same
    // change made the other way, which is why deletion asks this too.
    //
    // This is the same shape as the option policies above it and lives in the same place for
    // the same reason: the settings tool, the generic option tool and the journal's undo all
    // write options, and a rule enforced in one of them is a rule two of them do not have.
    foreach ( self::audit_log_option_prefixes() as $prefix ) {
      if ( $prefix !== '' && strpos( $key, strtolower( (string) $prefix ) ) === 0 ) {
        return 'This option configures an activity log that records what happens on this site. Changing it can stop events being recorded, shorten how long they are kept, or arm deleting the log on uninstall, and none of that can be undone from here. A log an agent can switch off is not a log. Change it on the plugin\'s own settings screen.';
      }
    }

    return true;
  }

  /**
  * Whether an option name is a site's role definitions: {table prefix}user_roles.
  *
  * Matched by suffix so a custom table prefix and a network's per-site prefixes are
  * covered without having to know them, and the live prefixes are checked as well so the
  * rule says what it is for.
  */
  public static function is_role_definitions_option( string $key ): bool {
    $key = strtolower( trim( $key ) );
    if ( $key === '' ) {
      return false;
    }
    if ( substr( $key, -strlen( 'user_roles' ) ) === 'user_roles' ) {
      return true;
    }
    global $wpdb;
    if ( isset( $wpdb ) && is_object( $wpdb ) ) {
      foreach ( [ $wpdb->prefix ?? '', $wpdb->base_prefix ?? '' ] as $prefix ) {
        if ( $prefix !== '' && $key === strtolower( $prefix . 'user_roles' ) ) {
          return true;
        }
      }
    }
    return false;
  }

  /**
  * Whether an option may be DELETED, and the refusal if not.
  *
  * Asked by wp_delete_option and by the journal's undo of a created option, which deletes.
  * Both used to compose their own answer out of unwritable_options() and a list kept in
  * the tool class, and both missed the rules that live in option_write_policy(), so the
  * wsal_ rows the write policy protects and wp_user_roles could each be removed in one
  * call. One function now answers for both, built from the same refusals the write policy
  * uses plus the rows that are only dangerous to remove.
  *
  * @return true|string True if the deletion is allowed, otherwise the full refusal sentence.
  */
  public static function option_delete_policy( string $key ) {
    $needle = strtolower( trim( $key ) );
    $never = self::undeletable_options();
    $why = $never[ $needle ] ?? self::option_refusal( $needle );
    if ( $why === true ) {
      return true;
    }
    return 'The option "' . $key . '" cannot be deleted through this API: ' . $why;
  }

  /**
  * Options that may not be deleted, each with what actually breaks if it goes.
  *
  * Moved here from the tool class so there is one list. These are rows WordPress either
  * cannot run without or cannot rebuild, so losing one is not a recoverable mistake. The
  * value is the sentence the refusal says, so the reason and the rule cannot drift apart.
  * Anything the write policy refuses outright is refused for deletion too, by
  * option_delete_policy(); this list holds the sentences that answer a deletion better.
  *
  * rewrite_rules is deliberately NOT here. It is a derived cache, WordPress regenerates
  * it on the next permalink flush, and deleting it is a normal repair for broken
  * permalinks rather than damage.
  *
  * @return array<string,string>
  */
  public static function undeletable_options(): array {
    return [
      'siteurl' => 'WordPress builds every URL from it, so with the row gone the site cannot resolve its own address; wp-admin and this endpoint included, which means there is no way back in through the API that deleted it.',
      'home' => 'Same as siteurl: the front page and every link on it become unreachable, and so does the route this request arrived on.',
      'template' => 'It names the active theme. With no theme WordPress has nothing to render the front end with.',
      'stylesheet' => 'It names the active child or parent theme. Removing it leaves WordPress looking for a theme directory that is not identified anywhere.',
      'active_plugins' => 'Every plugin deactivates at once, this one among them, so the API that deleted the row is no longer running to restore it.',
      'db_version' => 'It records which schema the database is on. Missing, WordPress runs its upgrade routine against a schema it can no longer place.',
      'initial_db_version' => 'It records the schema the site was installed at, which upgrade routines read to decide what to skip.',
      'cron' => 'The entire schedule is this one row. Deleting it silently drops every scheduled event on the site: publishing, backups, renewals, WooCommerce actions. Nothing errors; things just stop happening.',
      'admin_email' => 'It is where recovery mail, password resets and fatal-error notices go. Without it nobody is told when the site is in trouble.',
    ];
  }

  /**
  * Option prefixes belonging to a third-party activity log.
  *
  * Filterable, because the point is the category rather than one plugin: a site running a
  * different logger adds its prefix through gmcp_audit_log_prefixes and gets the same
  * protection without this list being edited.
  */
  public static function audit_log_option_prefixes(): array {
    return (array) apply_filters( 'gmcp_audit_log_prefixes', [ 'wsal_', 'wp_stream' ] );
  }

  /**
  * Plugin files whose deactivation, deletion or update is refused.
  *
  * The option policy stops an agent rewriting an activity log's settings, and that turned out
  * to be only half of the promise: the same agent could switch the plugin off entirely with one
  * wp_deactivate_plugin call, which stops the recording just as effectively and is not an
  * option write at all. Measured on this stack before the rule existed: Stream, Wordfence and
  * WP Activity Log were each deactivatable through the admin group, while the README said a log
  * an agent can switch off is not a log.
  *
  * By basename, because that is what the plugin tools resolve to and what plugin_basename()
  * returns. Filterable, because the category is "the thing that watches", not these three
  * plugins: a site running a different logger adds its own file.
  *
  * @return string[] plugin basenames.
  */
  public static function watcher_plugin_files(): array {
    return array_map( 'strval', (array) apply_filters( 'gmcp_watcher_plugin_files', [
      'wordfence/wordfence.php',
      'stream/stream.php',
      'wp-security-audit-log/wp-security-audit-log.php',
    ] ) );
  }

  /** Whether this plugin file is one whose removal would stop the site being watched. */
  public static function is_watcher_plugin( string $plugin_file ): bool {
    return in_array( strtolower( $plugin_file ), array_map( 'strtolower', self::watcher_plugin_files() ), true );
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
      // The rows the plugin and theme tools exist to change safely. Each was refused for
      // deletion with a reason that applies to writing it just as much, and writing it was
      // allowed: an empty list deactivated every plugin, this one included, mid-call, with
      // nothing left running to put it back, and a short list silenced the security and
      // logging plugins while getting round every refusal the plugin tools make.
      'active_plugins' => 'It is the list of active plugins. Writing it activates or deactivates plugins without running a single activation or deactivation hook, can switch this plugin off mid-call with nothing left to switch it back on, and gets round every refusal the plugin tools make. Use wp_activate_plugin and wp_deactivate_plugin.',
      // A network's list is a site option and update_option() never reaches it, so this
      // entry refuses a meaningless per-site row rather than a real write. It is listed so the
      // rule reads as complete and stays complete if a site-option writer is ever added.
      'active_sitewide_plugins' => 'It is the list of network-activated plugins. Writing it changes what runs on every site in the network without running any activation or deactivation hook. Activate plugins through the network admin.',
      'template' => 'It names the active (parent) theme. Writing it switches theme without the checks wp_activate_theme makes, that the theme exists, is complete and runs on this PHP, and a wrong value leaves the front end with nothing to render. Use wp_activate_theme.',
      'stylesheet' => 'It names the active theme. Writing it switches theme without the checks wp_activate_theme makes, and a wrong value leaves the front end with nothing to render. Use wp_activate_theme.',
      'cron' => 'The entire schedule of scheduled events is this one row. Writing it can drop every scheduled event on the site, or add one that calls any hook at any time. Use wp_list_cron_events, wp_run_cron_event and wp_unschedule_cron_event.',
      'db_version' => 'It records which database schema WordPress is on. A wrong value makes WordPress run, or skip, its upgrade routines against a schema it can no longer place.',
      'initial_db_version' => 'It records the schema the site was installed at, which upgrade routines read to decide what to skip.',
      // Maps a plugin to the callback WordPress calls when that plugin is deleted. Writing it
      // names a function to be run the next time someone deletes that plugin in wp-admin.
      'uninstall_plugins' => 'It maps each plugin to the callback WordPress runs when that plugin is deleted, so writing it names a function to be called later, by somebody else, from wp-admin.',
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
  * Capabilities that make an account one whose creation or takeover needs a person's say.
  *
  * The same reasoning as default_role_objection()'s backstop and a near relative of its
  * list: these are what an account needs to change who else can do what, to run code, or
  * to change the site's configuration. Judged by capability rather than by role name,
  * because a hand-made role holding manage_options is an administrator in everything but
  * name.
  */
  public static function privileged_capabilities(): array {
    return (array) apply_filters( 'gmcp_privileged_capabilities', [
      'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users',
      'activate_plugins', 'install_plugins', 'edit_plugins', 'delete_plugins', 'update_plugins',
      'install_themes', 'edit_themes', 'delete_themes', 'switch_themes', 'edit_files',
      'update_core', 'unfiltered_upload', 'manage_network', 'manage_network_users', 'manage_sites',
    ] );
  }

  /** Whether a role grants any of privileged_capabilities(). Unknown roles answer false. */
  public static function role_is_privileged( string $role_name ): bool {
    $role = get_role( $role_name );
    if ( !$role ) {
      return false;
    }
    $granted = array_keys( array_filter( (array) $role->capabilities ) );
    return (bool) array_intersect( $granted, self::privileged_capabilities() );
  }

  /**
  * Whether an existing account holds any of privileged_capabilities(), by any route: a
  * role, a capability granted to the user directly, or super admin on a network.
  */
  public static function user_is_privileged( int $user_id ): bool {
    if ( $user_id <= 0 ) {
      return false;
    }
    if ( is_multisite() && is_super_admin( $user_id ) ) {
      return true;
    }
    $user = get_userdata( $user_id );
    if ( !$user ) {
      return false;
    }
    $granted = array_keys( array_filter( (array) $user->allcaps ) );
    return (bool) array_intersect( $granted, self::privileged_capabilities() );
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
  * secret. Settings hold these under named fields, and the names say so. Free text that
  * can carry a pasted secret (a post body) is the one place that is not enough, and
  * @see GMCP_Credential_Scan reads it.
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
  public static function holds_credential( $value, int $depth = 0, int $max_depth = 6 ): bool {
    // Past the limit the answer is yes, because a limit that gave up and said no would be
    // a way to hide a secret by burying it. Six is right for the callers feeding something
    // a person reads, where refusing one unusual value costs a line of a log.
    //
    // It is wrong for the reversible snapshot, measurably so. A page-builder design nests
    // far past six: the meta writer decodes a JSON design into the array the row actually
    // holds, and thirteen nested containers walk twenty-eight levels that way. So every
    // design answered yes on the depth rule alone, with no credential-shaped field name
    // anywhere in it. That refusal protected nothing and declined precisely the values meta
    // undo exists to restore. @see GMCP_Journal::SNAPSHOT_CREDENTIAL_DEPTH for why raising
    // it there is safe for a different reason rather than a weaker one.
    if ( $depth > $max_depth ) {
      return true;
    }

    if ( is_string( $value ) ) {
      $trimmed = trim( $value );
      if ( $trimmed === '' ) {
        return false;
      }
      if ( function_exists( 'is_serialized' ) && is_serialized( $trimmed ) ) {
        $unpacked = @unserialize( $trimmed, [ 'allowed_classes' => false ] );
        return $unpacked === false ? false : self::holds_credential( $unpacked, $depth + 1, $max_depth );
      }
      if ( $trimmed[0] === '{' || $trimmed[0] === '[' ) {
        $decoded = json_decode( $trimmed, true );
        return is_array( $decoded ) ? self::holds_credential( $decoded, $depth + 1, $max_depth ) : false;
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
      // The limit travels with the recursion. Dropping it here would reinstate the
      // shallow default one level down, which is the whole array branch, so an override
      // would appear to work on a flat value and quietly fail on a nested one.
      if ( self::holds_credential( $inner, $depth + 1, $max_depth ) ) {
        return true;
      }
    }
    return false;
  }

  /** What a blanked leaf reads as. One spelling, because the undo path matches against it. */
  const REDACTION_MARKER = '[redacted]';

  /**
  * Post types owned by a commerce plugin, which the generic post and meta tools must not
  * write.
  *
  * The single list. The write guard below, its read-side counterpart and the change
  * journal's undo all ask this, because the journal used to keep a private copy holding
  * shop_subscription alone, and orders recorded under it were offered as ordinary undos.
  * Filterable as gmcp_commerce_post_types so a site running another shop plugin that
  * keeps orders as posts can add its types once and have every caller honour them.
  *
  * @return string[] Lowercased post type names.
  */
  public static function commerce_post_types(): array {
    $types = (array) apply_filters( 'gmcp_commerce_post_types', [
      'shop_order', 'shop_subscription', 'shop_order_refund', 'shop_order_placehold',
    ] );
    $out = [];
    foreach ( $types as $type ) {
      if ( is_string( $type ) && $type !== '' ) {
        $out[] = strtolower( $type );
      }
    }
    return $out;
  }

  /**
  * Whether a post type name is one of commerce_post_types().
  *
  * Compared without regard to case on purpose. MySQL compares post_type through the
  * column's collation, case-insensitive on a stock install, so a row retyped to
  * "Shop_Order" is found by WooCommerce's own queries for shop_order while an exact PHP
  * comparison here would call it an ordinary post.
  */
  public static function is_commerce_post_type( $type ): bool {
    return is_string( $type ) && $type !== '' && in_array( strtolower( $type ), self::commerce_post_types(), true );
  }

  /**
  * Post types that hold another plugin's CONFIGURATION rather than site content.
  *
  * Separate from commerce_post_types() and from personal_data_post_types() because the reason
  * differs even though the rule is the same shape. A WooCommerce order carries money; a form
  * submission is a person's data; these carry the settings that decide what happens to both.
  *
  * Measured on WPForms Lite 2.0.3.1: a form is a `wpforms` post whose post_content is one JSON
  * document holding the fields, the notification recipients and the confirmation redirects.
  * So a readwrite key could rewrite a notification's address through wp_update_post and every
  * future submission would go to an address the agent chose, with nothing on the site
  * recording that it changed — and on Lite, which stores no submissions at all, that email is
  * the only copy. The wpforms tools count those settings and never return them, which would be
  * decoration if the generic post tools returned them instead.
  *
  * Stream's alert rules are posts too (wp_stream_alerts), and a rule set to a disabled status
  * stops that alert firing: the same silencing, reached through post status.
  *
  * @return array<string,array{owner:string,why:string}> post type => who owns it and why.
  */
  public static function plugin_config_post_types(): array {
    return (array) apply_filters( 'gmcp_plugin_config_post_types', [
      'wpforms' => [
        'owner' => 'WPForms',
        'why' => 'the whole form — its fields, its notification recipients and its confirmation redirects — is one JSON document in the post content',
      ],
      // A saved user template: registered on Lite (measured on 2.0.4), and the plugin reads its
      // post_content through the same wpforms_decode() as a form's and builds new forms from
      // it, so the recipients and redirects sit in it exactly as they do in a form.
      'wpforms-template' => [
        'owner' => 'WPForms',
        'why' => 'a saved template is the same JSON document as a form — its fields, its notification recipients and its confirmation redirects — and new forms are made from it',
      ],
      'wp_stream_alerts' => [
        'owner' => 'Stream',
        'why' => 'an alert rule is a post whose status decides whether that alert fires at all',
      ],
    ] );
  }

  /**
  * The refusal for reading or writing a post type another plugin owns, or true.
  *
  * One function so the two directions cannot drift apart on the wording, and so a caller that
  * only has a type (a creation, a retype, a listing filter) gets the same answer as one that
  * has a post.
  */
  private static function plugin_config_post_refusal( string $type, string $verb, bool $read ) {
    $types = self::plugin_config_post_types();
    $key = strtolower( $type );
    if ( !isset( $types[ $key ] ) ) {
      return true;
    }
    $owner = (string) ( $types[ $key ]['owner'] ?? $key );
    $why = (string) ( $types[ $key ]['why'] ?? 'it holds that plugin\'s own settings' );
    if ( $read ) {
      return 'Reading a ' . $key . ' through the generic post tools needs an admin-level key, because ' . $owner
        . ' keeps settings in that row rather than content: ' . $why . '.';
    }
    return $verb . ' a ' . $key . ' through the generic post tools is refused because ' . $owner . ' owns it. '
      . 'That row is configuration, not content: ' . $why . ', so a generic write changes behaviour without the plugin validating anything and nothing on the site records that it changed. '
      . 'Use ' . $owner . '\'s own screen in wp-admin. A site can extend this list with the gmcp_plugin_config_post_types filter.';
  }

  /**
  * Whether a generic post or meta write may touch this post, and the refusal if not.
  *
  * An order and a subscription are posts when WooCommerce's high-performance order storage
  * is off, which is the default on older shops and what many still run, and HPOS with
  * compatibility sync on keeps a posts copy too. That makes them reachable by every
  * generic writer, none of which knows anything about them. The harm is not theoretical: a
  * subscription carries its billing period, its next payment date and whether it renews
  * manually in ordinary meta rows, and its status in post_status. A generic write changes
  * what the next renewal charges and when, without rescheduling the payment job and
  * without telling the gateway, so the site and the gateway disagree and neither says so.
  * Its status is the post's status, so a generic write of wc-active is an activation that
  * skipped every rule the subscription plugin has.
  *
  * This lives here, as a policy about the OBJECT rather than about one tool, because more
  * than one tool has to agree about it — the same reasoning as option_guard(). The
  * alternative, a check inside each write tool, is how the settings tool once enforced a
  * policy no other writer did, and how this guard itself was asked by five writers while
  * four others (copy meta, chunked meta, search-and-replace, media update) wrote the same
  * rows unasked.
  *
  * The existing row's type is not the whole question. A write can also MAKE a commerce
  * post: wp_create_post with post_type shop_subscription mints an "active subscription"
  * that membership plugins asking wcs_user_has_subscription() honour with no payment, and
  * wp_update_post with fields.post_type retypes an ordinary draft into one. So a caller
  * that creates or retypes passes the type it is about to write as $target_type, and that
  * is judged by the same list.
  *
  * @param int         $post_id     The post about to be written, or 0 for a creation.
  * @param string      $verb        Capitalised subject of the refusal: Updating, Deleting.
  * @param string|null $target_type The post type the write will leave the post holding, when
  *                                 the write sets one. Null when it does not.
  * @return true|string True when the write is allowed, otherwise the refusal.
  */
  public static function commerce_post_guard( int $post_id, string $verb = 'Changing', ?string $target_type = null ) {
    // The same question for the other policy that refuses every generic write: a form
    // submission, and what hangs from one. Asked here because this is the function every
    // generic writer already asks, so one definition covers all of them. The name is the
    // older of the two policies. @see personal_data_write_guard().
    $held = self::personal_data_write_guard( $post_id, $verb );
    if ( $held !== true ) {
      return $held;
    }
    $type = $post_id > 0 ? get_post_type( $post_id ) : false;
    // Another plugin's configuration row first, because the sentence differs and so does the
    // reason. Asked for the target type as well as the existing one: creating or retyping into
    // one of these types is the same defect reached from the other side.
    foreach ( array_filter( [ $type, $target_type === null ? '' : trim( $target_type ) ] ) as $candidate ) {
      $held = self::plugin_config_post_refusal( (string) $candidate, $verb, false );
      if ( $held !== true ) {
        return $held;
      }
    }
    if ( !$type || !self::is_commerce_post_type( $type ) ) {
      if ( $target_type === null || !self::is_commerce_post_type( trim( $target_type ) ) ) {
        return true;
      }
      // Creating or retyping reads differently from changing an existing one, and the
      // sentence says which was attempted.
      return ( $post_id > 0 ? 'Turning a post into' : 'Creating' ) . ' a ' . strtolower( trim( $target_type ) )
        . ' through the generic post tools is refused because WooCommerce owns that type. '
        . 'A row of that type written by hand is taken at face value by everything that reads it, so a hand-made subscription or order counts as a paid one without any payment having happened. '
        . 'Use the WooCommerce tools, which go through WooCommerce, or create it in wp-admin.';
    }

    // Owned by WooCommerce rather than by WordPress, and the difference matters: the
    // refusal explains why rather than only saying no.
    $owner = 'WooCommerce';
    return $verb . ' a ' . $type . ' through the generic post tools is refused because ' . $owner . ' owns it. '
      . 'Its status, its dates and its billing settings are stored in the same rows a post uses, so a generic write changes them without rescheduling anything or telling the payment gateway. '
      . 'The change journal will not put such a write back either, for the same reason. '
      . 'Use the WooCommerce tools, which go through WooCommerce, or edit it in wp-admin.';
  }

  /**
  * The access level of the key making the current call: readonly, readwrite or admin.
  *
  * GMCP_Server publishes it through gmcp_caller_access_level for a named key. An OAuth
  * grant, and anything outside a tool call (wp-cli, a cron job), reaches here with no
  * filter attached and is answered "admin", which is what those callers are: OAuth
  * already demands manage_options and applies no level filtering.
  */
  public static function caller_access_level(): string {
    return (string) apply_filters( 'gmcp_caller_access_level', 'admin' );
  }

  /**
  * Whether the current caller may READ a commerce post through a generic tool.
  *
  * The write guard above deliberately left reads alone, on the reasoning that reading an
  * order is a legitimate admin operation. True, and the reasoning stopped one step short:
  * the generic read tools are at the read level. wc_get_order and wc_list_orders are admin
  * because an order is a customer's name, email address, phone number, postal address and
  * IP address, and with HPOS off (or on with compatibility sync) every one of those is an
  * ordinary meta row on a shop_order post. A readonly key asked wp_get_posts for
  * post_type shop_order and then wp_get_post_meta for each id, and had the customer list
  * the admin level exists to keep from it, by naming the same rows through another tool.
  *
  * So the policy belongs to the post type here too, and the read tools ask it: below
  * admin, a commerce post is not returned, its meta is not returned, and a listing of
  * them is refused. At admin it is answered exactly as before.
  *
  * @param int|string|WP_Post $post A post, its id, or a post type name.
  * @return true|string True when the read is allowed, otherwise the refusal.
  */
  public static function commerce_post_read_guard( $post ) {
    if ( self::caller_access_level() === 'admin' ) {
      return true;
    }
    if ( $post instanceof WP_Post ) {
      $types = self::post_chain_types( $post );
    }
    elseif ( is_int( $post ) || ( is_string( $post ) && ctype_digit( $post ) ) ) {
      $types = self::post_chain_types( (int) $post );
    }
    else {
      $types = [ $post ];
    }
    $type = null;
    foreach ( $types as $candidate ) {
      if ( self::is_commerce_post_type( $candidate ) ) {
        $type = $candidate;
        break;
      }
    }
    if ( $type === null ) {
      // Not a commerce post, but possibly another plugin's configuration row, or a revision,
      // media row or child hanging from one: below admin it is not returned either, for the
      // reason the write guard gives. Followed up the chain for the same reason the commerce
      // types are, since a revision of a form holds the form's JSON.
      foreach ( $types as $candidate ) {
        $held = self::plugin_config_post_refusal( (string) $candidate, 'Changing', true );
        if ( $held !== true ) {
          return $held;
        }
      }
      return true;
    }
    return 'Reading a ' . strtolower( (string) $type ) . ' through the generic post tools needs an admin-level key. '
      . 'Orders and subscriptions carry customers\' names, email addresses, phone numbers, postal addresses and IP addresses in their meta, '
      . 'which is why the WooCommerce tools that read them on purpose (wc_get_order, wc_list_orders) are admin-level too. '
      . ( $types[0] !== $type ? 'This post belongs to one: a revision, a media row or a child of an order is read under the order\'s policy. ' : '' )
      . 'This key is ' . self::caller_access_level() . '.';
  }

  /**
  * The post type of a post and of every post above it, nearest first, lowercased.
  *
  * A read policy about a post type has to follow what hangs off it. A revision of a form
  * submission holds the submission's body, a media row attached to one is a file a visitor
  * uploaded, and a child post is part of it, yet each is an ordinary row of another type
  * ("revision", "attachment", "page") that asked of itself alone passes every type check.
  * Walked rather than looked up one level because a revision of a media row's parent is
  * two steps from the post that carries the policy, and bounded because post_parent is
  * data and a loop in it must not hang a read.
  *
  * @param int|WP_Post $post
  * @return string[] Empty when the post does not exist.
  */
  public static function post_chain_types( $post ): array {
    $post = $post instanceof WP_Post ? $post : get_post( (int) $post );
    $types = [];
    $seen = [];
    for ( $depth = 0; $post instanceof WP_Post && $depth < 8 && !isset( $seen[ $post->ID ] ); $depth++ ) {
      $seen[ $post->ID ] = true;
      $types[] = strtolower( (string) $post->post_type );
      $post = $post->post_parent ? get_post( (int) $post->post_parent ) : null;
    }
    return $types;
  }

  /**
  * Post types a SQL query that reads posts for the current caller must leave out: the
  * form-submission types always, the commerce types and the types that hold another plugin's
  * configuration below admin. For a query that cannot ask a guard row by row, so it has to say
  * what commerce_post_read_guard() says. Lowercased; empty is possible only if the filters
  * empty the lists.
  *
  * @return string[]
  */
  public static function read_excluded_post_types(): array {
    $types = array_map( 'strtolower', array_map( 'strval', self::personal_data_post_types() ) );
    if ( self::caller_access_level() !== 'admin' ) {
      $types = array_merge( $types, self::commerce_post_types(), array_map( 'strtolower', array_map( 'strval', array_keys( self::plugin_config_post_types() ) ) ) );
    }
    return array_values( array_unique( $types ) );
  }

  /**
  * SQL for "this post is a held type, or hangs from one", for a query that cannot ask the guard
  * row by row. The types are read_excluded_post_types(), so below admin an order's tree is in
  * it too. $type_col and $parent_col name the columns of the row being judged, so the caller
  * decides the table alias. The chain is the depth post_chain_types() walks: the row and
  * seven parents above it.
  *
  * Joins, not nested subqueries. Seven EXISTS inside one another is correct on MySQL and
  * overflows the parser of the SQLite driver WordPress can run on ("parser stack overflow",
  * measured), so the parents are joined by primary key instead: each join yields at most one
  * row, so no row is duplicated, and a missing parent is a NULL that the COALESCE reads as "not
  * a held type". The caller puts 'join' after its FROM and 'where' behind an AND.
  *
  * @return array{join: string, where: string} Both empty when no type is excluded.
  */
  public static function unreadable_post_clauses( string $type_col, string $parent_col, string $alias = 'gmcp_u' ): array {
    global $wpdb;
    $in = implode( ',', array_map( function ( $type ) {
      return "'" . esc_sql( $type ) . "'";
    }, self::read_excluded_post_types() ) );
    if ( $in === '' ) {
      return [ 'join' => '', 'where' => '' ];
    }
    $joins = '';
    $tests = [ 'LOWER(' . $type_col . ') IN (' . $in . ')' ];
    $up = $parent_col;
    for ( $level = 1; $level <= 7; $level++ ) {
      $a = $alias . $level;
      $joins .= " LEFT JOIN {$wpdb->posts} {$a} ON {$a}.ID = {$up}";
      $tests[] = "COALESCE(LOWER({$a}.post_type),'') IN ({$in})";
      $up = $a . '.post_parent';
    }
    return [ 'join' => $joins, 'where' => 'NOT (' . implode( ' OR ', $tests ) . ')' ];
  }

  /**
  * Runs $run() with the readable-only SQL on every WP_Query that does not suppress filters, and
  * takes it off again, so a listing's paging happens in the database.
  *
  * A listing that skipped unreadable rows in PHP could only page by loading every row up to its
  * offset: measured with 50,000 posts and a read-only key, `limit 5` took 0.6 seconds and
  * `limit 5, offset 40000` took 13 seconds and 210 MB, because each row up to the offset was built
  * into a WP_Post and had its parent chain walked, and an offset of ten million ran for over a
  * minute. Put in the query instead, the offset is an ordinary SQL offset over rows already
  * filtered. The query must run with suppress_filters false, or WordPress does not apply
  * posts_join and posts_where at all.
  *
  * @see unreadable_post_clauses() for the SQL, which the REST listings and the scans use too.
  *
  * @return mixed Whatever $run() returns.
  */
  public static function with_readable_posts_sql( callable $run ) {
    $join = static function ( $join ) {
      global $wpdb;
      return $join . self::unreadable_post_clauses( "{$wpdb->posts}.post_type", "{$wpdb->posts}.post_parent", 'gmcp_l' )['join'];
    };
    $where = static function ( $where ) {
      global $wpdb;
      $c = self::unreadable_post_clauses( "{$wpdb->posts}.post_type", "{$wpdb->posts}.post_parent", 'gmcp_l' );
      return $c['where'] === '' ? $where : $where . ' AND ' . $c['where'];
    };
    add_filter( 'posts_join', $join, PHP_INT_MAX );
    add_filter( 'posts_where', $where, PHP_INT_MAX );
    try {
      return $run();
    }
    finally {
      remove_filter( 'posts_join', $join, PHP_INT_MAX );
      remove_filter( 'posts_where', $where, PHP_INT_MAX );
    }
  }

  /**
  * The same for a comment query: a comment is read as the post it is on, so a comment on a
  * post the caller may not read is left out by the query, and the offset is a SQL offset.
  * WP_Comment_Query applies comments_clauses whatever the arguments.
  *
  * A comment with no post, or whose post is gone, is readable, as the PHP check has it.
  *
  * @return mixed Whatever $run() returns.
  */
  public static function with_readable_comments_sql( callable $run ) {
    $clauses = static function ( $clauses ) {
      global $wpdb;
      $c = self::unreadable_post_clauses( "COALESCE(gmcp_cp.post_type,'')", 'gmcp_cp.post_parent', 'gmcp_cc' );
      if ( !is_array( $clauses ) || $c['where'] === '' ) {
        return $clauses;
      }
      $clauses['join'] = ( $clauses['join'] ?? '' ) . " LEFT JOIN {$wpdb->posts} gmcp_cp ON gmcp_cp.ID = {$wpdb->comments}.comment_post_ID" . $c['join'];
      $clauses['where'] = ( ( $clauses['where'] ?? '' ) !== '' ? $clauses['where'] . ' AND ' : '' ) . $c['where'];
      return $clauses;
    };
    add_filter( 'comments_clauses', $clauses, PHP_INT_MAX );
    try {
      return $run();
    }
    finally {
      remove_filter( 'comments_clauses', $clauses, PHP_INT_MAX );
    }
  }

  /**
  * Rows a listing may return, collected until the page is full.
  *
  * A listing that fetches a page and then drops what the caller may not read returns a short
  * page, and the length of the page is itself an answer: with a limit of one, an empty reply
  * says the newest row is one the caller may not see. So the unreadable rows are skipped as
  * the page is filled, and a hidden row changes nothing a caller can count.
  *
  * The listings leave the unreadable rows out in SQL (with_readable_posts_sql(),
  * with_readable_comments_sql()), which is where their offset is applied, so this is the backstop
  * for a row the SQL let through and the guard would not: it asks the guard row by row.
  *
  * Bounded, so a window of nothing but unreadable rows cannot run a query forever. The bound is
  * on rows looked at, $max_scan of them in all, and counts the rows passed over for $skip as well:
  * an offset buys no extra work (it used to extend the bound, and an offset of ten million
  * loaded every row below it). Past the bound the page can come back short, which the
  * guardrails page states. Callers give the database their offset and pass no $skip.
  *
  * @param callable $fetch (int $offset, int $size): array, the next raw rows in listing order.
  * @param callable $keep  (mixed $row): bool, whether the caller may see the row.
  * @param int      $skip  Readable rows to pass over first, counted against $max_scan.
  */
  public static function collect_readable( callable $fetch, callable $keep, int $limit, int $skip = 0, int $max_scan = 5000 ): array {
    $out = [];
    $raw = 0;
    $scanned = 0;
    $size = max( 50, min( 500, $limit * 2 ) );
    while ( $scanned < $max_scan ) {
      $rows = $fetch( $raw, $size );
      if ( !is_array( $rows ) || !$rows ) {
        break;
      }
      foreach ( $rows as $row ) {
        $scanned++;
        if ( !$keep( $row ) ) {
          continue;
        }
        if ( $skip > 0 ) {
          $skip--;
          continue;
        }
        $out[] = $row;
        if ( count( $out ) >= $limit ) {
          break 2;
        }
      }
      $raw += count( $rows );
      if ( count( $rows ) < $size ) {
        break;
      }
    }
    return $out;
  }

  #region Drafts-only keys

  /**
  * Set once per request, by the server, when the credential presented is a drafts-only key.
  * Nothing sets it back: a request has one caller.
  */
  private static $drafts_only = false;
  private static $drafts_net_installed = false;

  /** Whether the credential behind this request may only touch content that is not live. */
  public static function caller_drafts_only(): bool {
    return self::$drafts_only;
  }

  /**
  * The statuses at which a post is in front of the public, or scheduled to be.
  *
  * publish, future and private are the three the brief names, and any status a plugin has
  * registered as public or private joins them, so a custom "published" state is not a way
  * round. inherit is deliberately not here: an attachment or a revision takes its parent's
  * visibility, which status_is_live() resolves.
  *
  * @return string[]
  */
  public static function live_statuses(): array {
    $statuses = [ 'publish', 'future', 'private' ];
    if ( function_exists( 'get_post_stati' ) ) {
      $statuses = array_merge(
        $statuses,
        array_values( get_post_stati( [ 'public' => true ] ) ),
        array_values( get_post_stati( [ 'private' => true ] ) )
      );
    }
    $statuses = (array) apply_filters( 'gmcp_live_post_statuses', $statuses );
    return array_values( array_diff( array_unique( array_map( [ __CLASS__, 'normalise_status' ], $statuses ) ), [ 'inherit' ] ) );
  }

  /**
  * Whether a status, for a post with this parent, is one the public can reach.
  *
  * An attachment is "inherit", and an attachment on a live page is part of that page. One
  * attached to a draft, or to nothing, is not live content: uploading media while drafting
  * is the ordinary use of a drafts-only key, and refusing it would make the key useless.
  * The walk up the parents is bounded so a loop cannot hang the request.
  */
  public static function status_is_live( string $status, int $parent = 0, int $depth = 0 ): bool {
    $status = self::normalise_status( $status );
    if ( $status === 'inherit' ) {
      if ( $parent <= 0 || $depth >= 5 ) {
        return false;
      }
      $up = get_post( $parent );
      return $up ? self::status_is_live( (string) $up->post_status, (int) $up->post_parent, $depth + 1 ) : false;
    }
    return in_array( $status, self::live_statuses(), true );
  }

  /**
  * Lower case, no surrounding whitespace. The posts table compares with a case- and
  * padding-insensitive collation on a stock install, so a post stored with the status
  * "Publish" or "publish " is found by every query that asks for "publish", and a
  * comparison here that was stricter than the database would call it a draft.
  */
  private static function normalise_status( $status ): string {
    return strtolower( trim( (string) $status ) );
  }

  /** @param int|WP_Post $post */
  public static function post_is_live( $post ): bool {
    $post = get_post( $post );
    return $post ? self::status_is_live( (string) $post->post_status, (int) $post->post_parent ) : false;
  }

  private static function drafts_only_explanation(): string {
    return 'This key is drafts-only: it can create and edit drafts and pending posts, but it cannot publish, schedule, '
      . 'make private, edit, delete or unpublish a post that is live, or edit or delete media that is not attached to a draft. '
      . 'That covers posts, their custom fields, terms and featured image, and attached media; comments, taxonomy terms, users and options are outside this setting. '
      . 'Ask an administrator to make the change, or use a key that is not drafts-only.';
  }

  /**
  * Whether a drafts-only caller may write to this existing post, and the sentence to refuse
  * with when it may not.
  *
  * A policy about the OBJECT, like commerce_post_guard(): every tool that writes a post or
  * its meta, terms or thumbnail asks this one question, and a net of WordPress hooks
  * (below) asks it again underneath for writers that do not, so a tool added later, or a
  * third-party plugin's, cannot be the one that was missed. The tools ask first so the
  * refusal is a clear sentence and nothing is half written; the net is the backstop.
  *
  * Live means the post is publish, future or private now, OR the write would make it so.
  * Unpublishing is refused as well as publishing: taking a page down is a change to the
  * site the public sees.
  *
  * @param string $new_status The status the write would leave the post in, or '' if it
  *                           does not change it.
  * @param bool   $removing   Deleting rather than editing. Media is judged harder, because an
  *                           unattached attachment can be the featured image of a live post.
  * @return true|string True when the write is allowed, otherwise the refusal.
  */
  public static function drafts_only_guard( int $post_id, string $verb = 'Changing', string $new_status = '', bool $removing = false ) {
    if ( !self::$drafts_only || $post_id <= 0 ) {
      return true;
    }
    $post = get_post( $post_id );
    if ( !$post ) {
      return true;
    }
    $new_status = sanitize_key( $new_status );
    if ( self::post_is_live( $post ) ) {
      return $verb . ' post #' . $post_id . ' is refused: it is live (status "' . $post->post_status . '"). ' . self::drafts_only_explanation();
    }
    if ( $new_status !== '' && self::status_is_live( $new_status, (int) $post->post_parent ) ) {
      return $verb . ' post #' . $post_id . ' with status "' . $new_status . '" is refused: that would put it in front of the public. ' . self::drafts_only_explanation();
    }
    if ( $removing && $post->post_type === 'attachment' && ( (int) $post->post_parent <= 0 ) ) {
      return $verb . ' media #' . $post_id . ' is refused: it is not attached to a draft, so a live page may be using it. ' . self::drafts_only_explanation();
    }
    return true;
  }

  /**
  * The same question for content that does not exist yet: may this key create something
  * with this status?
  *
  * @return true|string
  */
  public static function drafts_only_status_guard( string $status, string $verb = 'Creating content' ) {
    if ( !self::$drafts_only ) {
      return true;
    }
    $status = sanitize_key( $status );
    if ( $status !== '' && self::status_is_live( $status ) ) {
      return $verb . ' with status "' . $status . '" is refused: that would put it in front of the public. ' . self::drafts_only_explanation();
    }
    return true;
  }

  /**
  * The one question for the generic post and meta writes: the commerce policy first, then
  * the drafts-only one. Both are policies about the post, so both are asked here rather
  * than in whichever tool happens to be writing.
  *
  * @return true|string
  */
  public static function post_write_guard( int $post_id, string $verb = 'Changing', string $new_status = '' ) {
    $commerce = self::commerce_post_guard( $post_id, $verb );
    if ( $commerce !== true ) {
      return $commerce;
    }
    return self::drafts_only_guard( $post_id, $verb, $new_status );
  }

  /**
  * Turn the restriction on for this request, and install the net underneath the tools.
  *
  * The tool-level guards above give a clear refusal. This is what makes "no writer is
  * missed" true rather than hoped: every route to a post row that goes through WordPress,
  * which is every tool in this plugin and every plugin a tool calls into (Elementor, Yoast,
  * ACF, WooCommerce, the generated REST tools, menu items, the change journal's undo),
  * passes through one of these hooks, and each throws before anything is written.
  *
  * It throws rather than returning false. A pre-filter that short-circuits with false
  * makes update_post_meta() report failure quietly and the caller carry on as though it had
  * worked, which is a tool answering success over a write that did not happen. An
  * exception reaches execute_tool(), which turns it into an isError reply the audit log
  * records as a refusal.
  *
  * What it cannot see: a plugin writing the posts table with $wpdb directly. None of this
  * plugin's tools does that for content, and a drafts-only key is held to the write level,
  * which excludes the tool groups (Elementor, Yoast, ACF, menus, options) that reach such code.
  */
  public static function restrict_caller_to_drafts(): void {
    self::$drafts_only = true;
    if ( self::$drafts_net_installed ) {
      return;
    }
    self::$drafts_net_installed = true;

    // The status the row is about to be stored with, after every other plugin has had its
    // say, which is why this runs last. Covers wp_insert_post and wp_update_post, and so
    // everything built on them.
    add_filter( 'wp_insert_post_data', [ __CLASS__, 'net_insert' ], PHP_INT_MAX, 4 );
    // Attachments are filtered through their own hook instead of that one, so media
    // uploads, edits and re-parenting would otherwise walk straight past the net.
    add_filter( 'wp_insert_attachment_data', [ __CLASS__, 'net_insert' ], PHP_INT_MAX, 4 );

    // Meta, added, changed or removed, through every route including by meta id.
    foreach ( [ 'add', 'update', 'delete' ] as $op ) {
      add_filter( $op . '_post_metadata', [ __CLASS__, 'net_meta' ], PHP_INT_MIN, 3 );
    }
    add_filter( 'update_post_metadata_by_mid', [ __CLASS__, 'net_meta_by_mid' ], PHP_INT_MIN, 2 );
    add_filter( 'delete_post_metadata_by_mid', [ __CLASS__, 'net_meta_by_mid' ], PHP_INT_MIN, 2 );

    // Trash, restore and permanent deletion. These fire before anything is touched.
    foreach ( [ 'pre_delete_post', 'pre_delete_attachment', 'pre_trash_post', 'pre_untrash_post' ] as $hook ) {
      add_filter( $hook, [ __CLASS__, 'net_remove' ], PHP_INT_MIN, 2 );
    }
    // Restoring from the trash goes back to the status the post held before, which on
    // WordPress older than 5.6 was publish. A restore is always a draft for this caller.
    add_filter( 'wp_untrash_post_status', function ( $status ) {
      return self::status_is_live( (string) $status ) ? 'draft' : $status;
    }, PHP_INT_MAX );

    // Category, tag and any other taxonomy assignment, fired before the row is written.
    add_action( 'add_term_relationship', [ __CLASS__, 'net_terms' ], PHP_INT_MIN, 3 );
    add_action( 'delete_term_relationships', [ __CLASS__, 'net_terms' ], PHP_INT_MIN, 3 );
  }

  private static function net_refuse( string $message ): void {
    throw new GMCP_Drafts_Only_Refusal( $message );
  }

  public static function net_insert( $data, $postarr = [], $unsanitized = [], $update = false ) {
    if ( !self::$drafts_only || !is_array( $data ) ) {
      return $data;
    }
    $id = (int) ( $postarr['ID'] ?? 0 );
    if ( $update && $id > 0 ) {
      $why = self::drafts_only_guard( $id, 'Updating', (string) ( $data['post_status'] ?? '' ) );
      if ( $why !== true ) {
        self::net_refuse( $why );
      }
      return $data;
    }
    $why = self::drafts_only_status_guard( (string) ( $data['post_status'] ?? '' ), 'Creating a post' );
    if ( $why === true && self::status_is_live( (string) ( $data['post_status'] ?? '' ), (int) ( $data['post_parent'] ?? 0 ) ) ) {
      // inherit under a live parent: new media or a revision attached to a live page.
      $why = 'Creating a post attached to a live post is refused. ' . self::drafts_only_explanation();
    }
    if ( $why !== true ) {
      self::net_refuse( $why );
    }
    return $data;
  }

  public static function net_meta( $check, $object_id = 0, $meta_key = '' ) {
    if ( self::$drafts_only ) {
      $why = self::drafts_only_guard( (int) $object_id, 'Writing meta on' );
      if ( $why !== true ) {
        self::net_refuse( $why );
      }
    }
    return $check;
  }

  public static function net_meta_by_mid( $check, $meta_id = 0 ) {
    if ( self::$drafts_only ) {
      $row = function_exists( 'get_metadata_by_mid' ) ? get_metadata_by_mid( 'post', (int) $meta_id ) : null;
      if ( $row && !empty( $row->post_id ) ) {
        $why = self::drafts_only_guard( (int) $row->post_id, 'Writing meta on' );
        if ( $why !== true ) {
          self::net_refuse( $why );
        }
      }
    }
    return $check;
  }

  public static function net_remove( $check, $post = null ) {
    if ( self::$drafts_only && $post instanceof WP_Post ) {
      $why = self::drafts_only_guard( (int) $post->ID, 'Removing', '', true );
      if ( $why !== true ) {
        self::net_refuse( $why );
      }
    }
    return $check;
  }

  public static function net_terms( $object_id = 0, $unused = null, $taxonomy = '' ) {
    if ( !self::$drafts_only ) {
      return;
    }
    // The action also fires for objects that are not posts, and a user or a link can share
    // a post's numeric id. Only a taxonomy that belongs to the post's own type is a post write.
    $post = get_post( (int) $object_id );
    $tax = $post ? get_taxonomy( (string) $taxonomy ) : null;
    if ( !$post || !$tax || !in_array( $post->post_type, (array) $tax->object_type, true ) ) {
      return;
    }
    $why = self::drafts_only_guard( (int) $post->ID, 'Changing the terms of' );
    if ( $why !== true ) {
      self::net_refuse( $why );
    }
  }

  #endregion

  #region Deletion by another name

  /**
  * Post statuses a tool may not write. Both are DELETIONS that wait for a daily job.
  *
  * 'trash': wp_scheduled_delete() permanently deletes every post that carries a
  * _wp_trash_meta_time older than EMPTY_TRASH_DAYS, and a generic meta writer could stamp
  * that key with a date in 1970. So "set post_status to trash" plus one meta write was a
  * permanent delete with no approval and no two steps, reached from the write level, which
  * the README defines as "no destructive tools".
  *
  * 'auto-draft': wp_delete_auto_drafts() force-deletes every auto-draft whose post_date is
  * more than seven days old, and post_date is an ordinary field. Same outcome, no meta
  * needed.
  *
  * Refused rather than routed to the delete tool or held by argument. The tool that does
  * this honestly is wp_delete_post, which the approval gate holds by its name, and a second
  * door that merely behaves like it would have to be found and held again by every future
  * tool that takes a status. A refusal is closed by construction.
  *
  * @return string|null The refusal, or null when the status is fine to write.
  */
  public static function status_write_refusal( $status, string $noun = 'post' ): ?string {
    if ( !is_scalar( $status ) ) {
      return null;
    }
    $status = strtolower( trim( (string) $status ) );
    if ( $status === 'trash' ) {
      return 'Setting a status of "trash" is refused here: a trashed ' . $noun . ' is deleted for good by WordPress\'s daily clean-up, so this is a deletion by another name and the write level does not delete. Use wp_delete_' . $noun . ', which says whether the result can be restored and is held for approval where that is switched on.';
    }
    if ( $status === 'auto-draft' ) {
      return 'Setting a status of "auto-draft" is refused here: WordPress deletes old auto-drafts for good in its daily clean-up, so this is a deletion by another name. Use wp_delete_' . $noun . '.';
    }
    // A comment marked spam is permanently deleted once it is old enough by any anti-spam
    // plugin that purges its spam queue: Akismet, which ships with WordPress, deletes every
    // 'spam' comment older than fifteen days from a daily event it registers unconditionally.
    // Measured: an old comment moved to spam was gone the next run, while the same comment left
    // approved, and a recent one moved to spam, both survived. So 'spam' on a comment is the
    // comment's version of 'trash'. Marking spam is a moderation decision for a person in
    // wp-admin; wp_delete_comment is the tool that says it is a deletion.
    if ( $noun === 'comment' && $status === 'spam' ) {
      return 'Setting a comment to "spam" is refused here: anti-spam plugins permanently delete old spam in their daily clean-up (Akismet does, after fifteen days), so this is a deletion by another name and the write level does not delete. A person can mark spam in wp-admin; wp_delete_comment is the tool that says what it does and is held for approval where that is switched on.';
    }
    return null;
  }

  /**
  * Meta keys core uses to decide what to delete, which no tool may write.
  *
  * The _wp_trash_meta_ family is the schedule wp_scheduled_delete() reads (see
  * status_write_refusal()). Only core's own wp_trash_post() and wp_untrash_post() may touch
  * it, and trash_meta_net() lets exactly those through. Matched case-insensitively and
  * ignoring trailing spaces, because the meta_key column compares that way: "_WP_TRASH_META_TIME "
  * is the same row to every query WordPress runs. And accents, ignorable characters and expansions
  * too, on the collations that have them (see GMCP_Meta_Key, which measured what they equal).
  *
  * Not on the list, and why: _edit_lock only says who is editing, _wp_desired_post_slug only
  * names the slug an untrash restores and _wp_old_slug only adds a redirect from a slug the
  * writer could already have set directly. Each is reachable by an ordinary content edit and
  * none of them removes anything.
  *
  * @return string|null The refusal, or null when the key is fine.
  */
  /**
  * The refusal for the meta key that names an attachment's FILE, asked by the generic meta
  * writers and not by the WordPress-level net.
  *
  * `_wp_attached_file` is the path wp_download_request streams, and the access rule that tool
  * applies (an unattached file, or one on an unpublished post, is admin only; a submission's
  * upload is never served below admin) is a rule about the attachment ROW. Repointing the row
  * at another file under uploads moves the file out from under the rule: a readwrite key set
  * `_wp_attached_file` on an ordinary published attachment to the relative path of a form
  * submission's upload and downloaded it through that one (measured). Nothing a generic meta
  * tool legitimately does needs the key, because the media tools write it through core
  * (update_attached_file) and not through these writers. It is not in the net: the same core
  * call runs under wp_upload_media while a tool is in flight and has to keep working.
  *
  * @return string|null The refusal, or null for any other key.
  */
  public static function attachment_file_meta_refusal( $key ): ?string {
    if ( !is_string( $key ) || $key === '' || !GMCP_Meta_Key::is( $key, '_wp_attached_file' ) ) {
      return null;
    }
    return 'The meta key "' . $key . '" names the file an attachment serves, and the generic meta tools do not write it: pointing one attachment at another file under uploads would hand over, to anyone who can download the first, a file the second one\'s access rules keep from them. Upload the file as a new attachment instead (wp_upload_request).';
  }

  /**
  * Meta keys that hold a page builder's LAYOUT, which no generic tool may read or write.
  *
  * A policy about a key belongs to the key, not to the tool that writes it: the Beaver Builder
  * group withholds node settings and layout css/js, and that withholding would be decoration if
  * wp_get_post_meta returned `_fl_builder_data` and wp_update_post_meta could rewrite it. This
  * is the same rule the WPForms post type got, one level down — a post-type rule cannot help
  * here, because a layout hangs off an ordinary page.
  *
  * Measured on Beaver Builder 2.11.1.2: `_fl_builder_data` is a serialised array of nodes whose
  * settings hold the content and, for the html module, arbitrary markup including script;
  * `_fl_builder_data_settings` holds layout-level css and js that run on every page using the
  * layout. So a read hands over a script payload and a write installs one.
  *
  * Filterable, because the category is "a builder's layout", not one plugin.
  *
  * @return string[] Lowercased meta keys.
  */
  public static function layout_meta_keys(): array {
    return array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'gmcp_layout_meta_keys', [
      '_fl_builder_data',
      '_fl_builder_draft',
      '_fl_builder_data_settings',
      '_fl_builder_draft_settings',
      '_fl_builder_enabled',
    ] ) ) );
  }

  /**
  * The refusal for a layout meta key, or null when the key is fine.
  *
  * Asked by the generic meta readers and writers through generic_writer_meta_refusal(), and
  * NOT by the WordPress-level net: the builder writes these keys from its own save hook.
  */
  public static function layout_meta_refusal( $key ): ?string {
    if ( !is_string( $key ) || $key === '' ) {
      return null;
    }
    // Judged as the database compares the key, not as it was typed: on a collation that ignores
    // accents "_fl_builder_dáta" is the "_fl_builder_data" row, so a comparison of the typed
    // spelling refused only the people who spell it right. @see GMCP_Meta_Key.
    $hit = false;
    foreach ( self::layout_meta_keys() as $layout_key ) {
      if ( GMCP_Meta_Key::is( $key, $layout_key ) ) {
        $hit = true;
        break;
      }
    }
    if ( !$hit ) {
      return null;
    }
    return 'The meta key "' . $key . '" holds a page builder layout, and the generic meta tools neither read nor write it: a node\'s settings is the content and the html module makes it executable, and the layout settings hold css and js that run on every page using it. Reading it would hand over a script payload and writing it would install one, with no restore tool behind it. Use the builder\'s own tools to read the structure (node types, counts, whether it carries custom code), or its editor in wp-admin to change it. A site can extend the list with the gmcp_layout_meta_keys filter.';
  }

  public static function protected_meta_refusal( $key ): ?string {
    if ( !is_string( $key ) ) {
      return null;
    }
    // The key as the database will compare it, not as it was typed: "_wp_trash_meta_tíme" is the
    // "_wp_trash_meta_time" row to every query on a collation that ignores accents. @see GMCP_Meta_Key.
    if ( GMCP_Meta_Key::starts_with( $key, '_wp_trash_meta_' ) ) {
      return 'The meta key "' . $key . '" is refused: WordPress deletes posts and comments for good on the schedule these keys hold, so writing one is a deletion by another name. Use wp_delete_post.';
    }
    // NOT the page builder's layout keys, although they are refused to the generic meta tools
    // too. This function is also what the net under the WordPress meta functions asks while a
    // tool call is in flight, and the builder writes its own keys from inside an ordinary save:
    // Beaver Builder copies a page's layout onto the revision from its save_post hook, so with
    // the layout keys here wp_update_post on any Beaver page threw halfway through (measured:
    // the title was written and the tool answered "failed"). @see generic_writer_meta_refusal().
    return null;
  }

  /**
  * The key rules the generic meta WRITERS apply and the net under WordPress does not: a page
  * builder's layout and the file an attachment serves. Asked beside protected_meta_refusal() by
  * the meta tools, copy, meta_input and the REST meta argument. Not in the net, because the
  * builder and core write these keys themselves from inside a save or an upload, and a refusal
  * there fails a legitimate write after part of it is done.
  */
  public static function generic_writer_meta_refusal( $key ): ?string {
    return self::layout_meta_refusal( $key ) ?? self::attachment_file_meta_refusal( $key );
  }

  /**
  * The posts core is trashing, untrashing or deleting right now, whose own bookkeeping may be
  * written. A set, not one id: deleting a post deletes its revisions from inside the call, so
  * two deletions are open at once and the outer one still has meta to clear after the inner
  * one has finished.
  *
  * @var array<int,true>
  */
  private static $trash_meta_posts = [];

  /**
  * The same refusal for every writer, not only the tools that remember to ask.
  *
  * An ACF field, an Elementor save or another plugin's tool writes meta through the same
  * three functions, so the tool-level refusal alone would be a policy about one tool. Active
  * only while a tool call is in flight, so wp-admin, cron and wp-cli are untouched.
  */
  public static function install_trash_meta_net(): void {
    foreach ( [ 'add', 'update', 'delete' ] as $op ) {
      add_filter( $op . '_post_metadata', [ __CLASS__, 'trash_meta_net' ], PHP_INT_MIN, 3 );
    }
    add_filter( 'update_post_metadata_by_mid', [ __CLASS__, 'trash_meta_net_by_mid' ], PHP_INT_MIN, 4 );
    add_filter( 'delete_post_metadata_by_mid', [ __CLASS__, 'trash_meta_net_by_mid' ], PHP_INT_MIN, 2 );
    // Core's own trash, untrash and permanent delete write or clear the key in between these
    // pairs (wp_delete_post() removes it before it removes the row). Scoped to the one post, so
    // it is not a window for anything else.
    foreach ( [ 'wp_trash_post', 'untrash_post', 'before_delete_post' ] as $hook ) {
      add_action( $hook, function ( $post_id ) {
        self::$trash_meta_posts[ (int) $post_id ] = true;
      }, PHP_INT_MIN );
    }
    // wp_delete_attachment() clears the key before it fires any action, so the only door in
    // front of it is its pre-filter. Last, and only when nobody short-circuited the delete.
    add_filter( 'pre_delete_attachment', function ( $check, $post = null ) {
      if ( $check === null && $post instanceof WP_Post ) {
        self::$trash_meta_posts[ (int) $post->ID ] = true;
      }
      return $check;
    }, PHP_INT_MAX, 2 );
    foreach ( [ 'trashed_post', 'untrashed_post', 'deleted_post' ] as $hook ) {
      add_action( $hook, function ( $post_id ) {
        unset( self::$trash_meta_posts[ (int) $post_id ] );
      }, PHP_INT_MAX );
    }
  }

  /** Whether a tool call has started and not yet finished in this request. */
  public static function tool_in_flight(): bool {
    return did_action( 'gmcp_tool_start' ) > did_action( 'gmcp_tool_called' );
  }

  public static function trash_meta_net( $check, $object_id = 0, $meta_key = '' ) {
    if ( self::tool_in_flight() && !isset( self::$trash_meta_posts[ (int) $object_id ] ) ) {
      $why = self::protected_meta_refusal( $meta_key );
      if ( $why !== null ) {
        throw new GMCP_Policy_Refusal( $why );
      }
    }
    return $check;
  }

  public static function trash_meta_net_by_mid( $check, $meta_id = 0, $meta_value = null, $meta_key = false ) {
    if ( self::tool_in_flight() ) {
      $row = function_exists( 'get_metadata_by_mid' ) ? get_metadata_by_mid( 'post', (int) $meta_id ) : null;
      $on = $row ? (int) $row->post_id : 0;
      if ( !isset( self::$trash_meta_posts[ $on ] ) ) {
        foreach ( [ $meta_key, $row->meta_key ?? null ] as $candidate ) {
          $why = self::protected_meta_refusal( $candidate );
          if ( $why !== null ) {
            throw new GMCP_Policy_Refusal( $why );
          }
        }
      }
    }
    return $check;
  }

  #endregion

  /**
  * Post types that are form submissions, which the generic post READERS must not return.
  *
  * Flamingo (the message store behind Contact Form 7) and Ninja Forms keep a submission as a
  * post, with the answers, and for Flamingo the visitor's IP address and user agent, in post
  * meta. wp_get_post_meta is a read-level tool that returns any key on any post, so without
  * this the admin-level submission tools of the forms group would stand beside a read-level
  * door to the same rows, and "personal data is admin level" would be a sentence about the
  * tool and not about the data. As elsewhere in this class, the policy belongs to the post
  * type and is asked by each reader.
  *
  * The names are Flamingo's and Ninja Forms' own post types (UNVERIFIED: written from memory
  * of their source). A site with another form store that uses posts adds its type through
  * gmcp_personal_data_post_types.
  *
  * Reads only. Writing one of these rows is not what this guards, and no tool in this plugin
  * writes a submission.
  *
  * @return string[]
  */
  public static function personal_data_post_types(): array {
    return (array) apply_filters( 'gmcp_personal_data_post_types', [
      'flamingo_inbound', 'flamingo_contact', 'flamingo_outbound', 'nf_sub',
    ] );
  }

  /** "a flamingo_inbound", "an attachment": a type name with the right indefinite article. */
  private static function with_article( string $noun ): string {
    return ( preg_match( '/^[aeiou]/i', $noun ) === 1 ? 'an ' : 'a ' ) . $noun;
  }

  /**
  * @param int    $post_id The post a read is aimed at.
  * @param string $type    A post type named directly, for a listing that names no post.
  * @return true|string True when the read is allowed, otherwise the refusal.
  */
  public static function personal_data_post_guard( int $post_id, string $type = '' ) {
    $held = array_map( 'strtolower', array_map( 'strval', self::personal_data_post_types() ) );
    if ( $type !== '' ) {
      $chain = [ strtolower( $type ) ];
    }
    else {
      $chain = $post_id > 0 ? self::post_chain_types( $post_id ) : [];
    }
    $hit = null;
    foreach ( $chain as $candidate ) {
      if ( $candidate !== '' && in_array( $candidate, $held, true ) ) {
        $hit = $candidate;
        break;
      }
    }
    if ( $hit === null ) {
      return true;
    }
    return 'A ' . $hit . ' is a form submission: personal data a member of the public typed, stored as a post. The generic post readers do not return it, because they are available to a read-only key and a submission is admin-level data. '
      . ( $chain[0] !== $hit ? 'This post belongs to one (it is ' . self::with_article( $chain[0] ) . ' under it), and a revision, an upload or a child of a submission carries the same data. ' : '' )
      . 'Use the form tools for that plugin (cf7_, fluentform_ or ninjaform_), which return it at admin level, never return passwords, file uploads, IP addresses or user agents, and report whether the store is there at all.';
  }

  /**
  * Whether a taxonomy exists only to classify form submissions, and the refusal if so.
  *
  * Asked by the term LISTING, which names no post and so cannot ask the post guard. A term
  * attached to a submission is not visitor data in the plugins checked (Flamingo assigns a
  * channel only when the term already exists, and its contact tags are taken from existing
  * terms), but a taxonomy whose every object type is a submission type is, by construction,
  * a vocabulary about submissions, and a plugin that mints its terms from what a visitor
  * typed would put that text in the listing. A taxonomy shared with an ordinary type is not
  * refused: its terms are ordinary content, and which of them sit on a submission is only
  * reachable through the post, which the post guard refuses.
  *
  * @return true|string
  */
  public static function personal_data_taxonomy_guard( string $taxonomy ) {
    $tax = get_taxonomy( $taxonomy );
    if ( !$tax || empty( $tax->object_type ) ) {
      return true;
    }
    $held = array_map( 'strtolower', array_map( 'strval', self::personal_data_post_types() ) );
    foreach ( (array) $tax->object_type as $object_type ) {
      if ( !in_array( strtolower( (string) $object_type ), $held, true ) ) {
        return true;
      }
    }
    return 'The ' . $taxonomy . ' taxonomy classifies form submissions only, and the terms of such a taxonomy are not listed through the generic term tools: a submission is personal data, and what a vocabulary built around submissions holds is not something a read-only key is shown. '
      . 'The form tools for that plugin (cf7_, fluentform_ or ninjaform_) report on submissions at admin level.';
  }

  /**
  * The two read policies about a post, asked together: a form submission is never returned by
  * a generic reader, and a commerce post is returned only at admin level.
  *
  * Asked by every tool that takes a post id and COPIES from it or otherwise carries what it
  * holds somewhere else. Those are reads wearing a write's name. wp_copy_post_meta guarded
  * only the post it wrote to, so a readwrite key copied every meta key of a Flamingo message
  * (its _meta holds the visitor's IP address and user agent) or of an order onto its own draft
  * and read the draft back with wp_get_post_meta, which is a read the guards above would have
  * refused on the original.
  *
  * @return true|string True when the post may be read through a generic tool, otherwise the refusal.
  */
  public static function post_read_guard( int $post_id ) {
    $held = self::personal_data_post_guard( $post_id );
    if ( $held !== true ) {
      // Below admin the answer is the one a post that does not exist gets, word for word, so
      // a key that can only read cannot walk ids and learn which are submissions, which are
      // their revisions or uploads, and which are nothing. The explanation stays for admin,
      // who can list submissions through the form tools anyway, and in the audit log, which
      // records the call and the reply it got. @see post_not_found_text().
      return self::caller_access_level() === 'admin' ? $held : self::post_not_found_text( $post_id );
    }
    return self::commerce_post_read_guard( $post_id );
  }

  /**
  * What every generic reader says about a post id it cannot return, and what it says about
  * one that does not exist. One function, so the two cannot drift apart.
  */
  public static function post_not_found_text( int $post_id ): string {
    return 'No post with ID ' . $post_id . '.';
  }

  /**
  * The error code that goes with a refusal from post_read_guard(): the one "not found" carries
  * when the answer is the not-found sentence, so the code does not give the difference away.
  */
  public static function read_refusal_code( int $post_id, string $held ): int {
    return $held === self::post_not_found_text( $post_id ) ? -32602 : -32600;
  }

  /**
  * A post may not be retyped out of, or into, a form-submission type.
  *
  * Retyping is the other way to make a read guard about a post type stop applying: the guard
  * is asked "what type is this post", so a message retyped to "post" is an ordinary post whose
  * body wp_get_post returns, and Flamingo builds that body from the submission's fields,
  * consent and meta, the visitor's IP address and user agent among them. Retyping INTO one
  * is refused for the same family of reasons as creating one (a forged submission that the
  * form tools then report as received), and costs a legitimate caller nothing.
  *
  * A retype of a commerce post is refused by commerce_post_guard(), which refuses every
  * generic write to one.
  *
  * @param int    $post_id     The post being changed, or 0 for a creation.
  * @param string $target_type The post type the write would leave it with.
  * @return true|string
  */
  public static function personal_data_retype_guard( int $post_id, string $target_type, string $verb = 'Updating' ) {
    $target = strtolower( trim( $target_type ) );
    $current = $post_id > 0 ? strtolower( (string) get_post_type( $post_id ) ) : '';
    if ( $target === $current ) {
      return true;
    }
    $held = array_map( 'strtolower', array_map( 'strval', self::personal_data_post_types() ) );
    $from = $current !== '' && in_array( $current, $held, true );
    $to = $target !== '' && in_array( $target, $held, true );
    if ( !$from && !$to ) {
      return true;
    }
    return ( $post_id > 0 ? $verb . ' a post to change its type ' . ( $from ? 'from' : 'to' ) . ' ' . ( $from ? $current : $target ) : 'Creating a ' . $target )
      . ' is refused: ' . ( $from ? $current : $target ) . ' is a form submission, personal data that the generic post tools never return. '
      . 'Retyping a submission would make it an ordinary post those tools read, and a submission made by hand is not one a visitor sent. The form tools read submissions; nothing here writes one.';
  }

  #region What hangs from a submission: writes, moves and pointers

  /** The form-submission post types, lowercased. @see personal_data_post_types() */
  private static function held_personal_types(): array {
    return array_map( 'strtolower', array_map( 'strval', self::personal_data_post_types() ) );
  }

  /**
  * The nearest post type in a post's chain (itself, then every parent above it) that is a
  * form-submission type, or null. Commerce types too when $commerce is set, for the questions
  * where an order's tree must be as closed as a submission's (moving a row into or out of it).
  *
  * @param int|WP_Post $post
  */
  public static function guarded_tree_hit( $post, bool $commerce = false ): ?string {
    $held = self::held_personal_types();
    if ( $commerce ) {
      $held = array_merge( $held, self::commerce_post_types() );
    }
    foreach ( self::post_chain_types( $post ) as $type ) {
      if ( $type !== '' && in_array( $type, $held, true ) ) {
        return $type;
      }
    }
    return null;
  }

  /** The nearest form-submission type in a post's chain, or null. @see guarded_tree_hit() */
  public static function personal_data_chain_hit( $post ): ?string {
    return self::guarded_tree_hit( $post, false );
  }

  /**
  * Whether a generic WRITE may touch this post, as far as the form-submission policy goes.
  *
  * The read guards keep a submission, and everything that hangs from it through post_parent,
  * away from the generic readers. That is only true while the tree stays as it is, and every
  * generic writer could change it: re-parent a revision of a submission and it was an ordinary
  * post whose body any key read, re-parent a visitor's upload under a page and it was readable
  * media, comment_post_ID did the same for a comment. So the policy is symmetric with the
  * commerce one, which has always refused every generic write to an order: no generic write
  * touches a submission or anything in its tree, at any access level. Nothing legitimate is
  * lost, because nobody edits a visitor's submission through an administration agent and the
  * form tools only read.
  *
  * Called from commerce_post_guard(), which every generic writer already asks, and by the
  * writers that never asked it. The net below asks it again for the writers that ask neither.
  *
  * @param bool $descendants_only Refuse only a post that hangs FROM a submission, and let the
  *                               submission's own row through. The net uses it: Flamingo records
  *                               a contact (a post of a guarded type, with meta and terms) every
  *                               time a user profile is saved, so refusing every write to such a
  *                               row while a tool call is in flight broke wp_update_user and
  *                               wp_create_user on a site that has it. The generic tools ask the
  *                               strict question.
  * @return true|string
  */
  public static function personal_data_write_guard( int $post_id, string $verb = 'Changing', bool $descendants_only = false ) {
    if ( $post_id <= 0 ) {
      return true;
    }
    $hit = self::personal_data_chain_hit( $post_id );
    if ( $hit === null ) {
      return true;
    }
    $own = strtolower( (string) get_post_type( $post_id ) );
    if ( $descendants_only && $own === $hit ) {
      return true;
    }
    return $verb . ' post #' . $post_id . ' is refused: '
      . ( $own === $hit ? 'it is ' . self::with_article( $hit ) : 'it is ' . self::with_article( $own ) . ' that hangs from ' . self::with_article( $hit ) ) . ', a form submission. '
      . 'Nothing here edits, moves, annotates or deletes a visitor\'s submission or anything in its tree (its revisions, uploads and comments), at any access level: '
      . 'the form tools only read, and a write through the generic tools would change what the readers return, move a row out of the tree they keep from the readers, or destroy the only record of what the visitor sent. '
      . 'Handle submissions in wp-admin or with the form plugin.';
  }

  /**
  * Whether a post may be given this parent, when that changes what it hangs from.
  *
  * Which readers may see a post is decided by the chain above it, so changing the parent is
  * changing who may read it: moving a row OUT of a guarded tree makes it readable, moving one
  * INTO it hides it from every generic reader (a key could bury one of its own published pages
  * under a submission). Both are refused, and for the commerce trees as well as the
  * submission ones, because the commerce read policy follows the same chain.
  *
  * @param int  $post_id    The post being written, or 0 for a creation.
  * @param int  $new_parent The parent the write leaves it with.
  * @param bool $caller_named_it The parent was named by the caller of a tool (a create tool's
  *                         `parent` or `post` argument), as opposed to by a plugin's own code
  *                         under the net. A creation is then judged against commerce trees too.
  * @return true|string
  */
  public static function tree_move_guard( int $post_id, int $new_parent, string $verb = 'Changing the parent of', bool $caller_named_it = false ) {
    $current = $post_id > 0 ? (int) get_post_field( 'post_parent', $post_id ) : 0;
    if ( $new_parent === $current ) {
      return true;
    }
    $from = $post_id > 0 ? self::guarded_tree_hit( $post_id, true ) : null;
    // A creation the net sees is judged against submission trees only. Commerce plugins make
    // child rows of an order themselves (a refund hangs from its order), so refusing every
    // new child of a commerce post under the net would break their own tools. A creation whose
    // parent the CALLER named is judged against both: a new page parented under an order is
    // unreadable below admin, so a write key could otherwise hide its own page (REST
    // create_pages takes `parent`, create_media takes `post`). An EXISTING post is moved into or
    // out of either.
    $to = $new_parent > 0 ? self::guarded_tree_hit( $new_parent, $post_id > 0 || $caller_named_it ) : null;
    if ( $from === null && $to === null ) {
      return true;
    }
    return $verb . ( $post_id > 0 ? ' post #' . $post_id : ' a new post' ) . ' is refused: '
      . ( $from !== null ? 'it is in the tree of a ' . $from : 'the new parent (#' . $new_parent . ') is in the tree of a ' . $to ) . '. '
      . 'Which readers may see a post is decided by what it hangs from, so moving a row out of such a tree would make personal data readable and moving one into it would hide it from every generic reader. '
      . 'Neither is done through these tools.';
  }

  /**
  * Whether a comment may be written, as far as the post it is on (or would be moved to) goes.
  *
  * @param int      $comment_id  The comment, or 0 for a creation.
  * @param int|null $new_post_id The post a creation names, or the one an update moves it to.
  * @return true|string
  */
  public static function comment_write_guard( int $comment_id, ?int $new_post_id = null, string $verb = 'Changing' ) {
    $current = 0;
    if ( $comment_id > 0 ) {
      $comment = get_comment( $comment_id );
      $current = $comment ? (int) $comment->comment_post_ID : 0;
    }
    $moving = $comment_id > 0 && $new_post_id !== null && $new_post_id !== $current;
    $targets = [ $current ];
    if ( $new_post_id !== null && $new_post_id !== $current ) {
      $targets[] = (int) $new_post_id;
    }
    foreach ( $targets as $post_id ) {
      $hit = $post_id > 0 ? self::guarded_tree_hit( $post_id, $moving ) : null;
      if ( $hit !== null ) {
        return $verb . ( $comment_id > 0 ? ' comment #' . $comment_id : ' a comment' ) . ' is refused: '
          . ( $moving ? 'it would be moved into or out of the tree of a ' . $hit : 'it is on a post in the tree of a ' . $hit . ', a form submission' ) . '. '
          . 'A comment is read as the post it is on, so writing, moving or deleting one on a submission or anything hanging from it is refused at every access level, '
          . 'for the reason post writes to one are: nothing here changes what the readers keep from a key that can only read.';
      }
    }
    return true;
  }

  /**
  * Meta keys whose VALUE is another post's id that something renders or reads from, and the
  * refusal when that id is in a guarded tree.
  *
  * A featured image is displayed on the page that holds it and returned by the snapshot; a
  * menu item copies its title and its URL from the object it points at, into the public
  * navigation and into wp_get_menu_items. Pointing either at a visitor's submission or an
  * upload of one is a read of it by another name, and a write that "touches only an
  * ordinary post".
  *
  * @param mixed $value The value being written.
  * @return string|null
  */
  public static function pointer_meta_refusal( $key, $value, int $object_id = 0 ): ?string {
    if ( !is_string( $key ) ) {
      return null;
    }
    // Judged as the database will compare it, not as typed. On a collation that ignores accents
    // "_thumbnaíl_id" updates the "_thumbnail_id" row (or, with none, files a row every later
    // query for "_thumbnail_id" matches), so a rule keyed on the typed spelling was a rule for
    // the people who spell it right. One verdict per spelling the collations disagree on. @see GMCP_Meta_Key.
    foreach ( GMCP_Meta_Key::folds( $key ) as $needle ) {
      $why = self::pointer_meta_refusal_for( $needle, $value, $object_id );
      if ( $why !== null ) {
        return $why;
      }
    }
    return null;
  }

  /** The folded spelling of a meta key, the one every key policy judges. @see GMCP_Meta_Key::folds() */
  public static function meta_key_fold( $key ): string {
    return GMCP_Meta_Key::fold( $key );
  }

  private static function pointer_meta_refusal_for( string $needle, $value, int $object_id ): ?string {
    $target = 0;
    $what = '';
    if ( $needle === '_thumbnail_id' ) {
      $target = is_scalar( $value ) ? (int) $value : 0;
      $what = 'a featured image';
    }
    elseif ( $needle === '_menu_item_object_id' ) {
      $kind = $object_id > 0 ? (string) get_post_meta( $object_id, '_menu_item_type', true ) : '';
      if ( !in_array( $kind, [ 'taxonomy', 'custom', 'post_type_archive' ], true ) ) {
        $target = is_scalar( $value ) ? (int) $value : 0;
        $what = 'a menu item';
      }
    }
    elseif ( $needle === '_menu_item_type' ) {
      // Judged in the other order too: an item made a taxonomy item, pointed at a number and
      // then switched back to a post item would otherwise carry the id through.
      if ( is_scalar( $value ) && (string) $value === 'post_type' && $object_id > 0 ) {
        $target = (int) get_post_meta( $object_id, '_menu_item_object_id', true );
        $what = 'a menu item';
      }
    }
    elseif ( $needle === '_menu_item_object' ) {
      $named = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
      if ( $named !== '' && in_array( $named, array_merge( self::held_personal_types(), self::commerce_post_types() ), true ) ) {
        return 'Pointing a menu item at the ' . $named . ' type is refused: its rows are personal data and the public navigation would print them.';
      }
    }
    if ( $target <= 0 ) {
      return null;
    }
    $hit = self::guarded_tree_hit( $target, true );
    if ( $hit === null ) {
      return null;
    }
    return 'Using post #' . $target . ' as ' . $what . ' is refused: it is, or hangs from, a ' . $hit . '. '
      . 'A featured image is shown on the public page that holds it and a menu item prints the title and URL of what it points at, so this would publish, and let every reader see, something the generic readers keep from them.';
  }

  /**
  * The refusal for one post-meta write, from every route: the policy about the post it is on,
  * and the policy about a value that points at another post.
  */
  public static function meta_write_refusal( int $object_id, $key, $value = null ): ?string {
    if ( $object_id > 0 ) {
      $held = self::personal_data_write_guard( $object_id, 'Writing meta on', true );
      if ( $held !== true ) {
        return $held;
      }
    }
    return self::pointer_meta_refusal( $key, $value, $object_id );
  }

  /**
  * What the arguments of a REST create or update tool may not say, judged BEFORE the controller
  * is asked.
  *
  * The controller inserts the row and then applies the featured image, the parent-like fields and
  * the registered meta, and the net throws from inside that tail. A refusal from there leaves the
  * row it had already inserted, under a reply that says the write failed: measured, create_posts
  * with featured_media naming a form submission's upload answered "refused" and a draft stood in
  * the table. Asked here, nothing has been written when it says no. The net remains the backstop
  * for what this does not name, and handle_call() removes a row the net refused after it was made.
  *
  * @param int   $post_id The post an update names, or 0 for a creation.
  * @param array $args    The tool's arguments.
  * @return string|null The refusal, or null when nothing in them is refused.
  */
  public static function rest_write_refusal( int $post_id, array $args ): ?string {
    if ( array_key_exists( 'featured_media', $args ) && is_scalar( $args['featured_media'] ) ) {
      $why = self::pointer_meta_refusal( '_thumbnail_id', $args['featured_media'], $post_id );
      if ( $why !== null ) {
        return $why;
      }
    }
    foreach ( [ 'parent', 'post' ] as $field ) {
      if ( array_key_exists( $field, $args ) && is_scalar( $args[ $field ] ) ) {
        $why = self::tree_move_guard( $post_id, (int) $args[ $field ], $post_id > 0 ? 'Changing the parent of' : 'Creating', true );
        if ( $why !== true ) {
          return $why;
        }
      }
    }
    if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
      foreach ( $args['meta'] as $key => $value ) {
        $why = self::protected_meta_refusal( (string) $key ) ?? self::generic_writer_meta_refusal( (string) $key ) ?? self::pointer_meta_refusal( (string) $key, $value, $post_id );
        if ( $why !== null ) {
          return $why;
        }
      }
    }
    return null;
  }

  private static $personal_net_installed = false;

  /**
  * The same refusals under WordPress, for writers that never ask the guards above: every
  * route to a post, its meta, its terms, its comments or its deletion that goes through
  * WordPress passes one of these hooks. Active only while a tool call is in flight, so
  * wp-admin, cron and wp-cli (and the form plugins' own saves) are untouched. It throws, for
  * the reason restrict_caller_to_drafts() does: a filter that answered false would let a
  * writer report success over a write that did not happen.
  *
  * It refuses what hangs FROM a submission (a revision, an upload) being written, deleted or
  * given other terms, a post or comment moving into or out of such a tree, and a pointer into
  * one. It does NOT refuse a write to the submission's own row, because a form plugin keeps
  * such rows itself and does so from hooks a tool call can fire (Flamingo records a contact when
  * a profile is saved); the generic tools refuse that write themselves, through
  * commerce_post_guard().
  *
  * What it cannot see: a plugin writing the posts or comments table with $wpdb directly,
  * wp_set_comment_status() (no hook in front of it) and wp_insert_comment() (none either; the
  * comment tool asks the guard itself, and wp_new_comment() is covered).
  */
  public static function install_personal_data_net(): void {
    if ( self::$personal_net_installed ) {
      return;
    }
    self::$personal_net_installed = true;
    add_filter( 'wp_insert_post_data', [ __CLASS__, 'pd_net_insert' ], PHP_INT_MAX - 1, 4 );
    add_filter( 'wp_insert_attachment_data', [ __CLASS__, 'pd_net_insert' ], PHP_INT_MAX - 1, 4 );
    foreach ( [ 'add', 'update', 'delete' ] as $op ) {
      add_filter( $op . '_post_metadata', [ __CLASS__, 'pd_net_meta' ], PHP_INT_MIN + 1, 4 );
    }
    add_filter( 'update_post_metadata_by_mid', [ __CLASS__, 'pd_net_meta_by_mid' ], PHP_INT_MIN + 1, 4 );
    add_filter( 'delete_post_metadata_by_mid', [ __CLASS__, 'pd_net_meta_by_mid' ], PHP_INT_MIN + 1, 2 );
    foreach ( [ 'pre_delete_post', 'pre_delete_attachment', 'pre_trash_post', 'pre_untrash_post' ] as $hook ) {
      add_filter( $hook, [ __CLASS__, 'pd_net_remove' ], PHP_INT_MIN + 1, 2 );
    }
    add_action( 'add_term_relationship', [ __CLASS__, 'pd_net_terms' ], PHP_INT_MIN + 1, 3 );
    add_action( 'delete_term_relationships', [ __CLASS__, 'pd_net_terms' ], PHP_INT_MIN + 1, 3 );
    add_filter( 'wp_update_comment_data', [ __CLASS__, 'pd_net_comment_update' ], PHP_INT_MAX - 1, 3 );
    add_filter( 'preprocess_comment', [ __CLASS__, 'pd_net_comment_new' ], PHP_INT_MIN + 1 );
    foreach ( [ 'delete_comment', 'trash_comment' ] as $hook ) {
      add_action( $hook, [ __CLASS__, 'pd_net_comment_remove' ], PHP_INT_MIN + 1, 1 );
    }
  }

  private static function pd_refuse( $why ): void {
    if ( $why !== true && $why !== null ) {
      throw new GMCP_Policy_Refusal( (string) $why );
    }
  }

  public static function pd_net_insert( $data, $postarr = [], $unsanitized = [], $update = false ) {
    if ( !self::tool_in_flight() || !is_array( $data ) ) {
      return $data;
    }
    $id = (int) ( $postarr['ID'] ?? 0 );
    if ( $update && $id > 0 ) {
      self::pd_refuse( self::personal_data_write_guard( $id, 'Updating', true ) );
    }
    $parent = (int) ( $data['post_parent'] ?? 0 );
    self::pd_refuse( self::tree_move_guard( $update ? $id : 0, $parent, $update ? 'Changing the parent of' : 'Creating' ) );
    return $data;
  }

  public static function pd_net_meta( $check, $object_id = 0, $meta_key = '', $meta_value = null ) {
    if ( self::tool_in_flight() ) {
      self::pd_refuse( self::meta_write_refusal( (int) $object_id, $meta_key, $meta_value ) );
    }
    return $check;
  }

  public static function pd_net_meta_by_mid( $check, $meta_id = 0, $meta_value = null, $meta_key = false ) {
    if ( self::tool_in_flight() ) {
      $row = function_exists( 'get_metadata_by_mid' ) ? get_metadata_by_mid( 'post', (int) $meta_id ) : null;
      if ( $row ) {
        self::pd_refuse( self::meta_write_refusal( (int) $row->post_id, $meta_key !== false ? $meta_key : $row->meta_key, $meta_value ) );
      }
    }
    return $check;
  }

  public static function pd_net_remove( $check, $post = null ) {
    if ( self::tool_in_flight() && $post instanceof WP_Post ) {
      self::pd_refuse( self::personal_data_write_guard( (int) $post->ID, 'Removing', true ) );
    }
    return $check;
  }

  public static function pd_net_terms( $object_id = 0, $unused = null, $taxonomy = '' ) {
    if ( !self::tool_in_flight() ) {
      return;
    }
    // Only a taxonomy that belongs to the post's own type is a post write: the action also
    // fires for users and links, whose ids can equal a post's.
    $post = get_post( (int) $object_id );
    $tax = $post ? get_taxonomy( (string) $taxonomy ) : null;
    if ( $post && $tax && in_array( $post->post_type, (array) $tax->object_type, true ) ) {
      self::pd_refuse( self::personal_data_write_guard( (int) $post->ID, 'Changing the terms of', true ) );
    }
  }

  public static function pd_net_comment_update( $data, $comment = [], $commentarr = [] ) {
    if ( self::tool_in_flight() && is_array( $data ) ) {
      $cid = (int) ( $data['comment_ID'] ?? $commentarr['comment_ID'] ?? 0 );
      self::pd_refuse( self::comment_write_guard( $cid, isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : null, 'Updating' ) );
    }
    return $data;
  }

  public static function pd_net_comment_new( $commentdata ) {
    if ( self::tool_in_flight() && is_array( $commentdata ) ) {
      self::pd_refuse( self::comment_write_guard( 0, (int) ( $commentdata['comment_post_ID'] ?? 0 ), 'Creating' ) );
    }
    return $commentdata;
  }

  public static function pd_net_comment_remove( $comment_id = 0 ) {
    if ( self::tool_in_flight() ) {
      self::pd_refuse( self::comment_write_guard( (int) $comment_id, null, 'Removing' ) );
    }
  }

  #endregion

  /**
  * Whether a post is an Elementor library template something still renders, and the
  * sentence to refuse with.
  *
  * Asked before deleting, trashing or unpublishing one. elementor_template_references
  * already answers what depends on a template and says in its own description that it
  * refuses nothing, so until now the answer was only as good as a caller's habit of asking
  * first. A page whose design vanishes shows nothing where it was, with no error on the
  * page and nothing in any log.
  *
  * Trashing counts, and that departs from how deletion is treated everywhere else here.
  * The trash is normally the recoverable half and is deliberately not gated; from a
  * referencing page's point of view a trashed template and a deleted one render
  * identically.
  *
  * This lives in core while the search lives in GMCP_Tools_Elementor, and it calls across
  * rather than keeping its own copy. Two reasons, pulling in opposite directions and both
  * satisfied. The guard cannot live with the Elementor tools, because that group is
  * optional and a guard that disappears when it is switched off is not a guard: the delete
  * that breaks the page comes from the content tools, which are always on. And it must not
  * reimplement the search, because a second implementation would be free to disagree with
  * the one doing the reporting, and the direction it would drift is a guard that waves
  * through a reference the tool can see. So the finder is static and asked directly, which
  * needs the class autoloaded and not constructed.
  *
  * @param string $verb Capitalised, and used as the sentence's subject: Deleting, Unpublishing.
  * @return true|string True when there is nothing to warn about, otherwise the refusal.
  */
  public static function template_reference_guard( int $post_id, string $verb ) {
    if ( $post_id <= 0 || get_post_type( $post_id ) !== 'elementor_library' ) {
      return true;
    }

    // The third route, and the one with no page to name. An Elementor Pro header, footer,
    // single or archive template is rendered through its display conditions ("Entire
    // Site", "All posts"), with no shortcode and no template_id anywhere, so the two
    // searches below find nothing and deleting the site's header went through. Read from
    // Pro's own two stores, the template's _elementor_conditions meta and the
    // elementor_pro_theme_builder_conditions option that caches them by location, and
    // asked whether or not Pro is active right now: conditions on a template are live the
    // moment Pro is, and a guard that waved the delete through while Pro was briefly off
    // would leave the site headerless when it came back.
    $conditions = self::theme_builder_conditions( $post_id );
    if ( $conditions ) {
      return $verb . ' template #' . $post_id . ' would remove a theme-builder template that Elementor Pro shows by its display conditions ('
        . implode( ', ', array_slice( $conditions, 0, 5 ) ) . ( count( $conditions ) > 5 ? ', …' : '' ) . '), not by any page that names it. '
        . 'A header, footer or archive applied this way disappears from every page it covers, with no error and nothing in any log. '
        . 'Remove its display conditions in Theme Builder first, or pass despite_references true to go ahead anyway.';
    }

    if ( !class_exists( 'GMCP_Tools_Elementor' ) ) {
      return true;
    }

    // The two routes a page takes to embed a template: the shortcode, or a widget's
    // template_id inside _elementor_data. A guard that knew only one would refuse half the
    // cases and wave the other half through with the same confidence. Display conditions,
    // the route with no page at all, are checked above.
    $rows = [];
    foreach ( [ 'shortcode_references', 'data_references' ] as $route ) {
      $found = GMCP_Tools_Elementor::$route( $post_id, 25 );
      foreach ( (array) ( $found['rows'] ?? [] ) as $row ) {
        $rows[] = $row;
      }
    }
    if ( !$rows ) {
      return true;
    }

    $names = [];
    foreach ( array_slice( $rows, 0, 5 ) as $row ) {
      $names[] = '#' . ( $row['id'] ?? 0 ) . ' "' . ( $row['title'] ?? '' ) . '" (' . ( $row['status'] ?? '' ) . ')';
    }
    $more = count( $rows ) > 5 ? ' and ' . ( count( $rows ) - 5 ) . ' more' : '';

    // A draft that references the template is counted and said separately. It breaks when
    // it is published, which is long after the template is gone and long after anyone
    // would connect the two, so it is worth refusing over and not worth alarming about in
    // the same words as a live page.
    $live = 0;
    foreach ( $rows as $row ) {
      if ( !empty( $row['live'] ) ) {
        $live++;
      }
    }
    $shape = $live === count( $rows )
      ? count( $rows ) . ' post(s), all of them live,'
      : $live . ' live post(s) and ' . ( count( $rows ) - $live ) . ' not yet published,';

    return $verb . ' template #' . $post_id . ' would leave ' . $shape
      . ' rendering nothing where its design is: ' . implode( ', ', $names ) . $more . '. '
      . 'There is no error on those pages when it happens and nothing in any log. '
      . 'Point them elsewhere first, ask elementor_template_references for the full picture, '
      . 'or pass despite_references true to go ahead anyway.';
  }

  /**
  * The display conditions Elementor Pro holds for a template, from either of its stores.
  *
  * Defensive throughout, because the shapes are Pro's and not this plugin's: the meta is
  * normally a list of strings such as "include/general", and the option maps a location to
  * template id => conditions. Anything that is not that shape is ignored rather than
  * trusted, and nothing here needs Pro to be loaded.
  *
  * @return string[] The conditions, or the locations it is assigned to, empty when none.
  */
  public static function theme_builder_conditions( int $post_id ): array {
    $found = [];
    $meta = get_post_meta( $post_id, '_elementor_conditions', true );
    if ( is_array( $meta ) ) {
      foreach ( $meta as $condition ) {
        if ( is_string( $condition ) && $condition !== '' && strpos( $condition, 'exclude/' ) !== 0 ) {
          $found[] = $condition;
        }
      }
    }
    $cache = get_option( 'elementor_pro_theme_builder_conditions' );
    if ( is_array( $cache ) ) {
      foreach ( $cache as $location => $templates ) {
        if ( is_array( $templates ) && array_key_exists( $post_id, $templates ) ) {
          $found[] = 'location ' . ( is_string( $location ) ? $location : 'unknown' );
        }
      }
    }
    return array_values( array_unique( $found ) );
  }

  /**
  * A copy of a value safe to keep a record of, or a refusal when it cannot be made one.
  *
  * The middle ground between holds_credential(), which refuses a whole value if any part
  * of it looks secret, and redact(), which keeps the shape and blanks the leaves. It
  * exists because the all-or-nothing answer was costing far more than it protected.
  *
  * Elementor stores its SVG icon registry under a "key" field holding a value like
  * "eicon-star". "key" is one of the credential patterns and the patterns match as
  * substrings, so the whole option was judged a credential and the change journal dropped
  * the previous value, which is how an ordinary page build ended up with several changes
  * marked permanently irreversible. The same substring rule reads "author" as "auth" and
  * "keywords" and "monkey" as "key", so this was never going to be one plugin's problem.
  *
  * Loosening the patterns was the obvious alternative and is the wrong one. Bare "key" is
  * genuinely how plugins store secrets, and a guard that fails open is the one direction
  * that cannot be walked back once a credential is on disk in a second place. So the
  * patterns are left exactly as strict as they were and the leaves they name are blanked
  * instead. The 98% of an icon registry that is an SVG path comes back; the thing that
  * looked like a secret does not.
  *
  * Two rules keep it honest.
  *
  * It must reach every shape holds_credential() reaches, or it is a hole rather than a
  * guard. That function unpacks JSON and serialized strings before judging them, so a
  * secret under an innocuous field name arrives as a string that redact() would have
  * copied out verbatim. Any string leaf that holds_credential() flags is therefore blanked
  * whole, rather than parsed and rebuilt: re-encoding a decoded blob is a second chance to
  * get something wrong in the direction that writes the secret down.
  *
  * It refuses rather than guesses. An object anywhere in the value ends the attempt,
  * because restoring one would put an array back where WordPress had an object, and a
  * restore that changes the type of what it restores is its own defect. Running past the
  * depth limit refuses for the same reason holds_credential() redacts there.
  *
  * @return array{0:bool,1:mixed} [ true, the safe copy ] or [ false, null ].
  */
  public static function redact_reversible( $value, int $depth = 0 ): array {
    if ( $depth > 8 || is_object( $value ) ) {
      return [ false, null ];
    }
    // A value too large to be read within the budget is refused, as one nested too deep is: the
    // walk below judges every leaf, and the answer for a value it cannot finish is "not kept".
    if ( $depth === 0 && !GMCP_Credential_Scan::within_budget( $value ) ) {
      return [ false, null ];
    }
    if ( is_string( $value ) ) {
      // Free text too: a string leaf with a pasted wp-config line in it names nothing, so the
      // structural test passes it. @see GMCP_Credential_Scan.
      return [ true, ( self::holds_credential( $value ) || GMCP_Credential_Scan::text_holds( $value ) ) ? self::REDACTION_MARKER : $value ];
    }
    if ( !is_array( $value ) ) {
      return [ true, $value ];
    }
    $out = [];
    foreach ( $value as $k => $inner ) {
      if ( is_string( $k ) && self::field_looks_secret( $k ) ) {
        $out[ $k ] = self::REDACTION_MARKER;
        continue;
      }
      [ $ok, $clean ] = self::redact_reversible( $inner, $depth + 1 );
      if ( !$ok ) {
        return [ false, null ];
      }
      $out[ $k ] = $clean;
    }
    return [ true, $out ];
  }

  /**
  * A preview link's token, cut out of a string.
  *
  * A link made by wp_create_preview_link is a bearer credential for an unpublished post, and
  * it travels in a URL, so no field NAME says it is secret: it is a value under "url" or
  * inside a reply. The one place that can recognise it is the parameter it rides in. Used by
  * redact() for every string leaf (which is what the audit log and the debug log both ask)
  * and by the audit log for the reply text it keeps.
  */
  public static function scrub_preview_tokens( string $text ): string {
    $out = GMCP_Diag_Util::scrub_preview_tokens( $text );
    return self::scrubbed_or_withheld( $out, $text );
  }

  /** What stands in for text a scrub could not process. Never an empty string. @see scrubbed_or_withheld() */
  const UNSCRUBBABLE = '[unscrubbable text withheld]';

  /**
  * The result of a preg_replace over $original, or a marker when PCRE gave up.
  *
  * preg_replace() returns null when it hits a backtrack, recursion or JIT limit, and a cast to
  * string makes that "". A scrub that wrote "" for text it could not read recorded the argument
  * as an empty string: a false record, in a log whose whole value is that its records are true.
  * Failing closed means the marker says "something was here and was withheld", never nothing.
  *
  * @param string|null $result what preg_replace() returned
  */
  public static function scrubbed_or_withheld( $result, string $original ): string {
    if ( !is_string( $result ) || ( $result === '' && $original !== '' ) ) {
      return self::UNSCRUBBABLE;
    }
    return $result;
  }

  /**
  * Text that is about to be written down, with a preview link's token and any credential written
  * as an assignment inside it blanked.
  *
  * redact() used to judge a string only by the preview-link rule, so a string value was written
  * whole, and "smtp_pwd=hunter2" inside a search query went into the debug log as the query's
  * first thirty characters. A credential in a URL or a free-text field is named by the key in
  * front of it, the same way a field is, so the key decides here too: a key that
  * field_looks_secret() flags has its value blanked, and a Bearer or Basic credential is blanked
  * whatever it is named. The value ends at the first delimiter (a space, & , ; a quote or a
  * closing bracket), which is what makes the output read as the text it came from.
  *
  * Aggressive on purpose: any value after a secret-named key is blanked, whatever it looks like,
  * so "To reset your password: click the link" becomes "...password: [redacted] the link". That
  * is right for a log nobody should find a secret in, and wrong for a record of what a post said,
  * which is why the audit log's arguments and the journal's undo records use GMCP_Credential_Scan
  * (the shapes and assignments whose VALUE looks like a credential). The short free-text columns,
  * where over-redacting a label is cheaper than leaking one, ask looks_credentialed(), which is
  * this rule's assignment half joined to the scanner. @see log_line(), @see looks_credentialed()
  *
  * Still structural. A bare token with no key in front of it is not recognised, and this says so
  * rather than pretending otherwise.
  *
  * Linear in the length of the text, with the JIT on or off, and never returns "" for text:
  *  - a name run is only started at its beginning (the lookbehind), and the name, the separator and
  *    the value are possessive, so no run is ever re-scanned from a later position. The earlier
  *    form had neither and was quadratic: measured at 31 s for 512 KB of "pass" with the JIT on and
  *    over three minutes for 16 KB with it off, run by redact() over every argument of every call;
  *  - whether the name holds a credential word is asked inside the match, by a lookahead over the
  *    run alone, and only for the words that occur in the text at all;
  *  - when PCRE gives up the result is UNSCRUBBABLE, not "".
  */
  public static function scrub_written_text( string $text ): string {
    $text = self::scrub_preview_tokens( $text );
    if ( $text === '' || $text === self::UNSCRUBBABLE ) {
      return $text;
    }
    $text = self::scrubbed_or_withheld(
      preg_replace( '/\b(Bearer|Basic)\s++[A-Za-z0-9._~+\/=-]{8,}+/i', '$1 ' . self::REDACTION_MARKER, $text ),
      $text
    );
    return $text === self::UNSCRUBBABLE ? $text : self::scrub_assignments( $text );
  }

  /**
  * Whether a short piece of free text should be withheld from a record of what changed: the
  * scanner's answer (a credential by shape, or by the value after a secret-named name), or
  * anything scrub_assignments() or the preview-link rule would change.
  *
  * Wider than the scanner on purpose, and only for the places where over-redacting costs a
  * label: the audit log's target, reply and change summary, and the journal's one-line
  * description of an entry. "password: hunter2" and "smtp_pwd=hunter" passed the scanner (the
  * first has no assignment shape it recognises; the second is six lowercase letters) and were
  * recorded in the clear in the changes column, the journal and wp_list_changes, which is a
  * read-level tool. NOT used to decide whether the journal keeps a value for undo: a value it
  * refuses to keep is a value it cannot restore, and the narrower scanner is what keeps ordinary
  * edits undoable.
  *
  * Fails closed: when PCRE gives up, scrub_assignments() returns UNSCRUBBABLE, which differs.
  */
  public static function looks_credentialed( string $text ): bool {
    if ( $text === '' ) {
      return false;
    }
    if ( GMCP_Credential_Scan::text_holds( $text ) ) {
      return true;
    }
    $previews = self::scrub_preview_tokens( $text );
    return $previews !== $text || self::scrub_assignments( $text ) !== $text;
  }

  /**
  * The assignment half of scrub_written_text(): the value after a secret-named name replaced.
  * @see scrub_written_text() for the rule and for why it is written the way it is.
  */
  public static function scrub_assignments( string $text ): string {
    $names = [];
    foreach ( (array) self::credential_field_patterns() as $pattern ) {
      if ( is_string( $pattern ) && $pattern !== '' && stripos( $text, $pattern ) !== false ) {
        $names[] = preg_quote( $pattern, '/' );
      }
    }
    if ( !$names ) {
      return $text;
    }
    // A name that contains a credential word, followed by a separator (= : => or their
    // URL-encoded forms, with an optional closing quote), then the value up to the next
    // delimiter. The name class admits the brackets and dots of form and query names.
    // The (?!\[redacted\]) keeps this idempotent: a value already replaced by the marker is left
    // as it is, because the marker's own brackets would otherwise end the value early and
    // leave a stray "]" behind on a second pass.
    $re = '/(?<![A-Za-z0-9_.\-\[\]])(?=[A-Za-z0-9_.\-\[\]]*?(?:' . implode( '|', $names ) . '))'
      . '([A-Za-z0-9_.\-\[\]]++)(["\']?+\s*+(?:=>|=|:|%3D|%3A|%3d|%3a)\s*+["\']?+)(?!\[redacted\])[^\s&,;"\'}\])<>]++/i';
    return self::scrubbed_or_withheld( preg_replace( $re, '$1$2' . self::REDACTION_MARKER, $text ), $text );
  }

  /**
  * One request-derived value, as a debug line may carry it: a scalar as text, an array or
  * object as its redacted JSON, with the full log scrub applied either way. @see log_line()
  *
  * Every log line that prints something a client sent goes through this. The value is
  * scrubbed before anything truncates it, so a cut cannot leave a secret's head visible.
  */
  public static function log_text( $value ): string {
    if ( is_array( $value ) || is_object( $value ) ) {
      // A line holds LOG_READ_BYTES of this, so no more than a small multiple of that is read:
      // the redaction walk costs per leaf, and a million of them is an argument a client may send.
      $unused = 0;
      $budget = self::LOG_READ_BYTES * 4;
      $json = wp_json_encode( self::redact( self::cut_leaves( $value, self::LOG_READ_BYTES, $unused, 0, $budget ) ) );
      return self::log_line( is_string( $json ) ? $json : '' );
    }
    return self::log_line( (string) $value );
  }

  /**
  * What the debug line for a tool call prints in place of an identifier argument's own name.
  * Free of every credential word on purpose: the line is scrubbed as text afterwards, and text
  * that says `"key":"x_pwd"` reads as a value assigned to a secret-named field.
  */
  const IDENTIFIER_LOG_LABELS = [ 'key' => 'target', 'meta_key' => 'meta_target', 'option_key' => 'option_target' ];

  /**
  * A tool call's arguments as the debug log prints them: redact_call_args()'s answer for what each
  * argument may say, in a line that still says WHICH row a call named.
  *
  * The top-level "key" of wp_update_option and the meta tools is the name of the row, so it is
  * judged by its VALUE, as the audit log and the approval view judge it: kept unless it is
  * credential-shaped (a vendor key, an assignment, a token) and then hidden by the same scrub as
  * every other string in the line. Passing the result of redact_call_args() to log_text() as an
  * array did not do that: log_text() redacts an array by field name, which blanked "key" (the
  * option's name, "x_pwd") whatever it held, and the scrub after it read `"key":"x_pwd"` as a
  * secret-named assignment. So the identifiers are printed under labels with no credential word
  * in them (IDENTIFIER_LOG_LABELS), and everything else goes through redact() as before: a nested
  * field named "key" and every other secret-named argument are still redacted by name.
  *
  * The result is a string; the call site hands it to log_text() as every client-derived line
  * does, which scrubs it a second time and leaves it as it is.
  *
  * @param array $args The arguments the client sent.
  */
  public static function log_call_args( array $args ): string {
    $safe = self::redact_call_args( $args );
    $named = [];
    foreach ( self::IDENTIFIER_ARGS as $field ) {
      if ( array_key_exists( $field, $safe ) && is_scalar( $safe[ $field ] ) ) {
        $named[ self::IDENTIFIER_LOG_LABELS[ $field ] ?? 'target' ] = $safe[ $field ];
        unset( $safe[ $field ] );
      }
    }
    $unused = 0;
    $budget = self::LOG_READ_BYTES * 4;
    $json = wp_json_encode( $named + self::redact( self::cut_leaves( $safe, self::LOG_READ_BYTES, $unused, 0, $budget ) ) );
    return self::log_line( is_string( $json ) ? $json : '' );
  }

  /** The longest stretch of one value that is read for a debug line; the line itself is shorter. */
  const LOG_READ_BYTES = 2500;

  /**
  * Stands in for a redacted value while a log line is being scrubbed in two passes. Letters only,
  * so the second pass reads it as one ordinary token and ends its own replacement exactly there.
  */
  const LOG_SENTINEL = 'GMCPREDACTEDVALUE';

  /**
  * Text as the debug log may keep it. Over-redacting is acceptable here and under-redacting is
  * not: a log is read by whoever runs the server, and nobody is expected to be told what is
  * in it before they open it. Steps, in this order.
  *
  *  1. Cut to what a line can hold (read no more than that), so a megabyte argument is not
  *     processed to print two thousand characters.
  *  2. Whitespace of every kind (tab, newline, carriage return, vertical tab, form feed, the
  *     Unicode line and paragraph separators and the no-break spaces) becomes one plain space,
  *     before any scrub reads the text, so a separator written with a tab in it is still a
  *     separator. A request value with a newline in it was written as a second line, so any
  *     client could forge "[Guarded MCP] ..." lines in the log an operator reads; one line in,
  *     one line out. Other control characters are escaped to a visible form after the scrubs.
  *  3. A value after a secret-named key is taken whole: an unquoted one up to "&" or the end of
  *     the line, a quoted one up to its closing quote. "smtp_pwd=correct horse battery" was left
  *     as "smtp_pwd=[redacted] horse battery" by a scrub that ended a value at the first space.
  *  4. GMCP_Diag_Util::scrub_log_line(), the scrubber the error-log reader already uses, which
  *     knows the vendor key prefixes, PEM blocks, URL user information, JWTs, escaped JSON,
  *     define() lines and pw=.
  *
  * The two scrubs cannot read each other's marker: given "key=[redacted]" the second ends the
  * value at the "]" and leaves "[redacted]]" behind. So a marker is a letters-only sentinel
  * between steps 3 and 4 and becomes "[redacted]" at the end, which also makes the whole thing
  * stable when a line that was already scrubbed is scrubbed again (GMCP_Logging does that to
  * every line a call site has already passed through log_text()).
  *
  * Never returns "" for text: a scrub that gives up yields UNSCRUBBABLE.
  */
  public static function log_line( string $text ): string {
    // Whitespace first, so the scrubs read it as the separator it was; then every other control
    // character as a visible escape, because the scrubber would otherwise delete it unseen.
    $text = self::escape_control_characters( self::whitespace_to_spaces( self::cut_bytes( $text, self::LOG_READ_BYTES ) ) );
    if ( $text === '' ) {
      return '';
    }
    $text = str_replace( [ self::REDACTION_MARKER, self::LOG_SENTINEL ], self::LOG_SENTINEL, $text );
    $text = self::scrub_log_assignments( $text );
    if ( $text !== self::UNSCRUBBABLE ) {
      $scrubbed = GMCP_Diag_Util::scrub_log_line( $text, [ 'patterns' => array_values( array_filter( array_map( 'strval', (array) self::credential_field_patterns() ), 'strlen' ) ) ] );
      // scrub_log_line() casts each rule's result to a string, so a rule that PCRE gives up on
      // blanks the line and the rules after it run over nothing: what comes back is "" or just
      // its own truncation note. That is a line that lost its content, not a clean one.
      $left = trim( str_replace( ' …[line truncated]', '', $scrubbed ) );
      $text = ( $left === '' && trim( $text ) !== '' && trim( $text ) !== self::LOG_SENTINEL ) ? self::UNSCRUBBABLE : $scrubbed;
    }
    return self::escape_control_characters( str_replace( self::LOG_SENTINEL, self::REDACTION_MARKER, $text ) );
  }

  /**
  * Every kind of whitespace that is not a plain space or that a pattern's \s would not read as one,
  * as a single plain space: tab, vertical tab, form feed, carriage return, newline, the Unicode
  * next-line and line and paragraph separators, the no-break and other fixed-width spaces. Not a
  * regular expression: a table, which cannot give up.
  *
  * This runs BEFORE the scrubs. Escaping a tab first turned "smtp_pass<TAB>=<TAB>x" into the
  * characters backslash and "t", which neither the separator rule nor "Bearer\s++" matches, so a
  * value the client had put a tab in front of reached the log. A line still has one line's worth of
  * characters afterwards (a newline cannot start a forged record), and any other control character
  * is escaped to a visible form at the end of log_line().
  */
  public static function whitespace_to_spaces( string $text ): string {
    static $table = null;
    if ( $table === null ) {
      $table = [ "\t" => ' ', "\x0B" => ' ', "\x0C" => ' ', "\r" => ' ', "\n" => ' ',
        "\xC2\x85" => ' ', "\xC2\xA0" => ' ', "\xE1\x9A\x80" => ' ', "\xE2\x80\xA8" => ' ', "\xE2\x80\xA9" => ' ',
        "\xE2\x80\xAF" => ' ', "\xE2\x81\x9F" => ' ', "\xE3\x80\x80" => ' ' ];
      // U+2000 to U+200A: the en and em spaces, the thin and hair spaces and their neighbours.
      for ( $i = 0x80; $i <= 0x8A; $i++ ) {
        $table[ "\xE2\x80" . chr( $i ) ] = ' ';
      }
    }
    return strtr( $text, $table );
  }

  /**
  * Control characters (and the three Unicode line separators) as visible escapes, so that what
  * is written is one line. Not a regular expression: a table, which cannot give up.
  */
  public static function escape_control_characters( string $text ): string {
    static $table = null;
    if ( $table === null ) {
      $table = [ "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\x7F" => '\\x7f',
        "\xC2\x85" => '\\u0085', "\xE2\x80\xA8" => '\\u2028', "\xE2\x80\xA9" => '\\u2029' ];
      for ( $i = 0; $i < 32; $i++ ) {
        if ( !isset( $table[ chr( $i ) ] ) ) {
          $table[ chr( $i ) ] = sprintf( '\\x%02x', $i );
        }
      }
    }
    return strtr( $text, $table );
  }

  /**
  * The whole value after a secret-named key replaced by the sentinel, for the debug log. See
  * log_line() step 3. Linear for the same reasons scrub_written_text() is: the name run starts
  * at its beginning only, the pieces are possessive, and the credential word is asked of the
  * run by a lookahead and only for words that occur in the text. A value that is already the
  * sentinel is left as it is, and a quoted value keeps its quotes.
  */
  private static function scrub_log_assignments( string $text ): string {
    $names = [];
    foreach ( (array) self::credential_field_patterns() as $pattern ) {
      if ( is_string( $pattern ) && $pattern !== '' && stripos( $text, $pattern ) !== false ) {
        $names[] = preg_quote( $pattern, '/' );
      }
    }
    if ( !$names ) {
      return $text;
    }
    $re = '/(?<![A-Za-z0-9_.\-\[\]])(?=[A-Za-z0-9_.\-\[\]]*?(?:' . implode( '|', $names ) . '))'
      . '([A-Za-z0-9_.\-\[\]]++)((?:\\\\*+["\'])?+\s*+(?:=>|=|:|%3[Dd]|%3[Aa])\s*+)'
      . '(["\']?+' . self::LOG_SENTINEL . '["\']?+|"(?:[^"\\\\\r\n]++|\\\\.)*+"?+|\'(?:[^\'\\\\\r\n]++|\\\\.)*+\'?+|[^&\r\n]++)/i';
    $out = preg_replace_callback( $re, static function ( $m ) {
      if ( strpos( $m[3], self::LOG_SENTINEL ) !== false && strlen( $m[3] ) <= strlen( self::LOG_SENTINEL ) + 2 ) {
        return $m[0];
      }
      $quote = $m[3][0] === '"' || $m[3][0] === "'" ? $m[3][0] : '';
      return $m[1] . $m[2] . $quote . self::LOG_SENTINEL . $quote;
    }, $text );
    return self::scrubbed_or_withheld( $out, $text );
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
  * field_looks_secret() on the field it arrived under. A string leaf gets the preview-link
  * rule and nothing more: the assignment scrub that used to run here blanked ordinary text
  * ("To reset your password: click the link" became "password: [redacted] the link") in a
  * record whose job is to say what was written. Free text is judged as text by
  * redact_credential_spans() for the audit log, and by log_line() for the debug log, which
  * is the channel that takes the aggressive rule.
  *
  * $scrub_preview is for what is WRITTEN DOWN (the audit log, the debug log), where a
  * preview link's token must never land, and defaults to on so a new logging caller is
  * safe without knowing about it. A caller that is answering a tool call with a value the
  * site stores (a theme mod, an ACF field, a Kirki setting) passes false: the reply is the
  * data, and a stored string that happens to contain "gmcp_preview=" is the site's own
  * text, which was being returned as "gmcp_preview=[redacted]".
  */
  public static function redact( $value, int $depth = 0, bool $scrub_preview = true ) {
    if ( $depth > 8 ) {
      return '[too deeply nested to record]';
    }
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( is_string( $value ) ) {
      return $scrub_preview ? self::scrub_preview_tokens( $value ) : $value;
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
      $out[ $key ] = self::redact( $inner, $depth + 1, $scrub_preview );
    }
    return $out;
  }

  /** Top-level arguments that name a thing rather than authenticate to it. @see redact_call_args() */
  const IDENTIFIER_ARGS = [ 'key', 'meta_key', 'option_key' ];

  /** Top-level arguments never written anywhere, whatever their shape. */
  const NEVER_RECORD_ARGS = [ 'user_pass', 'password', 'pass' ];

  /**
  * A tool call's arguments as any log may keep them: the one answer for every channel.
  *
  * redact() alone is wrong for a call's arguments in two directions, and the debug
  * error_log used it alone. It blanks the top-level "key", which for wp_update_option and
  * the meta tools is the NAME of the row, so a log line lost which option changed; and it
  * judges only field names, so "value" was printed whatever it held, and
  * wp_update_option {key: "myplugin_pwd", value: "hunter2"} logged the password. It also
  * does not open strings, so a meta object sent as a JSON string went out whole.
  *
  * This does what the audit log's own wrapper did, in core so that the audit table and the
  * debug log cannot drift apart: never-record fields blanked, identifier fields kept, a
  * value blanked when the name it is stored under looks secret, and any string leaf that
  * holds_credential() flags (a JSON or serialized blob with a secret-named field inside)
  * blanked whole.
  */
  public static function redact_call_args( array $args ): array {
    // The debug line prints a few hundred characters of this; nothing is gained by reading more.
    $unused = 0;
    $budget = 16384;
    $args = self::cut_leaves( $args, 4096, $unused, 0, $budget );
    foreach ( self::NEVER_RECORD_ARGS as $field ) {
      if ( array_key_exists( $field, $args ) ) {
        $args[ $field ] = self::REDACTION_MARKER;
      }
    }
    $redacted = self::redact( $args );
    if ( !is_array( $redacted ) ) {
      return [];
    }
    foreach ( self::IDENTIFIER_ARGS as $field ) {
      if ( isset( $args[ $field ] ) && is_scalar( $args[ $field ] ) ) {
        $redacted[ $field ] = $args[ $field ];
        if ( self::field_looks_secret( (string) $args[ $field ] ) ) {
          foreach ( [ 'value', 'meta_value', 'option_value', 'data' ] as $companion ) {
            if ( array_key_exists( $companion, $redacted ) ) {
              $redacted[ $companion ] = self::REDACTION_MARKER;
            }
          }
        }
      }
    }
    return self::blank_credential_strings( $redacted );
  }

  /**
  * A string cut to at most $max bytes without ending in half a character.
  *
  * Pure PHP: mb_strcut() is not guaranteed to exist and WordPress does not polyfill it.
  */
  public static function cut_bytes( string $text, int $max ): string {
    if ( strlen( $text ) <= $max ) {
      return $text;
    }
    $cut = substr( $text, 0, max( 0, $max ) );
    $i = strlen( $cut ) - 1;
    $back = 0;
    while ( $i >= 0 && $back < 3 && ( ord( $cut[ $i ] ) & 0xC0 ) === 0x80 ) {
      $i--;
      $back++;
    }
    if ( $i >= 0 && ord( $cut[ $i ] ) >= 0xC0 ) {
      $need = ord( $cut[ $i ] ) >= 0xF0 ? 4 : ( ord( $cut[ $i ] ) >= 0xE0 ? 3 : 2 );
      if ( strlen( $cut ) - $i < $need ) {
        $cut = substr( $cut, 0, $i );
      }
    }
    return $cut;
  }

  /**
  * Every string leaf of a value cut to at most $max bytes, and how many bytes that removed.
  *
  * For a caller that can write down no more than $max bytes of any one leaf and wants the scrubs
  * to read no more than it can use: the scrubs are linear, but linear in 512 KB is still work
  * done after the tool has run, for text that cannot be stored. The count lets the caller say how
  * large the original was instead of reporting the size of the copy.
  *
  * Bounding each leaf does not bound how many there are, and the scrubs that run afterwards cost
  * about as much per leaf as per byte: a million one-character strings (4 MB) took 2.5 s to
  * record, a million secret-looking assignments (40 MB) 17 s, and both are well inside the size a
  * request may be. So $budget, when given, is how many bytes of the call may be kept at all. What
  * a node costs is never more than it takes in JSON (a string its length and two quotes, a string
  * key its length and three bytes, a number or a boolean one byte), so the encoded copy is at least
  * as large as what was spent, and a caller whose budget is its storage cap and whose budget ran out
  * would have been over that cap anyway. Once it is spent the rest of that array is dropped without
  * being read, and one entry named "__gmcp_omitted" says how many entries and bytes went unexamined.
  *
  * @param int      $removed running total of bytes not kept, by reference
  * @param int|null $budget  bytes still to be spent, by reference; null for no budget
  */
  public static function cut_leaves( $value, int $max, int &$removed, int $depth = 0, ?int &$budget = null ) {
    if ( is_string( $value ) ) {
      if ( strlen( $value ) > $max ) {
        $cut = self::cut_bytes( $value, $max );
        $removed += strlen( $value ) - strlen( $cut );
        $value = $cut;
      }
      if ( $budget !== null ) {
        $budget -= strlen( $value ) + 2;
      }
      return $value;
    }
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) || $depth > 8 ) {
      if ( $budget !== null ) {
        $budget -= 1;
      }
      return $value;
    }
    $dropped = 0;
    $dropped_bytes = 0;
    foreach ( $value as $k => $inner ) {
      if ( $budget !== null && $budget <= 0 ) {
        $dropped++;
        $dropped_bytes += self::weigh( $inner ) + strlen( (string) $k );
        unset( $value[ $k ] );
        continue;
      }
      if ( $budget !== null && is_string( $k ) ) {
        $budget -= strlen( $k ) + 3;
      }
      $value[ $k ] = self::cut_leaves( $inner, $max, $removed, $depth + 1, $budget );
    }
    if ( $dropped ) {
      $removed += $dropped_bytes;
      $value['__gmcp_omitted'] = $dropped . ' more entries (' . $dropped_bytes . ' bytes) were not examined or recorded';
    }
    return $value;
  }

  /**
  * The bytes a value holds, strings by length and anything else by a few bytes, without reading
  * the strings. For the size a caller reports about what it chose not to look at. Bounded in
  * depth; a deeper structure counts as the depth limit's worth.
  */
  public static function weigh( $value, int $depth = 0 ): int {
    if ( is_string( $value ) ) {
      return strlen( $value );
    }
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) ) {
      return 8;
    }
    if ( $depth > 64 ) {
      return 64;
    }
    $sum = 8;
    foreach ( $value as $k => $inner ) {
      $sum += strlen( (string) $k ) + self::weigh( $inner, $depth + 1 );
    }
    return $sum;
  }

  /**
  * A copy of a value in which each credential inside a string is replaced by the marker and
  * everything around it is kept. What the audit log writes for a call's arguments.
  *
  * blank_credential_strings() replaces a flagged string whole, which is the right answer when the
  * string cannot be taken apart (a JSON or serialized blob with a secret-named field in it, and
  * the journal's undo record, where a partly blanked value could not be restored). For a record
  * of what a call SAID it is the wrong one, in two ways. A short decoy turned the whole of a post
  * body into "[redacted]", so a body that began with a script tag and ended with a stray
  * define('DB_PASSWORD', ...) comment left no trace of the script. And it recorded less than it
  * knew: the scanner has already found where the secret is.
  *
  * So a string that holds_credential() flags structurally (a blob) is still blanked whole, and
  * any other string has only the spans GMCP_Credential_Scan found replaced. A field name that
  * looks secret is already blanked by redact(), before this runs.
  */
  public static function redact_credential_spans( $value, int $depth = 0 ) {
    if ( $depth > 8 ) {
      return $value;
    }
    if ( is_string( $value ) ) {
      return self::holds_credential( $value ) ? self::REDACTION_MARKER : GMCP_Credential_Scan::redact_spans( $value, self::REDACTION_MARKER );
    }
    if ( !is_array( $value ) ) {
      return $value;
    }
    foreach ( $value as $k => $inner ) {
      $value[ $k ] = self::redact_credential_spans( $inner, $depth + 1 );
    }
    return $value;
  }

  /**
  * Every string leaf that holds a credential, replaced by the marker: one that
  * holds_credential() flags (a JSON or serialized blob with a secret-named field inside) and
  * one whose free text carries a pasted secret (GMCP_Credential_Scan), which is what a post
  * body or an excerpt sent as an argument looks like.
  *
  * @param int $window When positive, only that many leading bytes of a string are scanned for
  *                    free text. For a caller that can record no more than that much of it.
  */
  public static function blank_credential_strings( $value, int $depth = 0, int $window = 0 ) {
    if ( $depth > 8 ) {
      return $value;
    }
    if ( is_string( $value ) ) {
      return ( self::holds_credential( $value ) || GMCP_Credential_Scan::holds( $value, 1, $window ) ) ? self::REDACTION_MARKER : $value;
    }
    if ( !is_array( $value ) ) {
      return $value;
    }
    foreach ( $value as $k => $inner ) {
      $value[ $k ] = self::blank_credential_strings( $inner, $depth + 1, $window );
    }
    return $value;
  }

  /**
  * Throw away anything cached against the old build, once, on the first request after the
  * plugin's version changes.
  *
  * The dynamic REST tools are generated from the site's routes and stored in a transient
  * for a day, and nothing ever removed it: not activation, not an upgrade. The only
  * invalidation was a human remembering to bump the suffix in the source, which is a rule
  * that holds right up until the change is somewhere else, such as in how the reply is
  * shaped rather than in how the schema is built.
  *
  * The transient does more than fill the listing. GMCP_Tools_Rest::handle_call() refuses a
  * tool that is not in it, so a tool added by an upgrade was not merely unlisted for
  * twenty-four hours, it was uncallable, and the failure reads as "unknown tool" with
  * nothing to suggest the cause is a cache. A client holding its own stale copy of the
  * manifest is a real and separate problem, and not one a plugin can reach; this half is.
  *
  * Keyed on the version rather than run on activation, because an upgrade in place does
  * not deactivate and reactivate. The option is written before anything else so a fatal
  * further along cannot turn this into a purge on every request.
  */
  private function clear_caches_on_upgrade(): void {
    if ( get_option( self::VERSION_OPTION ) === GMCP_VERSION ) {
      return;
    }
    update_option( self::VERSION_OPTION, GMCP_VERSION, true );

    // Named rather than wildcarded. A LIKE sweep over the options table would also take
    // the one-time plaintext of a newly minted key and the meta-chunk staging, neither of
    // which is a cache and both of which have an owner that expects to find them.
    delete_transient( GMCP_Tools_Rest::CACHE_KEY );
    delete_transient( 'gmcp_setup_report' );

    do_action( 'gmcp_upgraded', GMCP_VERSION );
  }

  public function init() {
    // Before the server, which would otherwise look for a shared token that is on its way
    // to becoming a key. The check is an isset on an option row already in memory, so it
    // costs nothing on the requests where there is nothing to do, which is all of them
    // after the first.
    if ( (string) $this->get_option( 'mcp_bearer_token' ) !== '' ) {
      GMCP_Tokens::adopt_shared_token();
    }
    // Unrelated to the adoption above and landing next to it only because both belong to
    // the first request after an upgrade. This one throws away tools generated by the
    // previous version, which otherwise survive a plugin update and describe a surface
    // that has changed underneath them.
    $this->clear_caches_on_upgrade();

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

    // The queue of calls waiting for a person. Booted unconditionally, like the table check
    // in the audit log: the mode can be switched to 'never' while requests are still open,
    // and the screen has to be able to answer them and the daily event to lapse them.
    GMCP_Approvals::boot();
    // The export group's expiry sweep is registered whether the group is on or off, so an
    // archive minted before somebody switched it off is still removed when it lapses.
    GMCP_Tools_Export::boot();
    GMCP_Core::install_trash_meta_net();
    GMCP_Core::install_personal_data_net();

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
      // Same switch, and the preview-link check it carries runs on the public front end, so
      // turning the group off ends every link it ever issued rather than leaving them live.
      new GMCP_Tools_Diagnostics( $this );
    }

    if ( $this->get_option( 'mcp_tools_rest' ) ) {
      new GMCP_Tools_Rest();
    }

    // Only when the shop is actually here. Registering the tools regardless would put
    // a dozen entries in front of a model that fail the moment it tries one.
    if ( $this->get_option( 'mcp_tools_woo' ) && class_exists( 'WooCommerce' ) ) {
      new GMCP_Tools_Woo();
    }

    // Subscriptions on their own switch, and NOT gated on the shop group: a site can run the
    // subscriptions group without the shop tools, and the two answer different questions.
    // Registered whenever its own switch is on; the per-call check in its handle_call() is
    // what refuses when WooCommerce Subscriptions has been deactivated since.
    if ( $this->get_option( 'mcp_tools_woo_subscriptions' ) ) {
      new GMCP_Tools_Woo_Subscriptions();
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
    // Registered whenever the group is switched on, and NOT gated on Elementor having
    // loaded. It used to be, which silently defeated the one tool in the group written to
    // answer with Elementor switched off: elementor_template_references reads only
    // wp_posts and wp_postmeta, and is the tool a person reaches for at exactly the moment
    // Elementor is deactivated and every referencing page has started printing its
    // shortcode as literal text. It carries a carve-out from the per-call Elementor check
    // for that reason, and a carve-out inside a class that was never constructed cannot
    // run: the tool was simply absent from the list, and the server answered "Unknown
    // tool", which reads as "this feature does not exist" rather than "it is gated".
    //
    // The per-call check in GMCP_Tools_Elementor::handle_call() is what keeps the rest of
    // the group honest, and it is the right level for it, because it can tell one tool
    // from another. Registering here costs one object on a request that may not use it.
    if ( $this->get_option( 'mcp_tools_elementor' ) ) {
      new GMCP_Tools_Elementor();
    }

    // Elementor Pro, on its own switch. Registered whenever that switch is on and NOT gated
    // on Pro having loaded, for the same reason the Elementor group above is not: the status
    // tool exists to answer "why is Pro not working", and a gate inside a class that is
    // never constructed cannot run. The per-call check in its handle_call() keeps the rest
    // of the group honest, and refuses Pro-dependent tools rather than the status tool.
    if ( $this->get_option( 'mcp_tools_elementor_pro' ) ) {
      new GMCP_Tools_Elementor_Pro();
    }

    // Same reasoning as the optional groups above: without Kirki these tools are a
    // handful of entries in front of a model that fail the moment it tries one. The
    // per-call check in GMCP_Tools_Kirki::handle_call() is what keeps them honest
    // when Kirki has been deactivated between the group being switched on and a call.
    if ( $this->get_option( 'mcp_tools_kirki' ) ) {
      new GMCP_Tools_Kirki();
    }

    // Same reasoning as the optional groups above: without Yoast these tools
    // fail the moment they try to read through the Surfaces API. The per-call
    // check in GMCP_Tools_Yoast::handle_call() keeps them honest when Yoast
    // has been deactivated between the group being switched on and a call.
    if ( $this->get_option( 'mcp_tools_yoast' ) ) {
      new GMCP_Tools_Yoast();
    }

    // Same for ACF: without it, update_field/get_field are undefined and the
    // tools fatal. The per-call check in GMCP_Tools_Acf::handle_call() is the
    // runtime honesty check.
    if ( $this->get_option( 'mcp_tools_acf' ) ) {
      new GMCP_Tools_Acf();
    }

    // And Gravity Forms: every tool goes through GFAPI, and its tables are not wp_posts,
    // so there is nothing a generic tool could do instead. The per-call check in
    // GMCP_Tools_Gravityforms::handle_call() is the runtime honesty check.
    if ( $this->get_option( 'mcp_tools_gravityforms' ) ) {
      new GMCP_Tools_Gravityforms();
    }

    // And LearnDash: courses are CPTs but enrolment is user meta and progress is LearnDash's
    // own shape, so the generic tools cannot answer either question honestly. The per-call
    // check in GMCP_Tools_Learndash::handle_call() is the runtime honesty check.
    if ( $this->get_option( 'mcp_tools_learndash' ) ) {
      new GMCP_Tools_Learndash();
    }

    // And WP Activity Log: a third-party security log, read from the outside. Switched on
    // separately because it is a reader for a log the site may not have agreed to keep, and
    // registered unconditionally within its own switch so the briefing can answer "the log
    // is not loaded" — which is a different answer from "the log is empty" and is the one
    // an operator needs when the log has stopped recording.
    if ( $this->get_option( 'mcp_tools_wp_activity_log' ) ) {
      new GMCP_Tools_Wsal();
    }

    // Redirection and Polylang. Constructed whenever the switch is on and not gated on the
    // plugin having loaded, like the groups above: the per-call check in each handle_call()
    // answers "this plugin is not loaded", which is a different statement from "there are
    // none" and the one an operator needs.
    if ( $this->get_option( 'mcp_tools_redirection' ) ) {
      new GMCP_Tools_Redirection();
    }
    if ( $this->get_option( 'mcp_tools_polylang' ) ) {
      new GMCP_Tools_Polylang();
    }
    // Rank Math and All in One SEO. Each tool is offered only while its plugin is loaded
    // (checked at listing and again per call in GMCP_Tools_Seo), so switching the group on
    // for a site with neither registers nothing rather than four tools that can only fail.
    if ( $this->get_option( 'mcp_tools_seo' ) ) {
      new GMCP_Tools_Seo();
    }

    // Form submissions, read only. Same per-plugin registration as the SEO group, and the
    // generic post readers refuse the post types Flamingo and Ninja Forms store submissions
    // under (GMCP_Core::personal_data_post_guard()), whether or not this switch is on.
    if ( $this->get_option( 'mcp_tools_forms' ) ) {
      new GMCP_Tools_Forms();
    }

    // Wordfence: the security plugin's own state, read from the outside. Registered whenever
    // its switch is on and NOT gated on Wordfence having loaded, for the reason the Elementor
    // Pro group is not: wf_status is written to answer "Wordfence is not loaded", which is the
    // answer an operator needs at exactly the moment nothing is watching, and a gate inside a
    // class that is never constructed cannot run.
    if ( $this->get_option( 'mcp_tools_wordfence' ) ) {
      new GMCP_Tools_Wordfence();
    }

    // Stream: a second activity log, read from its own tables. Registered unconditionally
    // within its switch so the briefing can answer "the log is not loaded", which is a
    // different answer from "the log is empty".
    if ( $this->get_option( 'mcp_tools_stream' ) ) {
      new GMCP_Tools_Stream();
    }

    // Beaver Builder: the layout is post meta and the front end renders from it. Registered
    // unconditionally within its switch, like the other optional groups, so the status tool
    // can answer "the builder is not loaded" rather than the tool being absent.
    if ( $this->get_option( 'mcp_tools_beaver' ) ) {
      new GMCP_Tools_Beaver();
    }

    // Beaver Builder writes, on their own switch and off by default. Registered whenever the
    // switch is on; the per-call check refuses when the builder is not loaded.
    if ( $this->get_option( 'mcp_tools_beaver_write' ) ) {
      new GMCP_Tools_Beaver_Write();
    }

    // Site files. Its own switch, off by default, and the pull leg additionally declares
    // that it always needs a person (see GMCP_Tools_Export and GMCP_Approvals).
    if ( $this->get_option( 'mcp_tools_export' ) ) {
      new GMCP_Tools_Export();
    }
  }

  /**
  * Two-step confirmation for irreversible operations, using a token the server mints.
  *
  * The obvious designs do not work. A `confirm: true` flag is free to send and carries
  * no evidence of intent. Echoing back the target's own name is better, since it proves
  * the caller knew which target it named, but it is still information the caller already
  * has in the same call, so it is satisfied on the first attempt. Both stop a typo.
  * Neither stops the thing that actually matters here: this agent reads comments, post
  * bodies and form entries that anonymous people wrote, so "delete plugin X" can arrive as
  * an instruction smuggled into content, and a single injected tool call should not be
  * able to complete an irreversible operation.
  *
  * A server-minted token fixes exactly that. The first call changes nothing and returns a
  * random token; only a second call carrying it proceeds. An injected instruction cannot
  * predict the token, so it cannot complete in one shot, and the refusal message with its
  * preview passes through the transcript where a person can see what was about to happen.
  * It raises the bar to two calls; it does not defeat an agent that is fully under an
  * attacker's control, and no description here should claim that it does.
  *
  * The token is keyed to the tool AND the normalized target, so a token minted to delete
  * one plugin cannot be replayed to delete another. It is single use and expires in two
  * minutes.
  *
  * This lives here rather than in the admin tool class because the optional groups each
  * delete something irreversible, and a copy in each file would drift in exactly the way
  * this repo keeps paying for: the TTL, the single-use delete, or the target binding
  * updated in one place and not the others.
  *
  * @param string $tool    The tool name, part of the token key.
  * @param mixed  $target  The resolved thing being acted on, part of the token key.
  * @param array  $args    The call's arguments; `confirm` is read from here.
  * @param string $summary What is about to happen, for the human reading the transcript.
  *
  * @return true|string True to proceed, otherwise the message to return.
  */
  public static function confirm_gate( string $tool, $target, array $args, string $summary ) {
    // Keyed on the RESOLVED target, not on the raw arguments. A caller may name a
    // plugin as "akismet" or "akismet/akismet.php"; both resolve to the same file, so
    // both must share one token, and the token has to be bound to what will actually
    // be deleted rather than to how it happened to be spelled.
    $key = 'gmcp_confirm_' . hash( 'sha256', $tool . '|' . (string) $target );

    $given = isset( $args['confirm'] ) ? (string) $args['confirm'] : '';
    $expected = get_transient( $key );

    if ( $given !== '' && is_string( $expected ) && hash_equals( $expected, $given ) ) {
      delete_transient( $key ); // Single use: a replay has to be confirmed again.
      return true;
    }

    $token = bin2hex( random_bytes( 8 ) );
    set_transient( $key, $token, 2 * MINUTE_IN_SECONDS );
    // Said out loud for the approval queue: this call changed nothing and handed back a
    // token, so an approval it ran under has not been spent on an effect. @see
    // GMCP_Approvals::finish().
    self::$gate_withheld = true;

    // The backup situation belongs in the summary rather than in a gate. Whoever reads
    // this is about to approve something irreversible, and "the last backup finished
    // eight days ago" is the fact that most changes their answer. Gating on it instead
    // would have to fail open on any provider this plugin cannot read, and a control that
    // silently passes is worse than an absent one, because it gets counted.
    $backup = class_exists( 'GMCP_Backup' ) ? GMCP_Backup::confirmation_line() : '';

    return $summary . $backup . " Nothing has been changed. To go ahead, call this tool again within two minutes with the same arguments plus confirm set to \"{$token}\".";
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
    if ( !empty( $users ) ) {
      return $users[0];
    }
    // A site with the role renamed or removed, or a network sub-site run only by super
    // admins, has no "administrator" here, and the caller then ran as user 0 or refused an
    // upload with "No administrator account". Asked by capability instead, which is what
    // the role was standing in for. The capability argument is WordPress 5.9+.
    $users = get_users( [
      'capability' => 'manage_options',
      'number' => 1,
      'orderby' => 'ID',
      'order' => 'ASC',
    ] );
    if ( !empty( $users ) ) {
      return $users[0];
    }
    if ( is_multisite() ) {
      foreach ( (array) get_super_admins() as $login ) {
        $user = get_user_by( 'login', $login );
        if ( $user ) {
          return $user;
        }
      }
    }
    return null;
  }

  public function markdown_to_html( $content ) {
    $parsedown = new Parsedown();
    return $parsedown->text( $content );
  }

  #endregion
}

/**
* Thrown by the drafts-only net under the tools, before anything is written.
*
* Its own class so a caller that must tell a policy refusal from a bug can, and so the
* journal's undo can answer with the sentence instead of a stack of "failed".
*/
class GMCP_Drafts_Only_Refusal extends RuntimeException {
}

/**
* Thrown by a net under the tools when a write is refused by a policy that is not the
* drafts-only one (the deletion-by-another-name meta keys).
*/
class GMCP_Policy_Refusal extends RuntimeException {
}
