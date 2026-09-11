<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* What changed, and how to put it back.
*
* The activity log records that a tool ran. That answers "what did my agent do", which
* is the question you ask calmly. This answers the question you ask in a hurry: "put it
* back". Without it, undoing an agent's afternoon means knowing what the settings used
* to say, and nobody knows what the settings used to say.
*
* It no longer listens to WordPress itself. GMCP_Changes does that, and works out the
* before and after once for both the subsystems that want it: this one, which needs the
* previous value so it can put it back, and the audit log, which needs a description of
* the difference. Two copies of that diff would drift, and the day somebody added a
* field to one list the other would silently stop mentioning it.
*
* What arrives is still every write a tool made, whichever tool made it, because the
* capture layer hooks WordPress rather than the tools: widgets live in options and menu
* items are posts, so both are journalled for free. Only writes made during a tool call
* are recorded. A human saving a settings page is not the agent's doing and is not the
* agent's to undo.
*
* Of the kinds the capture layer reports, this takes options and posts and ignores the
* rest. Users, terms, comments, plugins and themes are recorded in the audit log because
* a reader wants to know about them; they are absent here because nothing in this file
* could put them back, and an undo list full of entries that cannot be undone is worse
* than one that is honest about its reach.
*
* Two things it deliberately will not record:
*
* Anything GMCP_Core::option_guard() refuses, by name or by the names inside its value.
* The journal writes previous values into an option row, so recording the plugin's own
* settings would copy the bearer token into a second place. The value check exists
* because the name check is not enough: woocommerce_stripe_settings is an innocuous name
* holding a live secret_key, and matching only on the name would journal it in full.
*
* This is a heuristic and it is not retroactive. A site that adds an option to
* gmcp_protected_options later gets a correct refusal at revert time, but the plaintext
* already recorded stays in the row until the log rolls past it. Clear the journal after
* protecting something that was previously being recorded.
*
* Anything large. Previous values above MAX_VALUE are described rather than copied, and
* the log is trimmed by total size as well as by count, so it cannot grow without bound.
*
* It does not lean on post revisions, which is the obvious-looking shortcut and is wrong.
* By the time post_updated fires, the newest revision holds the NEW body: WordPress saves
* a revision of the post as it now stands, and the revision carrying the previous text
* only exists if that post had been saved before. Restoring "the latest revision" after an
* edit therefore restores the edit. The previous body is kept here instead.
*/
class GMCP_Journal {

  const OPTION = 'gmcp_journal';
  const LIMIT = 40;
  /** Serialized previous values above this are pointed at, not copied. */
  const MAX_VALUE = 64000;
  /** And the whole row stays under this, however few entries that turns out to be. */
  const MAX_TOTAL = 512000;

  /**
  * Post meta gets a table of its own rather than a place in the option row above.
  *
  * The limits above exist because the journal is a single option, and they are the right
  * limits for one. They are the wrong limits for meta: _elementor_data runs past 100KB
  * routinely, which is larger than MAX_VALUE, so every Elementor edit would have been
  * recorded as "too large to keep" and the undo that motivated journalling meta at all
  * would never have worked once. Raising MAX_VALUE instead would let two or three page
  * edits evict every option and post entry from a shared budget.
  *
  * So the entry stays in the option, small, and points at a row here. The two are kept
  * consistent by reversible(), which treats a missing snapshot as a pruned one and says
  * so, rather than by assuming a pointer always resolves.
  */
  const SNAP_DB_VERSION = '1';
  const SNAP_VERSION_OPTION = 'gmcp_journal_db_version';
  /** A single value larger than this is recorded as changed and not copied. */
  const SNAP_MAX_VALUE = 1048576;
  /** The whole snapshot table stays under this. */
  const SNAP_MAX_BYTES = 16777216;
  const SNAP_RETENTION_DAYS = 14;

  public function __construct() {
    add_action( 'gmcp_change', [ $this, 'observe' ], 10, 1 );
    if ( get_option( self::SNAP_VERSION_OPTION ) !== self::SNAP_DB_VERSION ) {
      self::install();
    }
    // Hung on the audit log's daily event rather than scheduling a second one. That event
    // is scheduled on activation whether or not the audit log is switched on, so this
    // prunes even on a site that keeps no activity log, and a site with both gets one
    // wake-up instead of two.
    add_action( GMCP_Audit::CRON_HOOK, [ __CLASS__, 'prune_snapshots' ] );
  }

  public static function snapshot_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'gmcp_meta_snapshots';
  }

  /**
  * Create or update the snapshot table.
  *
  * dbDelta is fussy in the ways GMCP_Audit::install() records: two spaces after PRIMARY
  * KEY, KEY rather than INDEX, lowercase types.
  *
  * meta_key is indexed at a prefix length. WordPress allows 255 characters there and
  * utf8mb4 makes that 1020 bytes, past InnoDB's 767-byte limit on older row formats, so
  * an unbounded key silently fails to create on exactly the installs least able to
  * diagnose it.
  */
  public static function install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::snapshot_table();
    $collate = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      entry_id varchar(32) NOT NULL DEFAULT '',
      ts datetime NOT NULL,
      post_id bigint(20) unsigned NOT NULL DEFAULT 0,
      meta_key varchar(255) NOT NULL DEFAULT '',
      meta_value longtext NULL,
      was_absent tinyint(1) NOT NULL DEFAULT 0,
      bytes int(10) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY  (id),
      KEY entry_id (entry_id),
      KEY ts (ts),
      KEY post_meta (post_id,meta_key(191))
    ) {$collate};" );
    update_option( self::SNAP_VERSION_OPTION, self::SNAP_DB_VERSION, false );
  }

  /**
  * Keep the snapshot table inside its bounds: age first, then total bytes.
  *
  * Age first because it is the cheap bound and usually the only one that does anything.
  * Bytes last because it is the one that removes something recent, and dropping by age
  * may already have solved it.
  *
  * A pruned snapshot does not remove its journal entry. The entry is the record that the
  * change happened, which stays true; only the ability to put it back expires, and
  * reversible() says so in those words rather than reporting a change that never was.
  */
  public static function prune_snapshots(): array {
    global $wpdb;
    $table = self::snapshot_table();
    $removed = [ 'age' => 0, 'bytes' => 0 ];

    $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::SNAP_RETENTION_DAYS * DAY_IN_SECONDS ) );
    $removed['age'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", $cutoff ) );

    $guard = 0;
    while ( (int) $wpdb->get_var( "SELECT COALESCE(SUM(bytes),0) FROM {$table}" ) > self::SNAP_MAX_BYTES && $guard < 200 ) {
      $dropped = (int) $wpdb->query( "DELETE FROM {$table} ORDER BY id ASC LIMIT 20" );
      if ( $dropped === 0 ) {
        break;
      }
      $removed['bytes'] += $dropped;
      $guard++;
    }
    return $removed;
  }

  /**
  * One observed change, if it is a kind this can put back.
  *
  * The in-flight test lives in GMCP_Changes now: nothing reaches this hook unless a tool
  * call made it.
  */
  public function observe( $change ): void {
    if ( !is_array( $change ) ) {
      return;
    }
    if ( ( $change['kind'] ?? '' ) === 'meta' ) {
      $this->observe_meta( $change );
    }
    if ( ( $change['kind'] ?? '' ) === 'option' ) {
      $this->observe_option( $change );
    }
    elseif ( ( $change['kind'] ?? '' ) === 'post' ) {
      $this->observe_post( $change );
    }
  }

  /**
  * Options whose previous value is either meaningless, enormous, or dangerous to put
  * back. Restoring active_plugins directly would activate plugins without running their
  * activation hooks, which is how you get a half-installed plugin; the plugin tools exist
  * for that. rewrite_rules is a derived cache measured in tens of kilobytes. Both of those
  * are on the shared list now, since they are no more readable as changes than they are
  * revertible.
  */
  private function skip_option( string $key ): bool {
    // The rows that are noise rather than change are listed once, where the listening
    // happens. What is added here is what undo in particular cannot sensibly put back:
    // restoring a protected row would copy a credential into a second place, and the
    // capture layer keeps those but records no value for them.
    if ( GMCP_Changes::is_noise( $key ) ) {
      return true;
    }
    return GMCP_Core::option_guard( $key ) !== true;
  }

  /**
  * Created and updated options are recorded; a deleted one is not.
  *
  * Putting back a deletion means deciding whether the row was there before, which the
  * created case already answers for the opposite direction, and no tool deletes an
  * option today. Recording one would be an undo path nothing had ever exercised.
  */
  private function observe_option( array $change ): void {
    $key = (string) ( $change['id'] ?? '' );
    if ( $key === '' || $this->skip_option( $key ) ) {
      return;
    }
    if ( ( $change['op'] ?? '' ) === 'created' ) {
      // "Previously absent" has to be a distinct state from "previously empty", or undo
      // leaves a row behind that WordPress never had.
      $this->record( [
        'kind' => 'option',
        'key' => $key,
        'what' => "Option \"{$key}\" created",
        'previous' => null,
        'absent' => true,
        'tool' => (string) ( $change['tool'] ?? '' ),
      ] );
      return;
    }
    if ( ( $change['op'] ?? '' ) !== 'updated' ) {
      return;
    }
    $old = $change['fields']['value']['from'] ?? null;
    // The guard matches on the option's NAME, which is not where most secrets live.
    // woocommerce_stripe_settings, wp_mail_smtp and jetpack_options are all innocuous
    // names holding an array with a secret_key or an api_key inside it. Copying that
    // array here would put a live credential in a second row, and rotating the
    // credential through the agent would leave the old one on disk.
    $entry = [
      'kind' => 'option',
      'key' => $key,
      'what' => "Option \"{$key}\" changed",
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];
    if ( GMCP_Core::holds_credential( $old ) ) {
      // Keep the shape with the secret-looking leaves blanked, rather than dropping the
      // value whole. Dropping was costing every option that merely contains a field named
      // key, author or password-something its undo, which on an Elementor build is most of
      // them, and buying nothing the blanking does not also buy.
      //
      // redact_reversible() refuses some values outright, an object or anything nested
      // past its limit, and those keep the old behaviour. A refusal here is the safe
      // answer and stays one.
      [ $can_snapshot, $clean ] = GMCP_Core::redact_reversible( $old );
      if ( $can_snapshot ) {
        $entry['previous'] = $this->storable( $clean );
        $entry['partly_redacted'] = true;
      }
      else {
        $entry['previous'] = null;
        $entry['redacted'] = true;
      }
    }
    else {
      $entry['previous'] = $this->storable( $old );
    }
    $this->record( $entry );
  }

  /**
  * One post meta write.
  *
  * Unlike a post edit, all three operations are recorded here. Creating a meta row and
  * deleting one are both reversible in a way that creating or deleting a post is not:
  * the opposite of adding a key is removing it, and the opposite of removing one is
  * writing the value back. "Previously absent" is kept as its own state so undo removes
  * the row rather than leaving an empty one the post never had.
  *
  * The value goes in the snapshot table and the entry keeps only a pointer, for the
  * reason SNAP_MAX_VALUE gives. Nothing credential-shaped goes in either: the same test
  * the option side uses is asked here, so the two subsystems cannot disagree about what
  * counts as a secret.
  */
  private function observe_meta( array $change ): void {
    $post_id = (int) ( $change['id'] ?? 0 );
    $meta_key = (string) ( $change['meta_key'] ?? '' );
    $op = (string) ( $change['op'] ?? '' );
    if ( $post_id <= 0 || $meta_key === '' ) {
      return;
    }

    $verb = [ 'created' => 'added', 'updated' => 'changed', 'deleted' => 'removed' ][ $op ] ?? null;
    if ( $verb === null ) {
      return;
    }

    $entry = [
      'kind' => 'meta',
      'ID' => $post_id,
      'key' => $meta_key,
      'what' => ucfirst( (string) ( $change['subject'] ?? 'post' ) ) . ' "'
        . mb_substr( (string) ( $change['label'] ?? '' ), 0, 60 ) . '" custom field "'
        . $meta_key . '" ' . $verb,
      'absent' => empty( $change['existed'] ),
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];

    // A key whose NAME looks like a credential, or a value that holds one, is recorded as
    // changed and its previous value is not kept. Same answer as the option side, asked of
    // the same functions, so the journal cannot protect a secret in an option and copy the
    // same secret out of a meta row.
    $previous = $change['previous'] ?? null;
    if ( GMCP_Core::field_looks_secret( $meta_key ) || GMCP_Core::holds_credential( $previous ) ) {
      $entry['redacted'] = true;
      $this->record( $entry );
      return;
    }

    // Nothing to keep for a key that was not there. The absent flag is the whole record.
    if ( !empty( $entry['absent'] ) ) {
      $entry['snapshot'] = 0;
      $this->record( $entry );
      return;
    }

    $serialized = maybe_serialize( $previous );
    $bytes = strlen( (string) $serialized );
    if ( $bytes > self::SNAP_MAX_VALUE ) {
      $entry['too_large'] = $bytes;
      $this->record( $entry );
      return;
    }

    // The id is minted before the row is written so the snapshot can carry it, which is
    // what lets a pruned snapshot be told apart from an entry that never had one.
    $entry['id'] = bin2hex( random_bytes( 5 ) );
    global $wpdb;
    $wpdb->insert( self::snapshot_table(), [
      'entry_id' => $entry['id'],
      'ts' => gmdate( 'Y-m-d H:i:s' ),
      'post_id' => $post_id,
      'meta_key' => $meta_key,
      'meta_value' => $serialized,
      'was_absent' => 0,
      'bytes' => $bytes,
    ] );
    $entry['snapshot'] = (int) $wpdb->insert_id;
    $this->record( $entry );
  }

  /** The stored previous value for an entry, or null when there is no usable snapshot. */
  private static function snapshot_for( string $entry_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT meta_value FROM " . self::snapshot_table() . " WHERE entry_id = %s ORDER BY id DESC LIMIT 1",
      $entry_id
    ) );
    return $row ? maybe_unserialize( $row->meta_value ) : null;
  }

  private static function snapshot_exists( string $entry_id ): bool {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM " . self::snapshot_table() . " WHERE entry_id = %s",
      $entry_id
    ) ) > 0;
  }

  /**
  * An edited post. Creations and deletions go past: this restores fields, and neither of
  * those is a field to restore.
  */
  private function observe_post( array $change ): void {
    if ( ( $change['op'] ?? '' ) !== 'updated' ) {
      return;
    }
    $changed = array_keys( (array) ( $change['fields'] ?? [] ) );
    if ( !$changed ) {
      return;
    }

    $entry = [
      'kind' => 'post',
      'ID' => (int) ( $change['id'] ?? 0 ),
      'what' => ucfirst( (string) ( $change['subject'] ?? 'post' ) ) . ' "'
        . mb_substr( (string) ( $change['label'] ?? '' ), 0, 60 ) . '" changed: ' . implode( ', ', $changed ),
      'fields' => $changed,
      'previous' => [],
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];
    foreach ( $change['fields'] as $field => $pair ) {
      $entry['previous'][ $field ] = $this->storable( $pair['from'] ?? null );
    }
    if ( $this->oversized( $entry['previous'] ) ) {
      $entry['note'] = 'Part of the previous version was too large to keep, so reverting will restore only what fits.';
    }
    $this->record( $entry );
  }

  /**
  * A snapshot made writable again, by putting the live value back under every leaf that
  * was blanked when the snapshot was taken.
  *
  * The one thing an undo must never do is write the redaction marker into a live option.
  * That is not a partial restore, it is destroying the credential the blanking existed to
  * protect, which is worse than having no undo at all.
  *
  * So a blanked leaf takes whatever is there now, and when nothing is there now the key is
  * dropped rather than written empty. Everything else comes from the snapshot, including
  * keys the current value has since gained: the snapshot is a faithful copy of what the
  * option was, and restoring it means those go, exactly as they would for an option that
  * was never redacted at all.
  *
  * @param mixed $snapshot The recorded value, with markers where leaves were blanked.
  * @param mixed $current  What the option holds now.
  * @param int   $kept     Out: how many leaves were left at their current value.
  */
  private static function refill( $snapshot, $current, int &$kept ) {
    if ( $snapshot === GMCP_Core::REDACTION_MARKER ) {
      $kept++;
      return $current;
    }
    if ( !is_array( $snapshot ) ) {
      return $snapshot;
    }
    $out = [];
    foreach ( $snapshot as $k => $inner ) {
      $has_current = is_array( $current ) && array_key_exists( $k, $current );
      if ( $inner === GMCP_Core::REDACTION_MARKER && !$has_current ) {
        // Nothing to put back and nothing safe to write. Leaving the key out restores the
        // option as closely as it can be restored, and writing the marker would not.
        $kept++;
        continue;
      }
      $out[ $k ] = self::refill( $inner, $has_current ? $current[ $k ] : null, $kept );
    }
    return $out;
  }

  /** Big previous values are described rather than copied, so the option stays small. */
  private function storable( $value ) {
    $size = strlen( maybe_serialize( $value ) );
    if ( $size > self::MAX_VALUE ) {
      return [ '__gmcp_too_large' => $size ];
    }
    return $value;
  }

  /** True when storable() replaced the value with a description of its size. */
  private static function too_large( $value ): bool {
    return is_array( $value ) && isset( $value['__gmcp_too_large'] );
  }

  private function oversized( array $values ): bool {
    foreach ( $values as $value ) {
      if ( self::too_large( $value ) ) {
        return true;
      }
    }
    return false;
  }

  private function record( array $entry ): void {
    $entry['t'] = time();
    $entry['tool'] = (string) ( $entry['tool'] ?? '' );
    $entry['call'] = (string) ( $entry['call'] ?? GMCP_Changes::call_id() );
    // Minted here unless the caller already has one. A meta entry writes its snapshot row
    // before the entry exists and has to carry the id it used, or the row and the entry
    // reference different ids and every meta undo reports its snapshot pruned.
    if ( empty( $entry['id'] ) ) {
      $entry['id'] = bin2hex( random_bytes( 5 ) );
    }

    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];
    $log[] = $entry;
    if ( count( $log ) > self::LIMIT ) {
      $log = array_slice( $log, -self::LIMIT );
    }
    // A count on its own is a poor bound once post bodies are in here: forty entries
    // could be forty long articles. Drop the oldest until the row is a sane size.
    //
    // The size is measured per entry rather than by re-serializing the whole log on every
    // iteration, which made trimming a full row quadratic in the number of entries.
    $sizes = array_map( function ( $one ) { return strlen( maybe_serialize( $one ) ); }, $log );
    $total = array_sum( $sizes );
    while ( count( $log ) > 1 && $total > self::MAX_TOTAL ) {
      $total -= array_shift( $sizes );
      array_shift( $log );
    }
    update_option( self::OPTION, $log, false );
  }

  /** Most recent first, without the stored values, which can be large and are not useful to read. */
  public static function recent( int $limit = 20 ): array {
    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];
    $out = [];
    foreach ( array_slice( array_reverse( $log ), 0, $limit ) as $entry ) {
      $row = [
        'id' => $entry['id'] ?? '',
        'when' => gmdate( 'Y-m-d H:i', (int) ( $entry['t'] ?? 0 ) ) . ' GMT',
        'tool' => $entry['tool'] ?? '',
        // Entries sharing a call came from one tool call. A page build writes several
        // things and they read as unrelated events without this.
        'call' => $entry['call'] ?? '',
        'what' => $entry['what'] ?? '',
        'reversible' => self::reversible( $entry ) === true,
      ];
      $why = self::reversible( $entry );
      if ( $why !== true ) {
        $row['not_reversible_because'] = $why;
      }
      // Said out loud rather than left to be discovered. "Reversible" on its own would
      // promise a restore this entry cannot give, and a caller deciding whether to undo
      // needs to know the credential-shaped fields will stay as they are.
      elseif ( !empty( $entry['partly_redacted'] ) ) {
        $row['partial_restore'] = 'Fields that looked like credentials were never recorded. '
          . 'Everything else goes back; those are left exactly as they are now.';
      }
      $out[] = $row;
    }
    return $out;
  }

  /** @return true|string True if it can be put back, otherwise why not. */
  private static function reversible( array $entry ) {
    if ( !empty( $entry['undone'] ) ) {
      return 'It has already been reverted.';
    }
    foreach ( self::gates( $entry ) as $tool ) {
      if ( !apply_filters( 'gmcp_can_call_tool', false, $tool ) ) {
        return "It needs {$tool}, which this connection cannot call.";
      }
    }
    if ( ( $entry['kind'] ?? '' ) === 'option' ) {
      if ( !empty( $entry['redacted'] ) ) {
        return 'The previous value looked like it held a credential, so it was never stored.';
      }
      if ( self::too_large( $entry['previous'] ?? null ) ) {
        return 'The previous value was too large to keep.';
      }
      // Re-checked at revert time as well: the guard list is filterable and a site may
      // have protected the key since.
      return GMCP_Core::option_guard( (string) $entry['key'] ) === true
        ? true
        : 'That option is protected.';
    }
    if ( ( $entry['kind'] ?? '' ) === 'meta' ) {
      if ( !get_post( (int) $entry['ID'] ) ) {
        return 'The post no longer exists.';
      }
      if ( !empty( $entry['redacted'] ) ) {
        return 'The previous value looked like it held a credential, so it was never stored.';
      }
      if ( !empty( $entry['too_large'] ) ) {
        return 'The previous value was ' . (int) $entry['too_large'] . ' bytes, past the limit for keeping a copy.';
      }
      // Absent needs no snapshot: undo removes the row. Anything else does, and a snapshot
      // that has been pruned is a real state rather than an error, so it is named as one.
      if ( !empty( $entry['absent'] ) ) {
        return true;
      }
      return self::snapshot_exists( (string) ( $entry['id'] ?? '' ) )
        ? true
        : 'The stored copy of the previous value has passed its retention window and been removed.';
    }
    if ( ( $entry['kind'] ?? '' ) === 'post' ) {
      if ( !get_post( (int) $entry['ID'] ) ) {
        return 'The post no longer exists.';
      }
      foreach ( (array) ( $entry['previous'] ?? [] ) as $value ) {
        if ( !self::too_large( $value ) ) {
          return true;
        }
      }
      return 'Every previous value was too large to keep.';
    }
    return 'Unknown change type.';
  }

  /**
  * The tools a caller must be able to call before this entry may be reverted.
  *
  * Two of them, and both are needed.
  *
  * The recorded tool is what was in flight, which is not always what made the write. The
  * journal listens at the WordPress level, so an option written by some other plugin
  * hooked on save_post during a wp_update_post call is recorded against wp_update_post.
  * Gating on that name alone let a write-level caller revert an admin-level option: a key
  * refused wp_update_option outright set default_role to editor, which with open
  * registration is a way in.
  *
  * So the entry's own kind decides the second gate, named for the operation the revert
  * will actually perform. The recorded tool is kept as well, because a caller who could
  * not have caused this change has no business reversing it either.
  *
  * @return string[]
  */
  private static function gates( array $entry ): array {
    $byKind = [
      'option' => 'wp_update_option',
      'post' => 'wp_update_post',
      // Named for the operation the revert performs, not for the tool that happened to be
      // in flight. Putting a meta row back is a meta write, and a caller who cannot make
      // one has no business making it backwards.
      'meta' => 'wp_update_post_meta',
    ];
    $tools = [];
    $kind = (string) ( $entry['kind'] ?? '' );
    // An unknown kind yields a tool name nothing registers, so the check refuses. A new
    // kind added without a gate must fail closed rather than sail past an empty list.
    $tools[] = $byKind[ $kind ] ?? 'gmcp_unknown_change_kind';
    $recorded = (string) ( $entry['tool'] ?? '' );
    if ( $recorded !== '' && $recorded !== $tools[0] ) {
      $tools[] = $recorded;
    }
    return $tools;
  }

  /**
  * Put one change back.
  *
  * Deliberately narrow: it restores exactly the fields the entry recorded and touches
  * nothing else, so reverting a title change on a post that has since been rewritten
  * restores the title and leaves the rewrite alone.
  *
  * @return array{ok:bool,message:string}
  */
  /**
  * Put back everything one tool call changed, newest first.
  *
  * One call routinely changes several things, and reverting them one at a time means
  * spotting its pieces among their neighbours and getting the order right. Newest first
  * matters: two entries can touch the same row, and replaying them oldest first leaves the
  * value the call set rather than the value it found.
  *
  * Partial success is reported rather than hidden. An entry can be individually
  * irreversible, a credential-shaped value or an expired snapshot among the reasons, and
  * saying "reverted" over a call that was only partly put back is the kind of claim this
  * plugin exists not to make.
  *
  * @return array{ok:bool,message:string}
  */
  public static function revert_call( string $call ): array {
    if ( $call === '' ) {
      return [ 'ok' => false, 'message' => 'A call id is required.' ];
    }
    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];

    $ids = [];
    foreach ( $log as $entry ) {
      if ( (string) ( $entry['call'] ?? '' ) === $call && empty( $entry['undone'] ) ) {
        $ids[] = (string) $entry['id'];
      }
    }
    if ( !$ids ) {
      return [
        'ok' => false,
        'message' => "No changes are on record for call \"{$call}\" that have not already been reverted. Call wp_list_changes to see what is there.",
      ];
    }

    $done = [];
    $failed = [];
    foreach ( array_reverse( $ids ) as $one ) {
      $result = self::revert( $one );
      if ( !empty( $result['ok'] ) ) {
        $done[] = $result['message'];
      }
      else {
        $failed[] = $one . ': ' . $result['message'];
      }
    }

    $lines = [ count( $done ) . ' of ' . count( $ids ) . ' change(s) from that call were put back.' ];
    foreach ( $done as $line ) {
      $lines[] = '- ' . $line;
    }
    if ( $failed ) {
      $lines[] = '';
      $lines[] = 'Not put back:';
      foreach ( $failed as $line ) {
        $lines[] = '- ' . $line;
      }
    }
    return [ 'ok' => !empty( $done ), 'message' => implode( "\n", $lines ) ];
  }

  public static function revert( string $id ): array {
    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];

    foreach ( $log as $index => $entry ) {
      if ( ( $entry['id'] ?? '' ) !== $id ) {
        continue;
      }
      $why = self::reversible( $entry );
      if ( $why !== true ) {
        return [ 'ok' => false, 'message' => 'That change cannot be reverted. ' . $why ];
      }

      // Undo is a write, and it is the same write the original tool made, backwards.
      // Without this it is an unscoped write primitive: wp_undo_change sits at the
      // write level while wp_update_option sits at admin, so a key refused
      // wp_update_option outright could still reopen public registration by reverting
      // the change that closed it. Every admin-level tightening made through the agent
      // would become a handle usable at write level, and a key scoped to a short list
      // of tools would still reach every write on the journal, because "undo" is one
      // name standing for all of them.
      //
      // So ask whether this caller could call the tool that made the change. Defaults
      // to false: no server means no answer, and a security check with no answer must
      // refuse.
      foreach ( self::gates( $entry ) as $tool ) {
        if ( !apply_filters( 'gmcp_can_call_tool', false, $tool ) ) {
          return [
            'ok' => false,
            'message' => "Reverting this is the same write in reverse, and it needs {$tool}, which this connection cannot call.",
          ];
        }
      }

      // A revert writes options and posts like anything else, and recording it would
      // put the change back on the list as though the agent had made it.
      $was = GMCP_Changes::pause();

      $done = '';
      if ( $entry['kind'] === 'meta' ) {
        $meta_post = (int) $entry['ID'];
        $meta_key = (string) $entry['key'];
        if ( !empty( $entry['absent'] ) ) {
          // It was not there before, so putting it back means removing it. Every row for
          // the key goes, which is what "absent" described.
          delete_post_meta( $meta_post, $meta_key );
          $done = 'Custom field "' . $meta_key . '" removed from post ' . $meta_post . ', which is what it was before.';
        }
        else {
          // wp_slash for the reason prepare_meta_value() gives: update_post_meta()
          // unslashes what it is handed, so restoring a JSON or regex value without this
          // puts back a corrupted copy of what was recorded correctly.
          $meta_value = self::snapshot_for( (string) $entry['id'] );
          update_post_meta( $meta_post, $meta_key, wp_slash( $meta_value ) );
          $done = 'Custom field "' . $meta_key . '" on post ' . $meta_post . ' restored to its previous value.';
        }
        clean_post_cache( $meta_post );
      }
      elseif ( $entry['kind'] === 'option' ) {
        // An undo is still a write, and the same policy applies to it. Being able to
        // call wp_update_option is not the same as being allowed to make this write:
        // a site whose default_role was already an editing role would otherwise have
        // that value restorable by reverting the change that closed it, which is the
        // refusal arriving one call late.
        //
        // Removing an option writes no value, so only the outright refusals can apply
        // to it. Running the value checks over a deletion would refuse it for failing
        // to be a valid value, which is true and beside the point.
        $absent = !empty( $entry['absent'] );

        // What will actually be written, worked out before the policy is asked about it.
        // A partly redacted snapshot is not the value that goes to disk: the blanked
        // leaves take whatever the option holds now, because writing the marker over a
        // live credential would destroy the thing the blanking protected. Asking the
        // policy about the snapshot instead would be asking about a value that is never
        // written.
        $kept = 0;
        $restore = $entry['previous'] ?? null;
        if ( !$absent && !empty( $entry['partly_redacted'] ) ) {
          $restore = self::refill( $restore, get_option( (string) $entry['key'] ), $kept );
        }

        $refusals = GMCP_Core::unwritable_options();
        $policy = $absent
          ? ( $refusals[ strtolower( (string) $entry['key'] ) ] ?? true )
          : GMCP_Core::option_write_policy( (string) $entry['key'], $restore );
        if ( $policy !== true ) {
          return [ 'ok' => false, 'message' => 'That change cannot be reverted. ' . $policy ];
        }
        if ( $absent ) {
          delete_option( $entry['key'] );
          $done = "Option \"{$entry['key']}\" removed, which is what it was before.";
        }
        else {
          update_option( $entry['key'], $restore );
          $done = "Option \"{$entry['key']}\" restored to its previous value.";
          if ( $kept > 0 ) {
            $done .= ' ' . $kept . ' field' . ( $kept === 1 ? '' : 's' )
              . ' looked like a credential and was never recorded, so '
              . ( $kept === 1 ? 'it was' : 'they were' ) . ' left exactly as found rather than overwritten.';
          }
        }
      }
      else {
        $post_id = (int) $entry['ID'];
        $restored = [];
        $fields = [ 'ID' => $post_id ];
        foreach ( (array) ( $entry['fields'] ?? [] ) as $field ) {
          if ( !array_key_exists( $field, (array) $entry['previous'] ) ) {
            continue;
          }
          $value = $entry['previous'][ $field ];
          if ( self::too_large( $value ) ) {
            continue;
          }
          $fields[ $field ] = $value;
          $restored[] = str_replace( 'post_', '', $field );
        }
        if ( count( $fields ) > 1 ) {
          wp_update_post( $fields );
        }
        $done = $restored
          ? 'Restored the ' . implode( ', ', array_unique( $restored ) ) . ' of post ' . $post_id . '.'
          : 'Nothing was left to restore on post ' . $post_id . '.';
      }

      GMCP_Changes::resume( $was );

      // Marked rather than removed, so the history still shows that it happened and
      // that it was put back. A revert cannot be double-applied.
      $log[ $index ]['undone'] = time();
      update_option( self::OPTION, $log, false );

      return [ 'ok' => true, 'message' => $done ];
    }

    return [ 'ok' => false, 'message' => "No recorded change with id \"{$id}\". Call wp_list_changes to see what is on record." ];
  }

  public static function clear(): void {
    delete_option( self::OPTION );
  }
}
