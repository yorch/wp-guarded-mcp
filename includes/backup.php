<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Starting a backup, and being honest about what that does and does not mean.
*
* An agent about to delete a plugin or rewrite a page would sensibly want a backup first.
* Three things make that harder than it sounds, and each one shapes what is here.
*
* THERE IS NO COMMON INTERFACE. Sixteen backup plugins with no dominant one, and the
* largest work in unrelated ways: UpdraftPlus fires a WordPress action, BackWPup wants a
* secret URL the site owner must first switch on with a filter, and several keep
* programmatic export behind a paid tier. So this ships adapters for what can actually be
* driven from a REST request and verified, names the rest as detected-but-not-drivable,
* and offers gmcp_backup_providers for anything else. It does not pretend to be universal.
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
  * Each declares: whether it is installed, how to start a backup, and how to read the
  * state. An adapter that cannot answer a question returns null rather than a guess, and
  * the difference between null and false is load-bearing throughout this file.
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
      return [ 'ok' => false, 'message' => 'No backup plugin this one can drive is active. Install UpdraftPlus, or register an adapter through the gmcp_backup_providers filter.' ];
    }
    if ( !is_callable( $provider['start'] ?? null ) ) {
      return [ 'ok' => false, 'message' => $provider['name'] . ' is active but cannot be started from here. ' . ( $provider['why_not'] ?? '' ) ];
    }

    $before = is_callable( $provider['state'] ?? null ) ? call_user_func( $provider['state'] ) : null;
    $result = call_user_func( $provider['start'], [] );
    if ( empty( $result['ok'] ) ) {
      return [ 'ok' => false, 'message' => $provider['name'] . ' refused to start a backup.' ];
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
}
