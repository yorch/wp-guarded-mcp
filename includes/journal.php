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

  public function __construct() {
    add_action( 'gmcp_change', [ $this, 'observe' ], 10, 1 );
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
  * activation hooks, which is how you get a half-installed plugin; the plugin tools
  * exist for that. rewrite_rules is a derived cache measured in tens of kilobytes.
  */
  private function skip_option( string $key ): bool {
    if ( strpos( $key, '_transient' ) === 0 || strpos( $key, '_site_transient' ) === 0 ) {
      return true;
    }
    if ( strpos( $key, 'gmcp_' ) === 0 || strpos( $key, '_wp_' ) === 0 ) {
      return true;
    }
    $never = [ 'cron', 'rewrite_rules', 'active_plugins', 'recently_activated', 'auto_updater.lock',
      'db_upgraded', 'can_compress_scripts', 'user_count', 'admin_email_lifespan' ];
    if ( in_array( $key, $never, true ) ) {
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
    ];
    if ( GMCP_Core::holds_credential( $old ) ) {
      $entry['previous'] = null;
      $entry['redacted'] = true;
    }
    else {
      $entry['previous'] = $this->storable( $old );
    }
    $this->record( $entry );
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
    ];
    foreach ( $change['fields'] as $field => $pair ) {
      $entry['previous'][ $field ] = $this->storable( $pair['from'] ?? null );
    }
    if ( $this->oversized( $entry['previous'] ) ) {
      $entry['note'] = 'Part of the previous version was too large to keep, so reverting will restore only what fits.';
    }
    $this->record( $entry );
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
    $entry['id'] = bin2hex( random_bytes( 5 ) );

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
        'what' => $entry['what'] ?? '',
        'reversible' => self::reversible( $entry ) === true,
      ];
      $why = self::reversible( $entry );
      if ( $why !== true ) {
        $row['not_reversible_because'] = $why;
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
      if ( $entry['kind'] === 'option' ) {
        if ( !empty( $entry['absent'] ) ) {
          delete_option( $entry['key'] );
          $done = "Option \"{$entry['key']}\" removed, which is what it was before.";
        }
        else {
          update_option( $entry['key'], $entry['previous'] );
          $done = "Option \"{$entry['key']}\" restored to its previous value.";
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
