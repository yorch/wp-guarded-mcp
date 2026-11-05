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
* Of the kinds the capture layer reports, this takes options, posts, post meta, term edits
* (name, slug, description, parent), which terms a post carries, and individual theme
* settings, and ignores the rest. Users, comments, plugins and themes are recorded in the
* audit log because a reader wants to know about them; they are absent here because
* nothing in this file could put them back, and an undo list full of entries that cannot
* be undone is worse than one that is honest about its reach.
*
* CREATIONS AND DELETIONS ARE OUT, on purpose, for posts, terms, menus, menu items and
* users alike. Putting back a deletion means recreating a thing with its old id, children,
* relationships and meta, and putting back a creation means deleting one; neither is the
* same write in reverse, and the plugin keeps deletion behind its own confirmation steps
* rather than behind an undo. A menu item's edit IS covered, because it is a post and
* some meta rows; adding or deleting one is not.
*
* AN UNDO IS ITSELF A CHANGE. Reverting records a new entry whose "previous" is what the
* revert overwrote and which names the entry it reversed, so reverting the revert puts the
* edit back. That second step refuses when the target has changed since the first, because
* it would otherwise overwrite work done after the undo with a copy taken before it. The
* first step (undoing an ordinary entry) has never carried that refusal and still does not:
* it restores the recorded fields and leaves everything else alone.
*
* Two things it deliberately will not record:
*
* Anything GMCP_Core::option_guard() refuses, by name or by the names inside its value.
* The journal writes previous values into an option row, so recording the plugin's own
* settings would copy the bearer token into a second place. The value check exists
* because the name check is not enough: woocommerce_stripe_settings is an innocuous name
* holding a live secret_key, and matching only on the name would journal it in full.
*
* The same goes for free text. A post body is prose, and a settings snippet or a pasted
* wp-config line inside it names no field, so field names alone passed it. Every kind now
* also asks GMCP_Credential_Scan about the strings it would keep, and a post field that
* fails is left out of the entry and named in redacted_fields: the rest of the edit stays
* undoable and nothing is stored in the field's place, because an undo would write a
* placeholder over live content.
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
  * A change to a theme-mods row naming more keys than this is recorded as one whole-row
  * entry instead of one entry per key, so a single wp_update_option on the row cannot fill
  * the 40-entry log by itself.
  */
  const THEMOD_MAX_KEYS = 10;

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
  /**
  * How deep the credential check walks before it refuses a meta value on depth alone.
  *
  * holds_credential() defaults to six, which is right for the audit log and the option
  * summaries, where a value that deep is unusual and refusing to record one costs a line
  * a person reads. It is wrong here and measurably so. A page-builder design nests far
  * past six, and further than its JSON suggests: prepare_meta_value() decodes the design
  * into the array the row actually holds, and thirteen nested containers walk twenty-eight
  * levels that way. So every design was refused on the depth rule with nothing
  * credential-shaped anywhere in it, and the refusal declined precisely the values this
  * feature exists to restore.
  *
  * Raising it is safe for a different reason rather than a weaker one. A snapshot is a
  * second copy of bytes already sitting unencrypted in wp_postmeta, it is written back
  * verbatim, and it is never rendered. What the deeper limit does NOT do is relax the
  * check: a secret buried below six levels is now found and refused, where before it was
  * refused only by the blanket depth rule that refused everything else with it.
  */
  const SNAPSHOT_CREDENTIAL_DEPTH = 64;

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
    $kind = (string) ( $change['kind'] ?? '' );
    // A record whose values were withheld (a post that is, or was before this write, part of a
    // form submission's tree) holds markers where the previous values were. Recording one
    // would offer an undo that writes the markers over the post, and the post may no longer
    // look like a submission's now, so the current state cannot be what decides this.
    // @see GMCP_Changes::withhold_personal_data()
    if ( !empty( $change['withheld'] ) ) {
      return;
    }
    if ( $kind === 'meta' ) {
      $this->observe_meta( $change );
    }
    if ( $kind === 'option' ) {
      $this->observe_option( $change );
    }
    elseif ( $kind === 'post' ) {
      $this->observe_post( $change );
    }
    elseif ( $kind === 'term' ) {
      $this->observe_term( $change );
    }
    elseif ( $kind === 'termrel' ) {
      $this->observe_termrel( $change );
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
      self::record( [
        'kind' => 'option',
        'key' => $key,
        'what' => 'Option "' . self::label( $key, 120 ) . '" created',
        'previous' => null,
        'absent' => true,
        'tool' => (string) ( $change['tool'] ?? '' ),
      ] );
      return;
    }
    if ( ( $change['op'] ?? '' ) !== 'updated' ) {
      return;
    }
    if ( strpos( $key, 'theme_mods_' ) === 0 && $this->observe_themod( $change, $key ) ) {
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
      'what' => 'Option "' . self::label( $key, 120 ) . '" changed',
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];
    if ( GMCP_Core::holds_credential( $old ) || GMCP_Credential_Scan::holds( $old ) ) {
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
        $entry['previous'] = self::storable( $clean );
        $entry['partly_redacted'] = true;
      }
      else {
        $entry['previous'] = null;
        $entry['redacted'] = true;
      }
    }
    else {
      $entry['previous'] = self::storable( $old );
    }
    self::record( $entry );
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
  * counts as a secret. It is asked to walk further, for the reason
  * SNAPSHOT_CREDENTIAL_DEPTH sets out, which makes this path stricter than the option
  * side rather than more permissive.
  */
  private function observe_meta( array $change ): void {
    $post_id = (int) ( $change['id'] ?? 0 );
    $meta_key = (string) ( $change['meta_key'] ?? '' );
    $op = (string) ( $change['op'] ?? '' );
    if ( $post_id <= 0 || $meta_key === '' ) {
      return;
    }
    // An entry this journal will never undo is not worth a place on a forty-entry list:
    // a shop tool saving one order writes a dozen meta rows, and journalling them would push
    // the agent's real, reversible changes off the end. The audit log still records them.
    if ( !GMCP_Changes::undoable_post( $post_id ) ) {
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
        . self::label( (string) ( $change['label'] ?? '' ) ) . '" custom field "'
        . $meta_key . '" ' . $verb,
      'absent' => empty( $change['existed'] ),
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];

    // The row an addition created, so undo can remove that row and not every row of the
    // key. A key holding several rows is ordinary: _wp_old_slug gains one per rename.
    if ( $op === 'created' && !empty( $change['mid'] ) ) {
      $entry['mid'] = (int) $change['mid'];
    }

    // What is kept for a deletion, and for an update of a key holding more than one row, is
    // every row it touched, by meta id. A single value restored with update_post_meta()
    // overwrote every sibling with it, and never re-added a row that was deleted while
    // others of the key remained. A single-row update keeps the single value, as before.
    $rows = isset( $change['rows'] ) && is_array( $change['rows'] ) ? $change['rows'] : null;
    if ( $rows !== null && ( $op === 'deleted' || count( $rows ) > 1 ) ) {
      $change['previous'] = [ self::META_ROWS => $rows ];
      $entry['rows'] = count( $rows );
    }

    self::record( self::meta_body( $entry, $change['previous'] ?? null ) );
  }

  /**
  * Everything a meta entry carries beyond its address, decided in one place.
  *
  * Shared by the recorder and by undo's own record of what it overwrote, so the two cannot
  * disagree about what counts as a secret, a value too large to keep, or a key that was not
  * there. Writes the snapshot row when there is one to write.
  *
  * A key whose NAME looks like a credential, or a value that holds one, is recorded as
  * changed and its previous value is not kept. Same functions as the option side, so the
  * journal cannot protect a secret in an option and copy the same secret out of a meta
  * row. The one difference is how far the value is walked, and it is a difference in
  * reach rather than in what counts: this path finds a credential the option side gives
  * up before reaching. @see SNAPSHOT_CREDENTIAL_DEPTH.
  *
  * @param array $entry    kind, ID, key, what, absent, tool, call
  * @param mixed $previous The value to keep, ignored when the entry says absent.
  */
  private static function meta_body( array $entry, $previous ): array {
    $post_id = (int) $entry['ID'];
    $meta_key = (string) $entry['key'];
    if ( GMCP_Core::field_looks_secret( $meta_key ) || self::unsafe_to_keep( $previous ) ) {
      $entry['redacted'] = true;
      return $entry;
    }

    // Nothing to keep for a key that was not there. The absent flag is the whole record.
    if ( !empty( $entry['absent'] ) ) {
      $entry['snapshot'] = 0;
      return $entry;
    }

    $serialized = maybe_serialize( $previous );
    $bytes = strlen( (string) $serialized );
    if ( $bytes > self::SNAP_MAX_VALUE ) {
      $entry['too_large'] = $bytes;
      return $entry;
    }

    // The id is minted before the row is written so the snapshot can carry it, which is
    // what lets a pruned snapshot be told apart from an entry that never had one.
    if ( empty( $entry['id'] ) ) {
      $entry['id'] = bin2hex( random_bytes( 5 ) );
    }
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
    return $entry;
  }

  /** The stored previous value for an entry, or null when there is no usable snapshot. */
  private static function snapshot_for( string $entry_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant
      "SELECT meta_value FROM " . self::snapshot_table() . " WHERE entry_id = %s ORDER BY id DESC LIMIT 1",
      $entry_id
    ) );
    return $row ? maybe_unserialize( $row->meta_value ) : null;
  }

  private static function snapshot_exists( string $entry_id ): bool {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant
      "SELECT COUNT(*) FROM " . self::snapshot_table() . " WHERE entry_id = %s",
      $entry_id
    ) ) > 0;
  }

  /**
  * One theme setting, rather than the whole row it lives in.
  *
  * theme_mods_<stylesheet> holds every Customizer value for a theme in one array, and so
  * does the menu-location assignment (nav_menu_locations is one key inside it). Recording
  * the row as an option entry meant undoing one setting wrote back the whole array and
  * threw away any other setting changed after it. One entry per changed key puts back that
  * key alone.
  *
  * Returns false when the row cannot be read as two arrays or too many keys moved at once,
  * and the caller then records the row whole as it always did.
  */
  private function observe_themod( array $change, string $row ): bool {
    $diff = self::themod_diff( $change['fields']['value']['from'] ?? null, $change['fields']['value']['to'] ?? null );
    if ( $diff === null ) {
      return false;
    }
    $theme = substr( $row, strlen( 'theme_mods_' ) );
    foreach ( $diff as $one ) {
      $verb = $one['absent'] ? 'added' : ( $one['removed'] ? 'removed' : 'changed' );
      $entry = [
        'kind' => 'themod',
        'row' => $row,
        'key' => $one['key'],
        'what' => 'Theme setting "' . self::label( (string) $one['key'], 60 ) . '" of "' . self::label( (string) $theme, 60 ) . '" ' . $verb,
        'absent' => $one['absent'],
        'tool' => (string) ( $change['tool'] ?? '' ),
        'call' => (string) ( $change['call'] ?? '' ),
      ];
      if ( GMCP_Core::field_looks_secret( $one['key'] ) || self::unsafe_to_keep( $one['from'] ) ) {
        // The same two tests as a meta value. The key is not restorable and says why; the
        // rest of the row is unaffected, which the whole-row entry could not offer.
        $entry['redacted'] = true;
      }
      elseif ( !$one['absent'] ) {
        $entry['previous'] = self::storable( $one['from'] );
      }
      self::record( $entry );
    }
    return true;
  }

  /**
  * Which keys of a theme-mods row differ, and what each held.
  *
  * Pure, so it can be exercised without WordPress.
  *
  * @param mixed $old The row before.
  * @param mixed $new The row after.
  * @return array|null One element per key: key, absent (not in the old row), removed (not in
  *                    the new row), from (the old value). Null when either side is not an
  *                    array or more than THEMOD_MAX_KEYS keys differ.
  */
  public static function themod_diff( $old, $new ): ?array {
    if ( !is_array( $old ) || !is_array( $new ) ) {
      return null;
    }
    $out = [];
    foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $k ) {
      $had = array_key_exists( $k, $old );
      $has = array_key_exists( $k, $new );
      if ( $had && $has && $old[ $k ] === $new[ $k ] ) {
        continue;
      }
      $out[] = [ 'key' => (string) $k, 'absent' => !$had, 'removed' => !$has, 'from' => $had ? $old[ $k ] : null ];
    }
    return count( $out ) > self::THEMOD_MAX_KEYS ? null : $out;
  }

  /** The row with one key put back (or taken out again, when it was not there before). Pure. */
  public static function themod_apply( array $row, string $key, bool $absent, $value ): array {
    if ( $absent ) {
      unset( $row[ $key ] );
    }
    else {
      $row[ $key ] = $value;
    }
    return $row;
  }

  /** The term fields an edit is recorded for, and that undo may write. */
  const TERM_FIELDS = [ 'name', 'slug', 'description', 'parent' ];

  /**
  * An edited term. A menu is a term in the nav_menu taxonomy, so renaming or re-slugging
  * one arrives here as well. Creations and deletions go past.
  */
  private function observe_term( array $change ): void {
    $taxonomy = (string) ( $change['taxonomy'] ?? '' );
    if ( ( $change['op'] ?? '' ) !== 'updated' || $taxonomy === '' ) {
      return;
    }
    $fields = array_values( array_intersect( array_keys( (array) ( $change['fields'] ?? [] ) ), self::TERM_FIELDS ) );
    if ( !$fields ) {
      return;
    }
    $previous = [];
    foreach ( $fields as $field ) {
      $previous[ $field ] = $change['fields'][ $field ]['from'] ?? null;
    }
    $entry = [
      'kind' => 'term',
      'ID' => (int) ( $change['id'] ?? 0 ),
      'taxonomy' => $taxonomy,
      'what' => ucfirst( (string) ( $change['subject'] ?? 'term' ) ) . ' "'
        . self::label( (string) ( $change['label'] ?? '' ) ) . '" changed: ' . implode( ', ', $fields ),
      'fields' => $fields,
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];
    if ( self::unsafe_to_keep( $previous ) ) {
      $entry['redacted'] = true;
    }
    else {
      $entry['previous'] = $previous;
    }
    self::record( $entry );
  }

  /** Which terms a post carried in one taxonomy, before a call changed them. */
  private function observe_termrel( array $change ): void {
    $taxonomy = (string) ( $change['taxonomy'] ?? '' );
    $pair = (array) reset( $change['fields'] );
    if ( ( $change['op'] ?? '' ) !== 'updated' || $taxonomy === '' || !isset( $pair['from'] ) ) {
      return;
    }
    // A record about a form submission reaches this point with its values withheld, and what
    // is left in 'from' is not a list of term ids: recording it would offer an undo that sets
    // a submission's terms to nothing. Only that case is refused here. A commerce post stays
    // recorded, so the undo path's own guard answers for it with its reason.
    if ( GMCP_Core::personal_data_post_guard( (int) ( $change['id'] ?? 0 ) ) !== true ) {
      return;
    }
    self::record( [
      'kind' => 'termrel',
      'ID' => (int) ( $change['id'] ?? 0 ),
      'taxonomy' => $taxonomy,
      'what' => ucfirst( (string) ( $change['subject'] ?? 'post' ) ) . ' "'
        . self::label( (string) ( $change['label'] ?? '' ) ) . '" terms in "' . $taxonomy . '" changed',
      'previous' => self::storable( array_values( array_map( 'intval', (array) $pair['from'] ) ) ),
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ] );
  }

  /**
  * An edited post. Creations and deletions go past: this restores fields, and neither of
  * those is a field to restore.
  */
  private function observe_post( array $change ): void {
    if ( ( $change['op'] ?? '' ) !== 'updated' ) {
      return;
    }
    // Same reason as observe_meta(): an order or a subscription is never put back from
    // here, so recording one would only evict entries that can be.
    if ( !GMCP_Changes::undoable_post( (int) ( $change['id'] ?? 0 ) ) ) {
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
        . self::label( (string) ( $change['label'] ?? '' ) ) . '" changed: ' . implode( ', ', $changed ),
      'fields' => $changed,
      'previous' => [],
      'tool' => (string) ( $change['tool'] ?? '' ),
      'call' => (string) ( $change['call'] ?? '' ),
    ];
    $values = [];
    foreach ( $change['fields'] as $field => $pair ) {
      $values[ $field ] = $pair['from'] ?? null;
    }
    [ $entry['previous'], $dropped ] = self::post_previous( $values );
    if ( $dropped ) {
      // Named, so the listing and the refusal can say which part was never recorded. The
      // value is not here and is not anywhere else in the entry.
      $entry['redacted_fields'] = $dropped;
    }
    if ( self::oversized( $entry['previous'] ) ) {
      $entry['note'] = 'Part of the previous version was too large to keep, so reverting will restore only what fits.';
    }
    self::record( $entry );
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

  /**
  * A title or name as it may appear in an entry's "what" line: cut to length, and withheld
  * when it is itself credential-shaped. The line is shown by wp_list_changes and copied
  * into the next undo's own line, and a post title is the one piece of free text it quotes.
  */
  private static function label( string $text, int $len = 60 ): string {
    $cut = mb_substr( $text, 0, $len );
    // The wider test (GMCP_Core::looks_credentialed()), not the scanner alone. A title of
    // "smtp_pwd=hunter" was quoted in the entry, and wp_list_changes is a read-level tool.
    // Over-redacting a label is the cheap direction. This is NOT how the journal decides whether
    // to keep a value for undo (unsafe_to_keep() is, and stays, the precise scanner): a value it
    // refuses to keep is a value it cannot put back.
    return GMCP_Core::looks_credentialed( $cut ) ? '[redacted]' : $cut;
  }

  /**
  * A whole "what" line as it may be stored and shown: itself, or a plain sentence when it holds
  * something credential-shaped.
  *
  * Applied when an entry is recorded (so an undo's "Undo of: ..." line, which copies the original's,
  * cannot carry one forward) and again when entries are listed, because a stored entry outlives
  * the code that wrote it: one recorded before the label was checked this way can still name a
  * post by a title that is a credential.
  */
  private static function safe_what( string $what ): string {
    return GMCP_Core::looks_credentialed( $what ) ? 'A change whose description held something credential-shaped (withheld).' : $what;
  }

  /**
  * Whether a value is unsafe to keep a copy of: a secret-named field anywhere in it, or free
  * text carrying one (a pasted wp-config line, a JSON snippet, an Authorization header).
  *
  * The one question every kind asks of a value it is about to store. holds_credential() alone
  * judges names and opens only values that BEGIN as JSON or serialized data, so a post body
  * with {"api_key":"..."} in the middle of it was copied whole. @see GMCP_Credential_Scan.
  */
  private static function unsafe_to_keep( $value ): bool {
    return GMCP_Credential_Scan::holds( $value );
  }

  /**
  * Post fields split into what may be kept and what may not.
  *
  * Per field, so one credential-shaped body does not take the title's undo with it, and so the
  * thing dropped is dropped and never replaced: a placeholder stored in `previous` would be
  * written over live content by the undo, which is the failure the option path's refill()
  * exists to prevent. A field left out of `previous` is simply not restored.
  *
  * @param array $values field => value
  * @return array{0:array,1:string[]} [ what may be kept, the names of the fields that were dropped ]
  */
  private static function post_previous( array $values ): array {
    $keep = [];
    $dropped = [];
    foreach ( $values as $field => $value ) {
      if ( self::unsafe_to_keep( $value ) ) {
        $dropped[] = (string) $field;
        continue;
      }
      $keep[ $field ] = self::storable( $value );
    }
    return [ $keep, $dropped ];
  }

  /** Big previous values are described rather than copied, so the option stays small. */
  private static function storable( $value ) {
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

  private static function oversized( array $values ): bool {
    foreach ( $values as $value ) {
      if ( self::too_large( $value ) ) {
        return true;
      }
    }
    return false;
  }

  private static function record( array $entry ): void {
    $entry['t'] = time();
    if ( isset( $entry['what'] ) ) {
      $entry['what'] = self::safe_what( (string) $entry['what'] );
    }
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
      // An entry written before records about form submissions were withheld names the post by
      // its title, which is the visitor's subject line. Rewritten on the way out rather than
      // trusted to have been written clean, because the stored row outlives the code that
      // wrote it.
      $what = self::safe_what( (string) ( $entry['what'] ?? '' ) );
      if ( in_array( $entry['kind'] ?? '', [ 'post', 'meta', 'termrel' ], true ) && !empty( $entry['ID'] )
        && GMCP_Core::personal_data_post_guard( (int) $entry['ID'] ) !== true ) {
        $what = 'A form submission (#' . (int) $entry['ID'] . ') was changed. Its title and values are not shown.';
      }
      $row = [
        'id' => $entry['id'] ?? '',
        'when' => gmdate( 'Y-m-d H:i', (int) ( $entry['t'] ?? 0 ) ) . ' GMT',
        'tool' => $entry['tool'] ?? '',
        // Entries sharing a call came from one tool call. A page build writes several
        // things and they read as unrelated events without this.
        'call' => $entry['call'] ?? '',
        'what' => $what,
        'reversible' => self::reversible( $entry ) === true,
      ];
      // An undo is an entry too. Naming what it reversed is what lets a caller see that
      // reverting THIS one puts the original change back.
      if ( !empty( $entry['undoes'] ) ) {
        $row['undoes'] = (string) $entry['undoes'];
      }
      $why = self::reversible( $entry );
      if ( $why !== true ) {
        $row['not_reversible_because'] = $why;
      }
      // Said out loud rather than left to be discovered. "Reversible" on its own would
      // promise a restore this entry cannot give, and a caller deciding whether to undo
      // needs to know the credential-shaped fields will stay as they are.
      elseif ( !empty( $entry['partly_redacted'] ) || !empty( $entry['redacted_fields'] ) ) {
        $row['partial_restore'] = 'Fields that looked like credentials were never recorded. '
          . 'Everything else goes back; those are left exactly as they are now.';
      }
      $out[] = $row;
    }
    return $out;
  }

  /**
  * Whether a drafts-only caller may put this entry back, as the refusal or true.
  *
  * Shared by the listing and the revert so the two cannot disagree, which is how the
  * gates loop and the revert once came to answer differently.
  *
  * @return true|string
  */
  private static function drafts_only_refusal( array $entry ) {
    $kind = (string) ( $entry['kind'] ?? '' );
    // Not posts. An option, a term and a theme setting are not rows of the posts table, and
    // a term's ID is a term id that could equal any post's, so asking the post guard about it
    // would refuse or allow by coincidence. Each is held back by its own gate instead
    // (wp_update_option, wp_update_term, wp_set_theme_mod), asked before this.
    if ( !GMCP_Core::caller_drafts_only() || in_array( $kind, [ 'option', 'term', 'themod' ], true ) ) {
      return true;
    }
    if ( $kind === 'meta' ) {
      return GMCP_Core::drafts_only_guard( (int) $entry['ID'], 'Reverting meta on' );
    }
    // A term assignment is a write to the post it is assigned to: putting the terms back on
    // a page that has since gone live is editing that live page.
    if ( $kind === 'termrel' ) {
      return GMCP_Core::drafts_only_guard( (int) $entry['ID'], 'Reverting the terms of' );
    }
    $previous = (array) ( $entry['previous'] ?? [] );
    $status = array_key_exists( 'post_status', $previous ) && in_array( 'post_status', (array) ( $entry['fields'] ?? [] ), true )
      ? (string) $previous['post_status'] : '';
    return GMCP_Core::drafts_only_guard( (int) $entry['ID'], 'Reverting', $status );
  }

  /**
  * @return true|string True if it can be put back, otherwise why not.
  *
  * Two layers. The kind decides whether the thing can be put back at all. An entry that is
  * itself the record of an undo carries after_state, the state its target was left in, and
  * is refused when the target has moved since: its copy predates whatever happened next, and
  * restoring it would silently discard that.
  */
  private static function reversible( array $entry ) {
    $why = self::reversible_kind( $entry );
    if ( $why !== true ) {
      return $why;
    }
    if ( isset( $entry['after_state'] ) && !self::state_matches( $entry ) ) {
      return 'It has changed since that undo was made, so putting the earlier copy back would discard the newer change. Read the current value and make the edit again if it is still wanted.';
    }
    return true;
  }

  /** Why a post may not be touched by an undo because a shop owns it, or null. */
  private static function commerce_refusal( int $post_id ): ?string {
    $guard = GMCP_Core::commerce_post_guard( $post_id, 'Reverting a change to' );
    return $guard === true ? null : (string) $guard;
  }

  /**
  * Why an order or a subscription is not put back, as a sentence for the entry kinds that
  * reach a post row. The backstop behind commerce_refusal(): the guard says no for the post
  * types it knows, and undoable_post() says no for anything this journal does not record.
  */
  private static function not_journalled_refusal( int $post_id ): string {
    if ( GMCP_Core::personal_data_post_guard( $post_id ) !== true ) {
      return 'It belongs to a form submission, personal data this journal neither shows nor restores: the previous values of such a post are not kept, and an entry written before that was the case is not put back either.';
    }
    return 'It belongs to a ' . self::post_type_label( $post_id ) . ', which the generic post tools do not write and this journal does not revert. '
      . 'Putting an order or a subscription back with a plain post write skips every status transition, stock and payment hook the shop runs, so the site and the payment gateway would disagree. '
      . 'Use the WooCommerce tools to change it.';
  }

  private static function reversible_kind( array $entry ) {
    if ( !empty( $entry['undone'] ) ) {
      return 'It has already been reverted.'
        . ( !empty( $entry['undone_by'] ) ? ' Reverting entry ' . $entry['undone_by'] . ' puts the change back.' : '' );
    }
    foreach ( self::gates( $entry ) as $tool ) {
      if ( !apply_filters( 'gmcp_can_call_tool', false, $tool ) ) {
        return "It needs {$tool}, which this connection cannot call.";
      }
    }
    $drafts = self::drafts_only_refusal( $entry );
    if ( $drafts !== true ) {
      return $drafts;
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
    if ( ( $entry['kind'] ?? '' ) === 'themod' ) {
      if ( !empty( $entry['redacted'] ) ) {
        return 'The previous value looked like it held a credential, so it was never stored.';
      }
      if ( empty( $entry['absent'] ) && self::too_large( $entry['previous'] ?? null ) ) {
        return 'The previous value was too large to keep.';
      }
      if ( GMCP_Core::option_guard( (string) $entry['row'] ) !== true ) {
        return 'That option is protected.';
      }
      return is_array( get_option( (string) $entry['row'], [] ) )
        ? true
        : 'The theme settings row is no longer an array, so one setting cannot be put back into it.';
    }
    if ( ( $entry['kind'] ?? '' ) === 'term' ) {
      if ( !taxonomy_exists( (string) $entry['taxonomy'] ) ) {
        return 'The taxonomy is no longer registered.';
      }
      if ( !( get_term( (int) $entry['ID'], (string) $entry['taxonomy'] ) instanceof WP_Term ) ) {
        return 'The term no longer exists.';
      }
      if ( !empty( $entry['redacted'] ) ) {
        return 'The previous value looked like it held a credential, so it was never stored.';
      }
      return self::too_large( $entry['previous'] ?? null ) ? 'The previous value was too large to keep.' : true;
    }
    if ( ( $entry['kind'] ?? '' ) === 'termrel' ) {
      if ( !get_post( (int) $entry['ID'] ) ) {
        return 'The post no longer exists.';
      }
      $owned = self::commerce_refusal( (int) $entry['ID'] );
      if ( $owned !== null ) {
        return $owned;
      }
      if ( !GMCP_Changes::undoable_post( (int) $entry['ID'] ) ) {
        return self::not_journalled_refusal( (int) $entry['ID'] );
      }
      if ( !taxonomy_exists( (string) $entry['taxonomy'] ) ) {
        return 'The taxonomy is no longer registered.';
      }
      return self::too_large( $entry['previous'] ?? null ) ? 'The previous set of terms was too large to keep.' : true;
    }
    if ( ( $entry['kind'] ?? '' ) === 'meta' ) {
      if ( !get_post( (int) $entry['ID'] ) ) {
        return 'The post no longer exists.';
      }
      $owned = self::commerce_refusal( (int) $entry['ID'] );
      if ( $owned !== null ) {
        return $owned;
      }
      if ( !GMCP_Changes::undoable_post( (int) $entry['ID'] ) ) {
        return self::not_journalled_refusal( (int) $entry['ID'] );
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
      $owned = self::commerce_refusal( (int) $entry['ID'] );
      if ( $owned !== null ) {
        return $owned;
      }
      if ( !GMCP_Changes::undoable_post( (int) $entry['ID'] ) ) {
        return self::not_journalled_refusal( (int) $entry['ID'] );
      }
      foreach ( (array) ( $entry['previous'] ?? [] ) as $value ) {
        if ( !self::too_large( $value ) ) {
          return true;
        }
      }
      if ( !empty( $entry['redacted_fields'] ) ) {
        return 'The previous value looked like it held a credential, so it was never stored.';
      }
      return 'Every previous value was too large to keep.';
    }
    return 'Unknown change type.';
  }

  /** The post type of an entry's target, for naming it in a refusal. */
  private static function post_type_label( int $post_id ): string {
    $post = get_post( $post_id );
    return $post ? $post->post_type : 'post type';
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
      // A term edit is wp_update_term backwards, a set of terms is wp_add_post_terms
      // backwards, and one theme setting is wp_set_theme_mod backwards. The tool that was in
      // flight is still required as well, so a menu rename made through an admin-level menu
      // tool is not reversible by a caller who can only edit ordinary terms.
      'term' => 'wp_update_term',
      'termrel' => 'wp_add_post_terms',
      'themod' => 'wp_set_theme_mod',
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
  * The tools whose writes an undo would repeat, for the approval gate. Null when the log holds
  * no such entry, which the gate reads as "cannot tell", and holds.
  *
  * Exactly what revert() and revert_call() would act on: one entry by id, or every entry of a
  * call that has not been undone. The union of gates(), so an option entry yields
  * wp_update_option as well as the tool that was in flight when it was recorded.
  *
  * @return string[]|null
  */
  public static function undo_tools( string $id, string $call ): ?array {
    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];
    $tools = [];
    $found = false;
    foreach ( $log as $entry ) {
      if ( !is_array( $entry ) ) {
        continue;
      }
      $match = $call !== ''
        ? ( (string) ( $entry['call'] ?? '' ) === $call && empty( $entry['undone'] ) )
        : ( (string) ( $entry['id'] ?? '' ) === $id );
      if ( $match ) {
        $found = true;
        $tools = array_merge( $tools, self::gates( $entry ) );
      }
    }
    return $found ? array_values( array_unique( $tools ) ) : null;
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

    foreach ( $log as $entry ) {
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

      // A revert is a post write like any other, so a drafts-only key is asked the same
      // question here, before anything is paused or touched. Restoring a field on a page
      // that has since gone live is editing that page, and "undo" is not a different verb.
      $drafts = self::drafts_only_refusal( $entry );
      if ( $drafts !== true ) {
        return [ 'ok' => false, 'message' => 'That change cannot be reverted. ' . $drafts ];
      }

      // A revert writes options and posts like anything else, and recording it through the
      // capture layer would put the change back on the list as though the agent had made it.
      // The undo is recorded below instead, deliberately and with a link to what it undid.
      //
      // Resumed in a finally. The policy refusals inside apply() return early, and an early
      // return that skipped the resume left recording switched off for the rest of the request.
      $was = GMCP_Changes::pause();
      try {
        $result = self::apply( $entry );
      }
      finally {
        GMCP_Changes::resume( $was );
      }
      if ( empty( $result['ok'] ) ) {
        return [ 'ok' => false, 'message' => (string) $result['message'] ];
      }

      // The undo is itself a change: what it overwrote is kept, so reverting it puts the
      // edit back. Recorded before the original is marked, and the mark re-reads the log,
      // because recording rewrites the row and a copy loaded above would erase the new entry.
      $by = '';
      if ( !empty( $result['redo'] ) ) {
        $by = self::record_undo( $entry, $result['redo'] );
      }
      self::mark_undone( $id, $by );

      return [
        'ok' => true,
        'message' => $result['message'] . ( $by !== '' ? ' This undo is itself on record as entry ' . $by . ', and reverting that entry puts the edit back.' : '' ),
      ];
    }

    return [ 'ok' => false, 'message' => "No recorded change with id \"{$id}\". Call wp_list_changes to see what is on record." ];
  }

  /**
  * Mark one entry reverted in a copy of the log. Pure.
  *
  * Marked rather than removed, so the history still shows that it happened and that it was
  * put back. A revert cannot be double-applied; the way back is the entry named in by.
  */
  public static function mark_in_log( array $log, string $id, int $when, string $by ): array {
    foreach ( $log as $index => $entry ) {
      if ( ( $entry['id'] ?? '' ) === $id ) {
        $log[ $index ]['undone'] = $when;
        if ( $by !== '' ) {
          $log[ $index ]['undone_by'] = $by;
        }
      }
    }
    return $log;
  }

  private static function mark_undone( string $id, string $by ): void {
    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];
    update_option( self::OPTION, self::mark_in_log( $log, $id, time(), $by ), false );
  }

  /**
  * The entry for an undo, saying how to put back what the undo overwrote.
  *
  * It carries the original's tool, not wp_undo_change, so gates() asks for the same
  * permissions to reverse it as the original needed. after_state is measured from the
  * stored target after the write rather than computed from what was meant to be written, so
  * the staleness test compares like with like.
  *
  * @return string The new entry's id.
  */
  private static function record_undo( array $original, array $redo ): string {
    $label = (string) ( $original['what'] ?? '' );
    while ( strpos( $label, 'Undo of: ' ) === 0 ) {
      $label = substr( $label, strlen( 'Undo of: ' ) );
    }
    $redo['what'] = 'Undo of: ' . mb_substr( $label, 0, 140 );
    $redo['undoes'] = (string) ( $original['id'] ?? '' );
    $redo['tool'] = (string) ( $original['tool'] ?? '' );
    if ( empty( $redo['id'] ) ) {
      $redo['id'] = bin2hex( random_bytes( 5 ) );
    }
    $redo['after_state'] = self::state_of( $redo );
    self::record( $redo );
    return (string) $redo['id'];
  }

  /**
  * A comparable fingerprint of what an entry's target holds right now.
  *
  * Not a copy of anything: a hash, so it can be stored for every kind without a size
  * budget and without a second place for a value to leak from. 'absent' is its own state.
  */
  private static function state_of( array $entry, bool $legacy = false ): string {
    $h = static function ( bool $exists, $value ) use ( $legacy ): string {
      return self::state_hash( $exists, $value, $legacy );
    };
    $kind = (string) ( $entry['kind'] ?? '' );
    if ( $kind === 'option' ) {
      $none = '__gmcp_absent__' . bin2hex( random_bytes( 4 ) );
      $value = get_option( (string) $entry['key'], $none );
      return $h( $value !== $none, $value );
    }
    if ( $kind === 'meta' ) {
      $exists = metadata_exists( 'post', (int) $entry['ID'], (string) $entry['key'] );
      if ( !$exists ) {
        return $h( false, null );
      }
      // Every row, not the first. get_post_meta( ..., true ) is one row, so a row added by
      // somebody else after an undo was invisible to the staleness check, and reverting the
      // undo then overwrote it. One row hashes as the single value, exactly as it did before
      // (entries already on record carry that hash), and several rows hash as a list under a
      // wrapper key so one row holding an array can never collide with several rows.
      $rows = (array) get_post_meta( (int) $entry['ID'], (string) $entry['key'], false );
      return $h( true, count( $rows ) > 1 ? [ self::META_ROWS => array_values( $rows ) ] : ( $rows ? reset( $rows ) : null ) );
    }
    if ( $kind === 'post' ) {
      $values = [];
      foreach ( (array) ( $entry['fields'] ?? [] ) as $field ) {
        $value = get_post_field( (string) $field, (int) $entry['ID'], 'raw' );
        $values[ $field ] = is_scalar( $value ) ? (string) $value : $value;
      }
      return $h( true, $values );
    }
    if ( $kind === 'themod' ) {
      $row = get_option( (string) $entry['row'], [] );
      $row = is_array( $row ) ? $row : [];
      $key = (string) $entry['key'];
      return $h( array_key_exists( $key, $row ), $row[ $key ] ?? null );
    }
    if ( $kind === 'term' ) {
      $term = get_term( (int) $entry['ID'], (string) $entry['taxonomy'] );
      if ( !( $term instanceof WP_Term ) ) {
        return 'gone';
      }
      $values = [];
      foreach ( (array) ( $entry['fields'] ?? [] ) as $field ) {
        $values[ $field ] = is_scalar( $term->$field ) ? (string) $term->$field : $term->$field;
      }
      return $h( true, $values );
    }
    if ( $kind === 'termrel' ) {
      return $h( true, GMCP_Changes::term_ids_of( (int) $entry['ID'], (string) $entry['taxonomy'] ) );
    }
    return 'unknown';
  }

  /**
  * The fingerprint itself. Pure, given the site's salt.
  *
  * Keyed: an HMAC under wp_salt(). It used to be md5( serialize( $value ) ), unsalted, stored as
  * after_state for an undone entry. That is a fingerprint of the live value (a stored option, a
  * post field, a meta row) kept in a second place that anyone able to read the journal could
  * test guesses against offline: a low-entropy value, such as a short password-like string, is
  * confirmed by hashing candidates. The key makes the stored string useless away from this site.
  * Truncated to 128 bits, which is ample for "did it change since" and keeps the row small.
  *
  * $legacy computes the old unsalted form, 'v:' and an md5, for one purpose only: an entry already
  * on record carries that string, and state_matches() accepts it until the journal rolls past it
  * (forty entries at most). Nothing writes it any more.
  */
  public static function state_hash( bool $exists, $value, bool $legacy = false ): string {
    if ( !$exists ) {
      return 'absent';
    }
    $blob = serialize( $value );
    if ( $legacy ) {
      return 'v:' . md5( $blob );
    }
    return 'h:' . substr( hash_hmac( 'sha256', $blob, wp_salt( 'auth' ) . 'gmcp-journal-state' ), 0, 32 );
  }

  /**
  * Whether an undo entry's target is still in the state the undo left it in.
  *
  * Compares the keyed fingerprint. An entry recorded before the key existed holds the old md5
  * form, and is compared in that form, so upgrading does not make every undone entry read as
  * "changed since" and refuse to be undone again. 'absent', 'gone' and 'unknown' are plain words
  * in both forms.
  */
  private static function state_matches( array $entry ): bool {
    $stored = (string) ( $entry['after_state'] ?? '' );
    if ( hash_equals( self::state_of( $entry ), $stored ) ) {
      return true;
    }
    return strpos( $stored, 'v:' ) === 0 && hash_equals( self::state_of( $entry, true ), $stored );
  }

  /**
  * Perform the write an entry describes, and describe what it overwrote.
  *
  * The caller has already established that the entry is reversible and that the caller may
  * make the write, and has paused the capture layer.
  *
  * @return array{ok:bool,message:string,redo?:array} redo is the kind-specific body of the
  *         entry that reverses this write, without its bookkeeping.
  */
  private static function apply( array $entry ): array {
    $kind = (string) ( $entry['kind'] ?? '' );
    $fail = function ( string $message ): array {
      return [ 'ok' => false, 'message' => 'That change cannot be reverted. ' . $message ];
    };

    if ( $kind === 'meta' ) {
      $meta_post = (int) $entry['ID'];
      $meta_key = (string) $entry['key'];
      // What is about to be overwritten, read before the write. The rows are counted because
      // an undo of a key holding several rows is not a single-value write, and the entry that
      // would reverse it cannot be a single value either: see $redoable below.
      $had = metadata_exists( 'post', $meta_post, $meta_key );
      $current = $had ? get_post_meta( $meta_post, $meta_key, true ) : null;
      $rows_now = $had ? count( (array) get_post_meta( $meta_post, $meta_key, false ) ) : 0;
      $redoable = $rows_now <= 1;
      if ( !empty( $entry['absent'] ) && !empty( $entry['mid'] ) ) {
        // The one row the addition created, and only if it is still that row: same post,
        // same key. Its siblings were there before and stay.
        $row = get_metadata_by_mid( 'post', (int) $entry['mid'] );
        if ( $row && (int) $row->post_id === $meta_post && self::same_meta_key( (string) $row->meta_key, $meta_key ) ) {
          if ( !delete_metadata_by_mid( 'post', (int) $entry['mid'] ) ) {
            return $fail( 'Removing the added custom field "' . $meta_key . '" row from post ' . $meta_post . ' failed. Nothing was marked as reverted.' );
          }
        }
        $done = 'The custom field "' . $meta_key . '" row this change added to post ' . $meta_post . ' was removed; any other rows of that key were left alone.';
      }
      elseif ( !empty( $entry['absent'] ) ) {
        // An entry recorded before additions carried their row id. It was not there before,
        // so putting it back means removing it: every row for the key goes.
        delete_post_meta( $meta_post, wp_slash( $meta_key ) );
        $done = 'Custom field "' . $meta_key . '" removed from post ' . $meta_post . ', which is what it was before.';
      }
      else {
        $meta_value = self::snapshot_for( (string) $entry['id'] );
        if ( is_array( $meta_value ) && array_key_exists( self::META_ROWS, $meta_value ) && is_array( $meta_value[ self::META_ROWS ] ) ) {
          // Row by row. A row that is still there gets its old value back by id, which
          // leaves its siblings alone; a row that is gone, because this change deleted it,
          // is added back. update_metadata_by_mid() does not unslash, add_post_meta() does.
          $redoable = false;
          $restored_rows = 0;
          foreach ( $meta_value[ self::META_ROWS ] as $mid => $old ) {
            $row = get_metadata_by_mid( 'post', (int) $mid );
            if ( $row && (int) $row->post_id === $meta_post && self::same_meta_key( (string) $row->meta_key, $meta_key ) ) {
              if ( maybe_serialize( $row->meta_value ) !== maybe_serialize( $old ) ) {
                update_metadata_by_mid( 'post', (int) $mid, $old );
              }
            }
            else {
              add_post_meta( $meta_post, wp_slash( $meta_key ), wp_slash( $old ) );
            }
            $restored_rows++;
          }
          $done = 'Custom field "' . $meta_key . '" on post ' . $meta_post . ': ' . $restored_rows . ' row' . ( $restored_rows === 1 ? '' : 's' ) . ' put back as they were.';
        }
        elseif ( $rows_now > 1 ) {
          // One recorded value, and the key now holds several rows. update_post_meta() with no
          // previous value rewrites EVERY row of the key to that one value, so restoring it here
          // would turn rows somebody else added since into copies of it and lose what they held.
          // The entry cannot say which row it was, so nothing is written.
          return $fail( 'The custom field "' . $meta_key . '" on post ' . $meta_post . ' now holds ' . $rows_now . ' rows, and this change recorded one value. Restoring it would overwrite every row with that value, so nothing was changed.' );
        }
        else {
          // wp_slash for the reason prepare_meta_value() gives: update_post_meta()
          // unslashes what it is handed, so restoring a JSON or regex value without this
          // puts back a corrupted copy of what was recorded correctly.
          update_post_meta( $meta_post, wp_slash( $meta_key ), wp_slash( $meta_value ) );
          $done = 'Custom field "' . $meta_key . '" on post ' . $meta_post . ' restored to its previous value.';
        }
      }
      clean_post_cache( $meta_post );
      $result = [ 'ok' => true, 'message' => $done ];
      // A key holding several rows is put back row by row, and the one value a redo entry
      // keeps cannot say which rows to restore: replaying it would overwrite every sibling
      // with one value. Such an undo is real but is not itself recorded, and says so.
      if ( $redoable ) {
        $result['redo'] = self::meta_body( [ 'kind' => 'meta', 'ID' => $meta_post, 'key' => $meta_key, 'absent' => !$had ], $current );
      }
      else {
        $result['message'] .= ' The key holds more than one row, so this undo is not itself recorded and cannot be undone.';
      }
      return $result;
    }

    if ( $kind === 'option' ) {
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

      // Undoing a creation deletes the row, so it asks the deletion policy, the same one
      // wp_delete_option asks. Composing the answer here from unwritable_options() alone
      // missed every rule that lives inside the write policy, an audit log's rows and the
      // role definitions among them.
      $policy = $absent
        ? GMCP_Core::option_delete_policy( (string) $entry['key'] )
        : GMCP_Core::option_write_policy( (string) $entry['key'], $restore );
      if ( $policy !== true ) {
        return $fail( $policy );
      }

      $none = '__gmcp_absent__' . bin2hex( random_bytes( 4 ) );
      $current = get_option( (string) $entry['key'], $none );
      $redo = [ 'kind' => 'option', 'key' => (string) $entry['key'] ];
      if ( $current === $none ) {
        $redo['previous'] = null;
        $redo['absent'] = true;
      }
      elseif ( GMCP_Core::holds_credential( $current ) || GMCP_Credential_Scan::holds( $current ) ) {
        // What the undo is about to overwrite holds a credential. It is not copied, for the
        // reason an ordinary entry does not copy one, so this undo cannot itself be undone.
        $redo['previous'] = null;
        $redo['redacted'] = true;
      }
      else {
        $redo['previous'] = self::storable( $current );
      }

      if ( $absent ) {
        // false from delete_option() means either it failed or there was nothing to delete,
        // and only the first is a failure.
        if ( !delete_option( $entry['key'] ) && get_option( $entry['key'], self::ABSENT ) !== self::ABSENT ) {
          return $fail( "Removing option \"{$entry['key']}\" failed. Nothing was marked as reverted, so it can be tried again." );
        }
        $done = "Option \"{$entry['key']}\" removed, which is what it was before.";
      }
      else {
        // Likewise: false from update_option() also means "already held that value".
        if ( !update_option( $entry['key'], $restore ) && maybe_serialize( get_option( $entry['key'] ) ) !== maybe_serialize( $restore ) ) {
          return $fail( "Restoring option \"{$entry['key']}\" failed. Nothing was marked as reverted, so it can be tried again." );
        }
        $done = "Option \"{$entry['key']}\" restored to its previous value.";
        if ( $kept > 0 ) {
          $done .= ' ' . $kept . ' field' . ( $kept === 1 ? '' : 's' )
            . ' looked like a credential and was never recorded, so '
            . ( $kept === 1 ? 'it was' : 'they were' ) . ' left exactly as found rather than overwritten.';
        }
      }
      return [ 'ok' => true, 'message' => $done, 'redo' => $redo ];
    }

    if ( $kind === 'themod' ) {
      $row = (string) $entry['row'];
      $key = (string) $entry['key'];
      $absent = !empty( $entry['absent'] );
      $current_row = get_option( $row, [] );
      if ( !is_array( $current_row ) ) {
        return $fail( 'The theme settings row is no longer an array.' );
      }
      $candidate = self::themod_apply( $current_row, $key, $absent, $entry['previous'] ?? null );
      // The row is what gets written, so the row is what the policy is asked about.
      $policy = GMCP_Core::option_write_policy( $row, $candidate );
      if ( $policy !== true ) {
        return $fail( $policy );
      }
      $had = array_key_exists( $key, $current_row );
      $redo = [ 'kind' => 'themod', 'row' => $row, 'key' => $key, 'absent' => !$had ];
      if ( $had ) {
        if ( GMCP_Core::field_looks_secret( $key ) || self::unsafe_to_keep( $current_row[ $key ] ) ) {
          $redo['redacted'] = true;
        }
        else {
          $redo['previous'] = self::storable( $current_row[ $key ] );
        }
      }
      update_option( $row, $candidate );
      return [
        'ok' => true,
        'message' => $absent
          ? 'Theme setting "' . $key . '" removed, which is what it was before.'
          : 'Theme setting "' . $key . '" restored to its previous value.',
        'redo' => $redo,
      ];
    }

    if ( $kind === 'term' ) {
      $term_id = (int) $entry['ID'];
      $taxonomy = (string) $entry['taxonomy'];
      $term = get_term( $term_id, $taxonomy );
      if ( !( $term instanceof WP_Term ) ) {
        return $fail( 'The term no longer exists.' );
      }
      $args = [];
      $skipped = [];
      foreach ( (array) ( $entry['fields'] ?? [] ) as $field ) {
        if ( !in_array( $field, self::TERM_FIELDS, true ) || !array_key_exists( $field, (array) $entry['previous'] ) ) {
          continue;
        }
        $value = $entry['previous'][ $field ];
        // A parent that has since been deleted cannot be put back, and WordPress would
        // store the dangling id rather than refuse it.
        if ( $field === 'parent' && (int) $value > 0 && !term_exists( (int) $value, $taxonomy ) ) {
          $skipped[] = 'parent';
          continue;
        }
        $args[ $field ] = $value;
      }
      if ( !$args ) {
        return $fail( 'Nothing was left to restore on term ' . $term_id . '.' );
      }
      $redo_previous = [];
      foreach ( array_keys( $args ) as $field ) {
        $redo_previous[ $field ] = $term->$field;
      }
      $result = wp_update_term( $term_id, $taxonomy, $args );
      if ( is_wp_error( $result ) ) {
        return $fail( 'WordPress refused to put the term back: ' . $result->get_error_message() );
      }
      $done = 'Restored the ' . implode( ', ', array_keys( $args ) ) . ' of term ' . $term_id . '.';
      if ( $skipped ) {
        $done .= ' The previous parent no longer exists, so the parent was left as it is.';
      }
      $redo = [ 'kind' => 'term', 'ID' => $term_id, 'taxonomy' => $taxonomy, 'fields' => array_keys( $args ) ];
      // The entry that reverses this undo keeps what the undo overwrote, and what it
      // overwrote is whatever the term held a moment ago, which may be a credential that was
      // written after the entry being undone (observe_term() judges the value it records and
      // this is the same value one step later). Judged the same way, and not copied.
      if ( self::unsafe_to_keep( $redo_previous ) ) {
        $redo['redacted'] = true;
        $done .= ' The value it replaced looked like it held a credential, so that is not recorded and this undo cannot be undone.';
      }
      else {
        $redo['previous'] = $redo_previous;
      }
      return [ 'ok' => true, 'message' => $done, 'redo' => $redo ];
    }

    if ( $kind === 'termrel' ) {
      $post_id = (int) $entry['ID'];
      $taxonomy = (string) $entry['taxonomy'];
      $wanted = array_values( array_map( 'intval', (array) ( $entry['previous'] ?? [] ) ) );
      $present = [];
      foreach ( $wanted as $term_id ) {
        if ( term_exists( $term_id, $taxonomy ) ) {
          $present[] = $term_id;
        }
      }
      $gone = count( $wanted ) - count( $present );
      $current = GMCP_Changes::term_ids_of( $post_id, $taxonomy );
      $result = wp_set_object_terms( $post_id, $present, $taxonomy, false );
      if ( is_wp_error( $result ) ) {
        return $fail( 'WordPress refused to put the terms back: ' . $result->get_error_message() );
      }
      $done = 'Restored the "' . $taxonomy . '" terms of post ' . $post_id . '.';
      if ( $gone > 0 ) {
        $done .= ' ' . $gone . ' of the earlier term' . ( $gone === 1 ? ' has' : 's have' ) . ' since been deleted and could not be restored.';
      }
      return [
        'ok' => true,
        'message' => $done,
        'redo' => [ 'kind' => 'termrel', 'ID' => $post_id, 'taxonomy' => $taxonomy, 'previous' => $current ],
      ];
    }

    if ( $kind === 'post' ) {
      $post_id = (int) $entry['ID'];
      $restored = [];
      $redo_fields = [];
      $redo_values = [];
      $refused = [];
      $fields = [ 'ID' => $post_id ];
      foreach ( (array) ( $entry['fields'] ?? [] ) as $field ) {
        if ( !array_key_exists( $field, (array) $entry['previous'] ) ) {
          continue;
        }
        // Only the fields the recorder watches. The commerce guard is asked about the post
        // as it is NOW (reversible_kind), and it is never asked about a value an entry would
        // write, so a field outside this list could be restored unchecked: post_type is the
        // one that matters, since putting 'shop_order' on a page makes a hand-made order.
        // Nothing records post_type today, and an entry that names it was not made by this
        // plugin's recorder, so it is skipped rather than trusted.
        if ( !in_array( $field, GMCP_Changes::POST_FIELDS, true ) ) {
          continue;
        }
        $value = $entry['previous'][ $field ];
        if ( self::too_large( $value ) ) {
          continue;
        }
        // The statuses no write tool may set are not set by an undo either. WordPress deletes
        // on them from a daily job: a trashed post that still carries its old trash stamp, an
        // auto-draft older than a week. An undo of "published an auto-draft" would write the
        // status back and hand the clean-up a deletion, through a tool that never deletes.
        if ( $field === 'post_status' && GMCP_Core::status_write_refusal( $value ) !== null ) {
          $refused[] = 'status';
          continue;
        }
        $fields[ $field ] = $value;
        $restored[] = str_replace( 'post_', '', $field );
        $redo_fields[] = $field;
        $redo_values[ $field ] = get_post_field( $field, $post_id, 'raw' );
      }
      // What the undo is about to overwrite is what reverting the undo would put back, and it
      // is judged exactly as a recorded value is: it may be a credential written after the
      // entry being undone. Dropped field by field, never replaced by a placeholder.
      [ $redo_previous, $redo_dropped ] = self::post_previous( $redo_values );
      if ( count( $fields ) > 1 ) {
        // Slashed, because wp_insert_post() unslashes what it is given and these are the
        // raw values off the WP_Post, exactly as stored. Unslashed, every backslash in the
        // restored fields was removed: a block attribute Gutenberg stores as \u003cb\u003e
        // came back as u003cbu003e and failed validation, a Windows path lost its
        // separators, and the reply said the post had been restored. The meta branch and the
        // menu tool already knew this.
        $result = wp_update_post( wp_slash( $fields ), true );
        if ( is_wp_error( $result ) || !$result ) {
          return $fail( 'Restoring post ' . $post_id . ' failed'
            . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '.' )
            . ' Nothing was marked as reverted, so it can be tried again.' );
        }
      }
      $done = $restored
        ? 'Restored the ' . implode( ', ', array_unique( $restored ) ) . ' of post ' . $post_id . '.'
        : 'Nothing was left to restore on post ' . $post_id . '.';
      if ( $refused ) {
        $done .= ' The earlier status was left as it is: it is "trash" or "auto-draft", which WordPress deletes on, and the write level does not delete. Use wp_delete_post.';
      }
      $redo = null;
      if ( $redo_fields ) {
        $redo = [ 'kind' => 'post', 'ID' => $post_id, 'fields' => $redo_fields, 'previous' => $redo_previous ];
        if ( $redo_dropped ) {
          $redo['redacted_fields'] = $redo_dropped;
          $done .= ' What it replaced in ' . implode( ', ', array_map( function ( $f ) {
            return str_replace( 'post_', '', $f );
          }, $redo_dropped ) ) . ' looked like it held a credential, so that is not recorded and cannot be put back by undoing this.';
        }
      }
      return [ 'ok' => true, 'message' => $done, 'redo' => $redo ];
    }

    return $fail( 'Unknown change type.' );
  }

  /**
  * Whether a row's key is the key an entry recorded, as the database compares them.
  *
  * The rows were found through wp_postmeta's collation, case-insensitive on a stock
  * install, so a row filed as "Existing_Key" was one of the rows a write of "existing_key"
  * touched. Comparing exactly here would add a duplicate instead of restoring it by id.
  */
  private static function same_meta_key( string $stored, string $recorded ): bool {
    return $stored === $recorded || strtolower( $stored ) === strtolower( $recorded );
  }

  /** Wraps the rows a multi-row meta snapshot holds, so a revert can tell them from a value. */
  //
  // Deliberately not prefixed gmcp_: field_looks_secret() honours option_guard(), which
  // refuses that prefix, so a wrapper named after this plugin read as a credential and every
  // multi-row snapshot was discarded as one.
  const META_ROWS = '__meta_rows_by_id';

  /** A default no stored option can hold, so "absent" and "stored as false" stay distinct. */
  const ABSENT = '__gmcp_option_absent__';

  public static function clear(): void {
    delete_option( self::OPTION );
  }
}
