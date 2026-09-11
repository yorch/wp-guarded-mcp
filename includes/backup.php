<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Starting a backup, and being honest about what that does and does not mean.
*
* An agent about to delete a plugin or rewrite a page would sensibly want a backup first.
* Four things make that harder than it sounds, and each one shapes what is here.
*
* THERE IS NO COMMON INTERFACE. Sixteen backup plugins with no dominant one, and the
* largest work in unrelated ways: UpdraftPlus fires a WordPress action, Backuply writes a
* job record and leaves a cron hook to pick it up, BackWPup wants a secret URL the site
* owner must first switch on with a filter, and several keep programmatic export behind a
* paid tier. So this ships adapters for what can actually be driven from a REST request
* and verified, names the rest as detected-but-not-drivable, and offers
* gmcp_backup_providers for anything else. It does not pretend to be universal.
*
* THE ROUTE THE ADMIN SCREEN USES IS OFTEN NOT A ROUTE. Every adapter here was written
* against what is loaded on a token-authenticated REST request, not against what the
* plugin's own button calls. Backuply is the clearest case: its start handler lives in a
* file included only under wp_doing_ajax(), and that handler then calls the site back over
* HTTP forwarding the administrator's browser cookies. Neither half survives the trip, so
* the adapter goes through the door Backuply uses for its own unattended backups instead.
*
* A BACKUP IS NOT FINISHED WHEN THE CALL RETURNS. Backups take minutes to hours; a tool
* call lives inside one PHP request. So start() starts, and nothing here ever reports that
* a backup completed because of something it did. On a small site UpdraftPlus may well
* finish within the request, which is a trap: it would be easy to test on a site with
* three posts and conclude the operation is synchronous. It is not, and the wording
* everywhere assumes it is not.
*
* THE DANGEROUS FAILURE IS A FALSE YES. Every other guard in this plugin fails closed: a
* refusal costs an agent a sentence. This one would fail open, because a tool that reports
* a backup was taken when it was not makes an agent MORE willing to do the irreversible
* thing. So "cannot tell" is a first-class answer here, returned in full rather than
* flattened into a no, and the destructive tools report the backup situation rather than
* gating on it. A gate that silently passes when it cannot read a provider is worse than
* no gate, because it gets counted.
*
* Restore is deliberately absent, at any access level. Restoring discards everything since
* the backup, which is a larger irreversible act than anything else this plugin can do,
* and no confirmation token makes that safe to hand to something reading instructions out
* of a comment queue.
*/
class GMCP_Backup {

  /**
  * The adapters, newest-first in preference order.
  *
  * Each declares: whether it is installed, how to start a backup, how to read the state,
  * and how to list what exists. An adapter that cannot answer a question returns null
  * rather than a guess, and the difference between null and false is load-bearing
  * throughout this file.
  *
  * A list slot takes no arguments and returns every backup it knows about, newest first.
  * Capping is listing()'s job, so that "there may be older ones" is decided against how
  * many exist rather than how many an adapter chose to hand over.
  *
  * A list entry describes a backup without naming it on disk. See list_entry() for why
  * the filename is the one field deliberately missing, and sanitise_entry() for why an
  * adapter registered through this filter is not taken at its word about that.
  */
  public static function providers(): array {
    return apply_filters( 'gmcp_backup_providers', [

      'updraftplus' => [
        'name' => 'UpdraftPlus',
        'installed' => function () {
          // The action is registered by the main plugin file rather than its admin class,
          // so it is present on a REST request. Verified rather than assumed, because
          // this plugin has been caught before by wp-admin-only code paths.
          return class_exists( 'UpdraftPlus_Backup_History' ) && has_action( 'updraft_backupnow_backup_all' );
        },
        'start' => function ( array $args ) {
          // nocloud 0 means "also send it to whatever remote storage the site configured".
          // Leaving that to the site's own settings is the right default: a backup that
          // only exists on the same disk as the site is not much of a backup.
          do_action( 'updraft_backupnow_backup_all', [ 'nocloud' => 0 ] );
          return [ 'ok' => true, 'handle' => (string) get_site_option( 'updraft_last_backup_job_nonce', '' ) ];
        },
        'state' => function () {
          $history = UpdraftPlus_Backup_History::get_history();
          $times = array_map( 'intval', array_keys( (array) $history ) );
          $last = $times ? max( $times ) : null;

          $running = false;
          if ( class_exists( 'UpdraftPlus_Options' ) ) {
            $running = (bool) UpdraftPlus_Options::get_updraft_option( 'updraft_activejobs', [] );
          }

          $report = get_option( 'updraft_last_backup' );
          $errors = is_array( $report ) && !empty( $report['errors'] ) ? count( (array) $report['errors'] ) : 0;

          return [
            'last_completed' => $last,
            'running' => $running,
            'count' => count( (array) $history ),
            'last_succeeded' => is_array( $report ) ? (bool) ( $report['success'] ?? false ) : null,
            'last_errors' => $errors,
          ];
        },
        // Everything, newest first. The slicing happens in listing(), so that truncated
        // can be decided against how many exist rather than against how many came back.
        'list' => function () {
          // Keyed by the completion time, which is the identifier this returns. The
          // entries themselves hold the zip names, and those are exactly what must not
          // come back out: see list_entry().
          $history = (array) UpdraftPlus_Backup_History::get_history();
          krsort( $history, SORT_NUMERIC );

          $out = [];
          foreach ( $history as $when => $set ) {
            $set = (array) $set;

            // A component is present when its own key is, and its size lives in a
            // sibling "<component>-size". Summing those rather than stat-ing the
            // directory keeps this working for a backup that has been sent away and
            // deleted locally.
            $contains = [];
            $bytes = 0;
            foreach ( self::updraft_entities() as $key ) {
              if ( empty( $set[ $key ] ) ) {
                continue;
              }
              $contains[] = $key === 'db' ? 'database' : $key;
              $bytes += (int) ( $set[ $key . '-size' ] ?? 0 );
            }

            $entry = self::list_entry( (int) $when, $contains, $bytes, self::updraft_destination( $set ) );

            // UpdraftPlus prunes archives to its retention limit but keeps the history
            // entry, sizes and nonce behind. Counting one of those as a backup is the
            // false yes this whole file is arranged against: an agent asking whether it
            // has something to fall back on would be told yes by a record of a backup
            // that no longer exists anywhere.
            if ( !$contains ) {
              $entry['note'] = 'UpdraftPlus still lists this set but every archive in it is gone, almost certainly removed by its retention limit. There is nothing here to restore from.';
            }

            $out[] = $entry;
          }
          return $out;
        },
      ],

      // Backuply, free or Pro: the Pro plugin loads this same code base, so both answer
      // to the same constants and functions and neither needs its own entry.
      'backuply' => [
        'name' => 'Backuply',
        'installed' => function () {
          // Tested against what a REST request actually has, which is not the same as the
          // plugin being active. backuply_create_backup, the function behind the Create
          // Backup button, genuinely does not exist here: Backuply includes the file
          // holding it only when wp_doing_ajax(). backuply_backup_execute does, because
          // init.php defines it at the top level on every request.
          return defined( 'BACKUPLY_VERSION' )
            && function_exists( 'backuply_backup_execute' )
            && function_exists( 'backuply_get_backups_info' );
        },
        'start' => function ( array $args ) { return self::backuply_start(); },
        'state' => function () { return self::backuply_state(); },
        'list' => function () { return self::backuply_list(); },
      ],

      // Known, detectable, and not drivable from here. Named rather than ignored so the
      // answer is "this site backs up with X and I cannot start it" instead of the much
      // less useful "no backup plugin found".
      'backwpup' => [
        'name' => 'BackWPup',
        'installed' => function () { return class_exists( 'BackWPup' ); },
        'start' => null,
        'why_not' => 'BackWPup starts jobs from a secret URL that a site owner has to enable with the backwpup_allow_job_start_with_links filter, or from WP-CLI, which is not available inside a web request. Start it yourself, or register an adapter through gmcp_backup_providers.',
        'state' => null,
      ],
      'all-in-one-wp-migration' => [
        'name' => 'All-in-One WP Migration',
        'installed' => function () { return defined( 'AI1WM_PATH' ) || class_exists( 'Ai1wm_Export_Controller' ); },
        'start' => null,
        'why_not' => 'All-in-One WP Migration keeps scriptable export behind its paid extensions. Start it yourself, or register an adapter through gmcp_backup_providers.',
        'state' => null,
      ],
      'duplicator' => [
        'name' => 'Duplicator',
        'installed' => function () { return defined( 'DUPLICATOR_VERSION' ) || class_exists( 'DUP_Package' ); },
        'start' => null,
        'why_not' => 'Duplicator builds packages through its own admin flow, with the scriptable path in Duplicator Pro. Start it yourself, or register an adapter through gmcp_backup_providers.',
        'state' => null,
      ],
    ] );
  }

  /** The first installed provider, or null. */
  public static function detect(): ?array {
    foreach ( self::providers() as $slug => $provider ) {
      $installed = $provider['installed'] ?? null;
      if ( is_callable( $installed ) && $installed() ) {
        $provider['slug'] = $slug;
        return $provider;
      }
    }
    return null;
  }

  /**
  * What is known about backups on this site.
  *
  * can_start and can_tell are separate and both can be false. A site running BackWPup is
  * a site with real backups that this plugin can neither start nor read, and saying "no
  * backups" there would be a lie in the direction that gets somebody hurt.
  */
  public static function status(): array {
    $provider = self::detect();
    if ( !$provider ) {
      return [
        'provider' => null,
        'can_start' => false,
        'can_tell' => false,
        'summary' => 'No backup plugin this one recognises is active, so it cannot start a backup and cannot see whether any exist. That is not the same as there being none: a host-level or external backup would be invisible here.',
      ];
    }

    $out = [
      'provider' => $provider['name'],
      'can_start' => is_callable( $provider['start'] ?? null ),
      'can_tell' => is_callable( $provider['state'] ?? null ),
    ];

    if ( !$out['can_tell'] ) {
      $out['summary'] = $provider['name'] . ' is active. This plugin cannot read its state or start it. '
        . ( $provider['why_not'] ?? '' );
      return $out;
    }

    $state = call_user_func( $provider['state'] );
    $out = array_merge( $out, $state );
    $out['last_completed_gmt'] = $state['last_completed'] ? gmdate( 'Y-m-d H:i', $state['last_completed'] ) . ' GMT' : null;
    $out['age_hours'] = $state['last_completed'] ? (int) round( ( time() - $state['last_completed'] ) / HOUR_IN_SECONDS ) : null;
    $out['summary'] = self::describe( $provider['name'], $state );
    return $out;
  }

  /**
  * The backups this site has, newest first.
  *
  * Separate from status() because the two answer different questions and one of them is
  * expensive. status() is called on every destructive confirmation; this is called when
  * somebody asks what is actually there.
  *
  * can_list is its own answer, distinct from can_tell, because an adapter may know when a
  * backup last finished without being able to enumerate them. Where it cannot list, the
  * backups key is absent rather than empty: an empty array reads as "there are none",
  * which is the one thing this file may never say when it does not know.
  */
  public static function listing( int $limit = 20 ): array {
    $limit = max( 1, min( 100, $limit ) );

    $provider = self::detect();
    if ( !$provider ) {
      return [
        'provider' => null,
        'can_list' => false,
        'summary' => 'No backup plugin this one recognises is active, so it cannot list anything. That is not the same as there being no backups: a host-level or external backup would be invisible here.',
      ];
    }

    $out = [
      // Never $provider['name'] alone. providers() is a filter, and an adapter registered
      // without a name produced provider: null, which is the value that means "nothing
      // recognised" - two opposite situations reported identically, with a summary that
      // began on a bare space.
      'provider' => self::provider_name( $provider ),
      'can_list' => is_callable( $provider['list'] ?? null ),
    ];

    if ( !$out['can_list'] ) {
      $out['summary'] = trim( $out['provider'] . ' is active and this plugin cannot enumerate its backups, so it cannot say whether any exist. '
        . ( $provider['why_not'] ?? '' ) );
      return $out;
    }

    // The adapter returns everything it knows and the slicing happens here, so truncated
    // can be decided against how many exist. Deciding it from how many came back cannot
    // tell "there are exactly this many" from "the adapter stopped at your limit", and it
    // got both cases wrong: it claimed older backups might exist whenever a site held
    // exactly as many as were asked for, and it claimed none did whenever an adapter
    // registered through gmcp_backup_providers capped its own output.
    $all = (array) call_user_func( $provider['list'] );
    $total = count( $all );
    $backups = array_slice( array_values( $all ), 0, $limit );

    $entries = array_values( array_filter( array_map( [ self::class, 'sanitise_entry' ], $backups ) ) );

    // "shown", not "count". wp_backup_status already publishes a count meaning how many
    // backups exist, and the same key meaning a page size here had the two tools
    // disagreeing about the same site at the same moment.
    $out['total'] = $total;
    $out['shown'] = count( $entries );
    $out['limit'] = $limit;
    $out['truncated'] = $total > $limit;
    // Dropped entries are counted rather than quietly absent. count and total disagreeing
    // with no explanation is the sort of gap a reader fills in with an assumption.
    if ( count( $entries ) < count( $backups ) ) {
      $out['unreadable'] = count( $backups ) - count( $entries );
    }
    $out['backups'] = $entries;

    // A backup without a database cannot put the site back, and on UpdraftPlus a set can
    // be reduced to one stray component by retention while still being listed. Counting
    // those separately is the difference between "you have four backups" and the thing
    // the caller actually wants to know.
    $out['with_database'] = count( array_filter( $entries, function ( $b ) {
      return in_array( 'database', (array) ( $b['contains'] ?? [] ), true );
    } ) );

    $out['summary'] = self::describe_listing( $provider['name'], $out );
    return $out;
  }

  /**
  * Reduce one entry to the fields this tool promises, whoever produced it.
  *
  * The no-filename rule was a sentence in a summary and a sentence in a tool description,
  * which is a claim rather than a control. providers() is a public filter: a plugin can
  * register an adapter returning whatever it likes, and this passed it through untouched
  * and then appended a paragraph asserting the opposite. The audit log stores the reply,
  * so anything leaked that way is written to the database and readable afterwards.
  *
  * So the shape is imposed here instead of asked for. Unknown keys are dropped rather
  * than sanitised, because a key nobody designed has no safe rendering, and the two free
  * text fields are checked for anything shaped like a path or an archive: a destination
  * is meant to name a place, not to give directions to a file.
  */
  private static function sanitise_entry( $entry ): ?array {
    if ( !is_array( $entry ) ) {
      // Not a record at all. Dropping it loses a backup from the list, so the count above
      // is taken before this runs and will not match, which is the honest outcome: this
      // says "something is here that I will not show you", not "there is nothing here".
      return null;
    }

    $when = isset( $entry['completed'] ) ? (int) $entry['completed'] : 0;
    $contains = [];
    foreach ( (array) ( $entry['contains'] ?? [] ) as $part ) {
      if ( is_scalar( $part ) ) {
        $contains[] = self::scrub( (string) $part );
      }
    }

    $out = self::list_entry( $when, $contains, isset( $entry['size_bytes'] ) ? (int) $entry['size_bytes'] : 0, self::scrub( (string) ( $entry['destination'] ?? '' ) ) );

    if ( !empty( $entry['note'] ) && is_scalar( $entry['note'] ) ) {
      $out['note'] = self::scrub( (string) $entry['note'] );
    }
    return $out;
  }

  /**
  * Strip a free text field of anything that could be followed to a file.
  *
  * Deliberately blunt. A destination is a label like "local only" or the name the site
  * gave an S3 bucket, and none of those need a slash, a backslash or an archive
  * extension. Replacing rather than deleting means a reader can see that something was
  * removed instead of silently receiving a shortened name.
  */
  private static function scrub( string $value ): string {
    $value = trim( wp_strip_all_tags( $value ) );
    if ( $value === '' ) {
      return '';
    }
    // Four backslashes so the pattern reaches PCRE as [/\\]. Written with two, PHP hands
    // over [/\], where the backslash escapes the closing bracket, the class never ends,
    // and preg_match returns false on the bad pattern instead of matching. That failure
    // is silent and reads exactly like "nothing to scrub", which is why the suite asserts
    // that a planted path is withheld rather than only that a real one is absent.
    if ( preg_match( '~[/\\\\]|\.(?:zip|gz|tar|sql|bz2|7z|php)\b~i', $value ) ) {
      return '[withheld: looked like a path or an archive name]';
    }
    return mb_substr( $value, 0, 200 );
  }

  /** One sentence about the listing, including what it is not showing. */
  private static function describe_listing( string $name, array $out ): string {
    if ( $out['total'] === 0 ) {
      return sprintf( '%s is active and has no backups recorded. It can enumerate them, so this is a real zero rather than a gap in what can be seen.', $name );
    }

    $line = sprintf(
      '%s has %d backup%s, newest first, each identified by when it finished.',
      $name,
      $out['total'],
      $out['total'] === 1 ? '' : 's'
    );
    if ( $out['truncated'] ) {
      $line .= sprintf( ' The newest %d are shown; raise limit to see further back.', $out['shown'] );
    }
    if ( !empty( $out['unreadable'] ) ) {
      $n = (int) $out['unreadable'];
      $line .= sprintf(
        $n === 1 ? ' One more came back in a shape this tool will not pass on, and is not listed.' : ' %d more came back in a shape this tool will not pass on, and are not listed.',
        $n
      );
    }

    // The number that matters before anything irreversible. A set can be listed and hold
    // nothing restorable, which is worth one clause rather than leaving the reader to
    // notice an empty contains array.
    if ( $out['with_database'] === 0 ) {
      $line .= ' None of the ones shown contain a database, so none of them can put this site back.';
    }
    elseif ( $out['with_database'] < $out['shown'] ) {
      $line .= sprintf( ' %d of the %d shown contain a database; the rest cannot put the site back on their own.', $out['with_database'], $out['shown'] );
    }

    return $line . ' No archive filename or path is included in any entry, and entries from an adapter registered through gmcp_backup_providers are reduced to the same fields. Both plugins do guard their directory with an .htaccess, which Apache honours and nginx never reads, so on an nginx site the unguessable name is the last thing between a caller and a database archive holding every user row and password hash.';
  }

  /**
  * One backup, described without naming it on disk.
  *
  * The missing field is the point. UpdraftPlus writes
  * backup_<date>_<site>_<nonce>-db.gz into wp-content/updraft, where a 48-bit job nonce
  * makes the URL unguessable. Backuply inverts it, with a filename derivable from the
  * timestamp inside a directory whose 36-bit suffix is the secret, one suffix covering
  * every archive on the site.
  *
  * Not the only protection, which this comment used to claim. Both ship an .htaccess
  * saying "deny from all", and Backuply writes archives 0600; Apache honours the first and
  * nginx never reads it, so on nginx the name is the last line rather than the only one.
  * Either way the on-disk name is a capability, and a database archive holds every user
  * row and password hash on the site.
  *
  * So this returns nothing a caller could turn into a URL. A backup is identified by when
  * it finished, which is unguessable by nobody and sufficient for every question an agent
  * has a reason to ask: is there a recent one, what is in it, and where did it go.
  */
  private static function list_entry( int $when, array $contains, int $bytes, string $destination ): array {
    // A missing time stays missing. Running the date and the age arithmetic over a zero
    // produced "1970-01-01 00:00 GMT" and an age of half a million hours, sitting beside
    // a note saying the time was not recorded. A model reading the number rather than the
    // note concludes the backup is fifty years old, and nulls cannot be misread that way.
    $dated = $when > 0;
    return [
      'completed' => $dated ? $when : null,
      'completed_gmt' => $dated ? gmdate( 'Y-m-d H:i', $when ) . ' GMT' : null,
      'age_hours' => $dated ? (int) round( ( time() - $when ) / HOUR_IN_SECONDS ) : null,
      'contains' => $contains,
      'size_bytes' => $bytes,
      'size' => $bytes > 0 ? size_format( $bytes ) : null,
      'destination' => $destination,
    ];
  }

  /** An adapter's name, or something that at least is not the value meaning "none". */
  private static function provider_name( array $provider ): string {
    $name = trim( (string) ( $provider['name'] ?? '' ) );
    if ( $name !== '' ) {
      return $name;
    }
    $slug = trim( (string) ( $provider['slug'] ?? '' ) );
    return $slug !== '' ? $slug : 'an unnamed backup adapter';
  }

  /**
  * The components an UpdraftPlus backup can hold, asked of UpdraftPlus rather than listed
  * here.
  *
  * A hardcoded list gets this wrong twice. It omitted mu-plugins, which every real history
  * entry on a site carries, so the listing stated that must-use plugins were not in a
  * backup that contained them and left their bytes out of the size. And the set is open:
  * add-ons extend it through updraftplus_backupable_file_entities, and anything added that
  * way would be dropped the same silent way.
  *
  * The global is set by the plugin's main file outside any is_admin() guard, so it is
  * there on a REST request. The fallback is what the method returned when this was
  * written, used only if a future version moves it.
  */
  private static function updraft_entities(): array {
    global $updraftplus;
    if ( is_object( $updraftplus ) && method_exists( $updraftplus, 'get_backupable_file_entities' ) ) {
      $files = array_keys( (array) $updraftplus->get_backupable_file_entities( true ) );
    }
    else {
      $files = [ 'plugins', 'themes', 'uploads', 'mu-plugins', 'others' ];
    }
    // The database is not a file entity and is never in that list, so it is added here.
    return array_merge( [ 'db' ], array_values( array_filter( array_map( 'strval', $files ) ) ) );
  }

  /**
  * Where an UpdraftPlus backup went.
  *
  * An empty service list means it was only ever written next to the site, which is worth
  * saying rather than leaving blank: a backup on the same disk as the site does not
  * survive the failure people take backups for.
  */
  private static function updraft_destination( array $set ): string {
    $services = array_filter( array_map( 'strval', (array) ( $set['service'] ?? [] ) ) );
    $services = array_filter( $services, function ( $s ) { return $s !== '' && $s !== 'none'; } );
    return $services ? implode( ', ', array_unique( $services ) ) : 'local only';
  }

  /** One sentence a person or a model can act on, with no cheerful rounding. */
  private static function describe( string $name, array $state ): string {
    $parts = [];
    if ( $state['last_completed'] ) {
      $age = human_time_diff( $state['last_completed'], time() );
      $parts[] = sprintf( 'The most recent %s backup finished %s ago.', $name, $age );
      if ( $state['last_succeeded'] === false || $state['last_errors'] > 0 ) {
        $parts[] = sprintf( 'It reported %d error(s), so do not rely on it without checking.', (int) $state['last_errors'] );
      }
    }
    else {
      $parts[] = sprintf( '%s is active but has no completed backups recorded.', $name );
    }
    $parts[] = $state['running']
      ? 'A backup is running now; it will not be finished until after this request.'
      : 'No backup is running.';
    return implode( ' ', $parts );
  }

  /**
  * Start one. Never reports completion, because it cannot know.
  *
  * @return array{ok:bool,message:string}
  */
  public static function start(): array {
    $provider = self::detect();
    if ( !$provider ) {
      return [ 'ok' => false, 'message' => 'No backup plugin this one can drive is active. Install UpdraftPlus or Backuply, or register an adapter through the gmcp_backup_providers filter.' ];
    }
    if ( !is_callable( $provider['start'] ?? null ) ) {
      return [ 'ok' => false, 'message' => $provider['name'] . ' is active but cannot be started from here. ' . ( $provider['why_not'] ?? '' ) ];
    }

    $before = is_callable( $provider['state'] ?? null ) ? call_user_func( $provider['state'] ) : null;
    $result = call_user_func( $provider['start'], [] );
    if ( empty( $result['ok'] ) ) {
      // An adapter that knows why gets to say so. "Refused" on its own tells an agent
      // nothing it can act on, and the two real reasons want opposite responses: wait,
      // for a backup already running, and stop, for a provider that cannot queue one.
      $why = trim( (string) ( $result['message'] ?? '' ) );
      return [ 'ok' => false, 'message' => $why !== '' ? $why : $provider['name'] . ' refused to start a backup.' ];
    }

    $message = sprintf(
      'Asked %s to start a full backup. It runs in the background and is almost certainly not finished yet, so do not treat this as a backup having been taken. Call wp_backup_status to see when one completes.',
      $provider['name']
    );
    if ( $before && $before['last_completed'] ) {
      $message .= sprintf( ' The most recent completed backup before this was %s ago.', human_time_diff( $before['last_completed'], time() ) );
    }
    return [ 'ok' => true, 'message' => $message ];
  }

  /**
  * The line the two-step confirmation adds to its summary before a destructive operation.
  *
  * Reports, never gates. A gate here would have to fail open whenever it cannot read a
  * provider, and a control that silently passes is worse than an absent one because it
  * gets counted. What this does instead is put the real situation in front of whoever is
  * about to approve, which is the decision they are actually being asked to make.
  */
  public static function confirmation_line(): string {
    $status = self::status();
    if ( !$status['can_tell'] ) {
      return ' ' . $status['summary'];
    }
    if ( empty( $status['last_completed'] ) ) {
      return sprintf( ' %s is active but has never completed a backup, so there is nothing to fall back on.', $status['provider'] );
    }
    $line = sprintf( ' The most recent %s backup finished %s ago.', $status['provider'], human_time_diff( $status['last_completed'], time() ) );
    if ( !empty( $status['running'] ) ) {
      $line .= ' Another is running now and is not finished.';
    }
    return $line;
  }

  #region Backuply

  /**
  * Queue a Backuply backup, in a request that is not this one.
  *
  * Backuply's Create Backup button posts to an admin-ajax action, and that handler then
  * calls the site back over HTTP carrying the administrator's browser cookies, landing on
  * a second handler that checks current_user_can( 'activate_plugins' ). A REST request
  * authenticated with a bearer token has no cookies to forward, so the loopback arrives
  * logged out and is refused. This is not a matter of finding the right nonce.
  *
  * What is registered on every request is backuply_backup_cron, wired to
  * backuply_backup_execute. That is the same hook Backuply's own scheduled backups run
  * on, which makes it the supported way in rather than a way around: the job record below
  * is the one the button writes.
  *
  * It has to be a different request. Everything under backuply_backup_execute ends in
  * die(), on the failure paths as much as the success ones, so calling it here would take
  * this tool's own reply with it and the caller would see a dropped connection.
  */
  private static function backuply_start(): array {
    if ( self::backuply_running() ) {
      return [
        'ok' => false,
        'message' => 'Backuply already has a backup in flight. Backuply keeps one job record, so starting a second would overwrite the first and leave neither finishable. Call wp_backup_status until it reports nothing running.',
      ];
    }

    // Both halves, whatever the site's saved defaults say. wp_start_backup offers a full
    // backup, and a site whose Backuply screen is left on database-only would otherwise
    // hand back a partial one under that name, which is exactly the false yes this file
    // is arranged against. Where it goes is a different question, and one the site owner
    // has already answered in Backuply: an empty location is Backuply's own local folder.
    $settings = (array) get_option( 'backuply_settings', [] );
    $job = [
      'backup_dir' => '1',
      'backup_db' => '1',
      'backup_location' => (string) ( $settings['backup_location'] ?? '' ),
    ];

    backuply_create_log_file();
    update_option( 'backuply_backup_stopped', false, false );
    update_option( 'backuply_status', $job );

    if ( !wp_schedule_single_event( time(), 'backuply_backup_cron' ) ) {
      // Leaving the record behind would show a job on Backuply's own screen that nothing
      // is going to run, and would read as "running" here until it went stale.
      delete_option( 'backuply_status' );
      return [
        'ok' => false,
        'message' => 'Backuply was ready but WordPress would not queue the event that runs the job, so nothing was started. A backup may already be queued, or something is filtering the cron schedule.',
      ];
    }

    // Kick the runner rather than waiting for the next visitor to the site. This is a
    // non-blocking loopback, so it returns whether or not anything answers, and it is
    // sent even on a site with DISABLE_WP_CRON, where the switch that skips cron sits in
    // the request handler rather than in here.
    spawn_cron();

    return [ 'ok' => true ];
  }

  /** What Backuply knows, in the shape status() merges. */
  private static function backuply_state(): array {
    // Written only when a backup finishes successfully, which is what makes it usable as
    // last_completed: a failed run leaves the previous value alone rather than moving it.
    $last = (int) get_option( 'backuply_last_backup', 0 );
    $log = self::backuply_last_log();

    return [
      'last_completed' => $last ?: null,
      'running' => self::backuply_running(),
      'count' => count( (array) backuply_get_backups_info() ),
      'last_succeeded' => $log['succeeded'],
      'last_errors' => $log['errors'],
    ];
  }

  /**
  * Backuply's backups, newest first.
  *
  * backuply_get_backups_info() already sorts by the timestamp in the info filename and
  * drops records whose archive has gone, so this is a filter and a reshape rather than a
  * scan. It returns objects, not arrays, which is the one thing to be careful of.
  *
  * btime is the completion time and doubles as the identifier. The name field beside it
  * is not returned: it is the archive's own filename, and Backuply keeps archives in a
  * directory whose random suffix is all that protects them.
  */
  private static function backuply_list(): array {
    $out = [];
    foreach ( (array) backuply_get_backups_info() as $info ) {
      // Backuply pushes json_decode()'s return without checking it, so an info file that
      // was truncated mid-write while its archive survived puts a null in this list. An
      // object type hint further down turned that into an uncaught TypeError, and one bad
      // file took out the listing for every other backup on the site while handing back
      // the plugin's absolute path in the error. The archive is real, so it is reported
      // as a backup nothing can be read about rather than skipped.
      if ( !is_object( $info ) ) {
        $out[] = self::list_entry( 0, [], 0, '' )
          + [ 'note' => 'Backuply has an archive here whose record is unreadable, so nothing about it can be reported. Its own screen is the place to look.' ];
        continue;
      }

      $when = (int) ( $info->btime ?? 0 );
      $entry = self::list_entry( $when, self::backuply_contains( $info ), (int) ( $info->size ?? 0 ), self::backuply_destination( $info ) );
      if ( $when <= 0 ) {
        // Undated. Skipping it would undercount, and list_entry leaves the time fields
        // null rather than rendering a zero as 1970.
        $entry['note'] = 'Backuply recorded no completion time for this one, so it cannot be placed in the order above.';
      }
      $out[] = $entry;
    }
    return $out;
  }

  /** Which halves of the site a Backuply archive holds, from the flags it was made with. */
  private static function backuply_contains( object $info ): array {
    $contains = [];
    if ( !empty( $info->backup_db ) ) {
      $contains[] = 'database';
    }
    if ( !empty( $info->backup_dir ) ) {
      $contains[] = 'files';
    }
    return $contains;
  }

  /**
  * Where a Backuply archive went.
  *
  * backup_location is an id into the site's own list of configured remote locations, and
  * an absent or empty one means Backuply's local folder. The id is resolved to the name
  * the site gave it, because "3" tells a reader nothing.
  */
  private static function backuply_destination( object $info ): string {
    $id = $info->backup_location ?? '';
    if ( $id === '' || $id === null ) {
      return 'local only';
    }
    $locations = (array) get_option( 'backuply_remote_backup_locs', [] );
    $name = $locations[ $id ]['name'] ?? '';
    return $name !== '' ? (string) $name : 'a remote location Backuply no longer has configured';
  }

  /**
  * Whether a Backuply backup is in flight.
  *
  * Not backuply_active(), which is the call this looks like it should make and which
  * answers false while a backup is running: it tests $status['last_time'], and no line in
  * Backuply ever writes that key. The key the job does refresh, at the start of every
  * chunk, is last_update.
  *
  * A record with no last_update at all is one this plugin has just written and whose first
  * chunk has not run yet. That is in flight too, and falling back to whether the event is
  * still queued keeps a job that never starts from reading as running for good.
  */
  private static function backuply_running(): bool {
    $status = get_option( 'backuply_status' );
    if ( !is_array( $status ) || !$status ) {
      return false;
    }
    $updated = (int) ( $status['last_update'] ?? 0 );
    if ( $updated === 0 ) {
      return (bool) wp_next_scheduled( 'backuply_backup_cron' );
    }
    // The same window Backuply uses to declare a job dead, so a crashed backup stops
    // being reported as running at the moment Backuply stops believing in it too.
    $window = defined( 'BACKUPLY_TIMEOUT_TIME' ) ? (int) BACKUPLY_TIMEOUT_TIME : 300;
    return ( time() - $updated ) < $window;
  }

  /**
  * How the last Backuply run ended, read out of the log it copies aside when a job stops.
  *
  * There is no option recording this. backuply_last_backup is written on success only, so
  * on its own it cannot tell a backup that failed an hour ago from no attempt at all,
  * and that difference is the whole point of asking. Each log line is
  * "message|status|percent", and a run ends on a line whose status is success or error.
  *
  * Nulls rather than guesses when the log cannot be read whole: an error count taken from
  * part of a file is a smaller number than the truth, in the direction that reassures.
  */
  private static function backuply_last_log(): array {
    $unknown = [ 'succeeded' => null, 'errors' => null ];
    if ( !defined( 'BACKUPLY_BACKUP_DIR' ) ) {
      return $unknown;
    }

    $file = BACKUPLY_BACKUP_DIR . 'backuply_backup_log.php';
    $size = is_readable( $file ) ? (int) @filesize( $file ) : 0;
    if ( $size <= 0 || $size > MB_IN_BYTES ) {
      return $unknown;
    }

    $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( !is_array( $lines ) ) {
      return $unknown;
    }

    $errors = 0;
    $succeeded = null;
    foreach ( $lines as $line ) {
      $status = (string) ( explode( '|', $line )[1] ?? '' );
      if ( $status === 'error' ) {
        $errors++;
        $succeeded = false;
      }
      elseif ( $status === 'success' ) {
        $succeeded = true;
      }
    }
    return [ 'succeeded' => $succeeded, 'errors' => $errors ];
  }

  #endregion
}
