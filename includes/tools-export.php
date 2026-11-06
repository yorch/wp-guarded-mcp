<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Site files, read as a manifest and — only after a person says so — as an archive.
*
* WHY THIS IS ITS OWN GROUP AND OFF BY DEFAULT. A directory listing is reconnaissance: it says
* which plugins are installed, which themes are present, and what is sitting in uploads. An
* archive is worse: it leaves the server with the whole of `plugins`, `themes` or `uploads`,
* which on a real site can include customer exports, licence files and the source of security
* plugins. The owner of this site chose to allow the whole of `uploads`, not a subtree, so the
* switch that allows it has to be their decision and not an update's.
*
* WHAT A MANIFEST IS. It walks the tree and reports the file count, the total bytes, the depth
* and the entries that WOULD be excluded, with the reason for each. Each file is read once, in
* one streaming pass, only to hash its contents into the digest: a name, a size and an mtime all
* survive an equal-length rewrite inside the same second, so a digest built from those three
* alone would let a tree be changed after a person read it and still match the approval. It
* returns no file bytes, not even a preview. Over the caps it refuses and says how far the walk
* got rather than silently truncating: a manifest that stopped at 20,000 entries and returned a
* clean-looking count would be a lie about what the archive would contain.
*
* WHAT A PULL IS. Two separate gates, and neither is the other's substitute:
*
*   1. The call always waits in the approvals queue, in every `mcp_approval_mode` including
*      `never`. The tool registration declares `requiresApproval` for `mode=pull`, so this is
*      a property of the tool rather than a site setting somebody can leave open.
*   2. The approval is keyed to the resolved root, the relative path and a digest of the
*      manifest, because all three are arguments of the call. The caller has to run a manifest
*      first and pass its digest, and the pull recomputes the digest and refuses if the tree
*      changed between the two. An agent cannot be approved for one set of files and then
*      archive a different one. The digest covers each file's contents as well as its name,
*      size and mtime, so "changed" means the bytes and not only the listing.
*
* THE ARCHIVE NEVER APPEARS IN A REPLY. An approved pull builds a temporary file OUTSIDE the
* web root, with 0600 permissions, and returns a one-time URL. The bytes are streamed by the
* REST endpoint in server.php. A reply is copied into this plugin's chained audit log for its
* retention window, and no field-name redaction can find a secret inside a zip file. The same
* reason is why the token is scrubbed from that log by {@see GMCP_Diag_Util::scrub_preview_tokens()}.
*
* WHAT IS WITHHELD. A directory is not a classification of its contents. Names that hold secrets
* (dot-files, `wp-config*`, keys, database dumps, logs, backup archives), the private directories
* of form and shop plugins inside uploads, and every uploaded file that hangs from a form
* submission are excluded with a reason, not read, and absent from the archive: the whole point
* of the submission policy elsewhere in this plugin is that a visitor's upload leaves the server
* only through the form plugin's own screens, and `wp_download_request` already refuses such a
* file at every level, so an archive of uploads must not be the other door. Names that are not
* safe inside an archive (not UTF-8, a backslash, a control character) are excluded too.
* {@see self::name_refusal()} and {@see self::personal_upload_paths()} say exactly what, and
* what they cannot know (a file no attachment row points at, in a directory this tool does not
* name).
*
* CONTAINMENT. `root` is one of four names, never a path the caller supplies. A `path` is
* relative, has no empty, `.` or `..` segment, cannot be absolute, and cannot use a PHP stream
* wrapper. Every component of it is checked with lstat and a symlink is refused rather than
* followed; the final realpath has to sit inside the realpath of the named root, compared on a
* directory boundary so `/uploads-evil` does not pass for `/uploads`. Inside the tree, a
* symlink, a hard link (nlink > 1) and anything that is not a regular file or directory is
* excluded and listed with its reason rather than followed, included or silently dropped.
* Files are added to the archive with ZipArchive::addFile(), which reads from disk as it writes;
* nothing calls file_get_contents() on a tree entry and no entry is ever held in memory whole.
* Each entry is re-checked (lstat, realpath, device, inode, size, content hash) immediately
* before it is added, and the closed archive is checked against the manifest's hashes
* afterwards, because ZipArchive opens the path itself and later than any check made here.
* {@see self::entry_refusal()} says plainly which part of that window stays open.
*
* WHAT THIS DELIBERATELY DOES NOT DO. There is no restore or import counterpart, and no tool
* that accepts an archive. Every write here creates a temporary file that expires. The audit
* log is not exempt: the tool's arguments are logged redacted like any other call, and the
* token in its reply is scrubbed before the row is written.
*/
class GMCP_Tools_Export {

  /** A walk this large is refused, not truncated. Both filters are narrowings by default. */
  const MAX_ENTRIES = 20000;
  const MAX_BYTES = 536870912; // 512 MiB.

  /** How long a minted archive URL lives, and how much of a long exclusion list a reply prints. */
  const TOKEN_TTL = 5 * MINUTE_IN_SECONDS;
  const EXCLUDED_LIST_MAX = 200;

  /** The option row that holds one unspent token. Its name is credential-shaped on purpose. */
  const TOKEN_OPTION_PREFIX = 'gmcp_export_token_';
  /** Temporary files all start with this, so a sweep can find one left by an old row. */
  const TEMP_PREFIX = 'gmcp-export-';
  const CRON_HOOK = 'gmcp_export_cleanup';

  const ROOTS = [ 'plugins', 'themes', 'mu-plugins', 'uploads' ];

  /**
  * File names that are never exported, in any of the four roots and at any depth.
  *
  * A directory is not a classification of its contents: uploads and plugins both collect files
  * whose whole purpose is to stay on the server. Dot-files (`.env`, `.htpasswd`, `.git`),
  * `wp-config*`, private keys, certificates and keystores in any case, database dumps and SQLite
  * files with their `-wal`/`-shm`/`.gz` companions, logs and their rotations, `*.env`, editor and
  * backup leftovers. Matched as a glob, `*` only, on the NAME AS A FILESYSTEM AND AN OPERATING
  * SYSTEM READ IT, not as typed ({@see self::match_name()}): `dump.sql ` with a trailing space,
  * `server.key.` with a trailing dot and `db.sql` followed by a no-break space are the files
  * their short names say they are, and a rule on the typed spelling let each of them through. A match is listed in
  * the manifest with the reason `private_name` and its bytes are never read, so it is not in the
  * digest by content either. A site can ADD patterns with `gmcp_export_private_names`; the filter
  * cannot remove a built-in one, because it is a list of what is withheld and not of what is
  * allowed.
  */
  const PRIVATE_NAME_GLOBS = [
    '.*', 'wp-config*', 'error_log*', 'php_errorlog*', 'access_log*', '*_log', '*_log.*', '*_log-*',
    '*.log', '*.log.*', '*.log-*',
    '*.sql', '*.sql.*', '*.dump', '*.dump.*', '*.dmp', '*.mdb', '*.sqlite', '*.sqlite.*', '*.sqlite-*',
    '*.sqlite3', '*.sqlite3.*', '*.sqlite3-*', '*.db', '*.db.*', '*.db-*', '*.db3', '*.s3db', '*.sdb',
    '*-wal', '*-shm', '*-journal',
    '*.env', '*.env.*', '*.envrc', 'htpasswd', '*.htpasswd',
    '*.bak', '*.old', '*.orig', '*.save', '*.swp', '*~',
    '*.pem', '*.pem.*', '*.key', '*.key.*', '*.crt', '*.cer', '*.der', '*.csr', '*.p7b', '*.p8',
    '*.p12', '*.pfx', '*.pkcs12', '*.jks', '*.jceks', '*.bks', '*.keystore', '*.truststore',
    '*.kdbx', '*.ppk', '*.gpg', '*.pgp', '*.asc',
    'id_rsa*', 'id_dsa*', 'id_ecdsa*', 'id_ed25519*',
    'auth.json', 'credentials*.json', 'secrets*.json', 'service-account*.json', 'client_secret*.json',
    '*.wpress',
  ];

  /**
  * Directories of the uploads root that hold another plugin's private files, matched on the
  * lower-cased name at any depth: a form plugin's uploaded files and temporary files, a shop's
  * paid downloads and logs, backup plugins' archives. Uploads only: in `plugins` and `themes` a
  * directory called `logs` or `backup` is usually source code. UNVERIFIED: the names are written
  * from memory of each plugin's storage layout, not checked against their source; a site with
  * another store adds its directory through `gmcp_export_private_directories`. The files of a
  * form submission that WordPress knows as attachments are found by the database instead
  * ({@see self::personal_upload_paths()}), which does not depend on this list.
  */
  const PRIVATE_UPLOAD_DIRS = [
    'wpforms', 'gravity_forms', 'ninja-forms', 'fluentform', 'wpcf7_uploads', 'flamingo',
    'formidable', 'forminator', 'wc-logs', 'woocommerce_uploads', 'wpallimport', 'sucuri',
    'updraft', 'backuply', 'ai1wm-backups', 'backwpup', 'wpvividbackups', 'backup', 'backups',
    'logs', 'private',
  ];
  /** Directory-name prefixes with the same meaning (backup plugins add a random suffix). */
  const PRIVATE_UPLOAD_DIR_PREFIXES = [ 'backwpup', 'updraft', 'wpvivid', 'duplicator', 'backup-', 'backups-' ];

  public function __construct() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  /**
  * Register the expiry sweep even when the group is switched off.
  *
  * The handler is registered from GMCP_Core::init() rather than from the constructor, so a
  * token that was minted before somebody turned the group off still has its archive removed
  * when the scheduled event fires. Turning a capability off should not leave files behind.
  */
  public static function boot(): void {
    add_action( self::CRON_HOOK, [ __CLASS__, 'cleanup_expired' ] );
  }

  /** Whether the group's switch is on. The REST endpoint asks this too. */
  public static function enabled(): bool {
    $core = $GLOBALS['gmcp_core'] ?? null;
    return $core && (bool) $core->get_option( 'mcp_tools_export' );
  }

  public function register_tools( $tools ) {
    return array_merge( is_array( $tools ) ? $tools : [], array_values( $this->tools() ) );
  }

  private function tools(): array {
    return [
      'wp_export_request' => [
        'name' => 'wp_export_request',
        'description' => 'Inspect or export a directory of this site as a manifest or a zip archive. root is one of plugins, themes, mu-plugins or uploads; path is an optional RELATIVE directory or file under it, and defaults to the whole root (uploads means all of uploads). mode=manifest (the default) walks the tree and reports file_count, total_bytes, depth, a manifest_digest and the entries that WOULD be excluded with a reason (symbolic links, hard links, non-regular files, unreadable entries, anything that resolves outside the root, names that are not safe in an archive, files that are private by name — dot-files, wp-config, keys, database dumps, logs, backups, the private directories of form and shop plugins in uploads — and uploaded files that belong to a form submission). Withheld files are never read and never in an archive. The digest covers every file\'s name, size and contents, so a file rewritten in place after a manifest changes it. It never returns file contents, and it refuses rather than truncating when the tree exceeds 20000 entries or 512 MiB. mode=pull acts on the manifest_digest from a previous manifest call: it recomputes the manifest and refuses if the tree changed, then asks a signed-in administrator to approve THIS exact call on the Approvals screen. Until that person approves it, nothing is archived and no token exists. After approval, repeat the identical call and it builds the archive outside the web root and returns a one-time URL, valid for five minutes, that streams the zip. The archive is never returned in a tool reply, because replies are copied into the audit log. The pull leg always needs human approval, whatever the site\'s approval mode is set to. Admin level.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'root' => [
              'type' => 'string',
              'enum' => self::ROOTS,
              'description' => 'The named root to read: plugins, themes, mu-plugins or uploads.',
            ],
            'path' => [
              'type' => 'string',
              'description' => 'Optional relative path under the root. Defaults to the whole root. Absolute paths, "..", stream wrappers and symbolic links are refused.',
            ],
            'mode' => [
              'type' => 'string',
              'enum' => [ 'manifest', 'pull' ],
              'default' => 'manifest',
              'description' => 'manifest reports the tree without reading it. pull archives it, after human approval, and returns a one-time URL.',
            ],
            'manifest_digest' => [
              'type' => 'string',
              'description' => 'Required for mode=pull: the manifest_digest returned for exactly this root and path. A changed tree is refused rather than archived under an older approval.',
            ],
          ],
          'required' => [ 'root' ],
        ],
        'accessLevel' => 'admin',
        // Read by GMCP_Approvals::requires_human_approval(). The pull leg waits in every
        // approval mode, including 'never'; the manifest leg does not wait at all.
        'requiresApproval' => [ 'argument' => 'mode', 'values' => [ 'pull' ] ],
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || $tool !== 'wp_export_request' ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];
    $mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'manifest';
    if ( !in_array( $mode, [ 'manifest', 'pull' ], true ) ) {
      return $this->error( $r, 'Unknown mode "' . $mode . '". Use manifest or pull.' );
    }

    // A token left behind by an earlier run is cheap to remove here and is removed even when
    // this call is going to fail. The REST endpoint and the cron event sweep as well; this is
    // the path that runs most often.
    self::cleanup_expired();

    $root_key = isset( $args['root'] ) ? (string) $args['root'] : '';
    $relative = isset( $args['path'] ) ? (string) $args['path'] : '';
    $resolved = $this->resolve( $root_key, $relative );
    if ( isset( $resolved['error'] ) ) {
      return $this->error( $r, $resolved['error'] );
    }

    $scan = $this->scan( $root_key, $resolved );
    if ( isset( $scan['refused'] ) ) {
      return $this->error( $r, $scan['message'] );
    }

    if ( $mode === 'manifest' ) {
      // The full entry lists exist only so the pull can build the archive from the same walk
      // that produced the digest. A manifest returns counts, the digest and the exclusions;
      // listing every included path would be a larger reconnaissance surface and a larger
      // audit row than the answer needs.
      unset( $scan['files'], $scan['directories'] );
      return $this->json( $r, $scan );
    }

    return $this->pull( $args, $scan, $resolved, $r );
  }

  #region Manifest and archive

  /**
  * Build the archive for one approved pull and mint its one-time token.
  *
  * The manifest digest is recomputed here and compared with the caller's copy. That is what
  * makes the approval bind to the files rather than to a directory name: an approval is for
  * one manifest, and a tree that changed after the person read it gets a new request.
  */
  private function pull( array $args, array $scan, array $resolved, array $r ): array {
    $digest = isset( $args['manifest_digest'] ) ? strtolower( trim( (string) $args['manifest_digest'] ) ) : '';
    if ( $digest === '' ) {
      return $this->error( $r, 'mode=pull needs manifest_digest: run mode=manifest for this root and path first, then pass the manifest_digest it returned.' );
    }
    if ( !hash_equals( (string) $scan['manifest_digest'], $digest ) ) {
      return $this->error( $r, 'The tree has changed since that manifest, so this pull was not built. Run mode=manifest again, read the exclusions, and pass the new manifest_digest. The approval has to be for the files that are actually there.' );
    }
    if ( !$scan['files'] && !$scan['directories'] ) {
      // A zip with no entries is not a file: ZipArchive writes nothing for it, and the check on
      // the finished archive then failed with a message about the tree having changed. Said
      // plainly instead, and before anything is created. This is also what a tree whose every
      // entry is withheld looks like.
      return $this->error( $r, 'There is nothing to archive: this path holds no file that may be exported (see excluded in the manifest for what was withheld and why), so no archive was built.' );
    }
    if ( !class_exists( 'ZipArchive' ) ) {
      return $this->error( $r, 'The zip extension is not available on this server, so an archive cannot be built. The manifest is still available.' );
    }
    $temp_dir = self::temp_dir();
    if ( $temp_dir === null ) {
      return $this->error( $r, 'No writable temporary directory outside the web root was found, so an archive was not built. The manifest is still available.' );
    }

    $tmp = tempnam( $temp_dir, self::TEMP_PREFIX );
    if ( $tmp === false ) {
      return $this->error( $r, 'A temporary file could not be created outside the web root, so an archive was not built. The manifest is still available.' );
    }
    @chmod( $tmp, 0600 );
    // Until the token row exists the file has no owner, and a request that dies here (the PHP
    // time limit on a large tree, an out-of-memory fatal, a client that went away) ran none of
    // the unlinks below: the file stayed in the temporary directory, up to 512 MiB, until some
    // later export call happened to sweep it, and the sweep is only scheduled once an archive
    // has been issued. A shutdown callback runs on every one of those ends.
    $keep = false;
    register_shutdown_function( static function () use ( $tmp, &$keep ) {
      if ( !$keep ) {
        GMCP_Tools_Export::unlink_archive( $tmp );
      }
    } );

    $zip = new ZipArchive();
    $opened = $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    if ( $opened !== true ) {
      @unlink( $tmp );
      return $this->error( $r, 'The archive could not be opened for writing, so it was not built.' );
    }

    // Declared before the try, not inside it: both are read by the catch, and a failure on the
    // first entry (or on close() with no files at all) would otherwise reach it with neither set.
    $changed = '';
    $closed = false;
    try {
      foreach ( $scan['directories'] as $rel ) {
        $zip->addEmptyDir( $rel );
      }
      foreach ( $scan['files'] as $file ) {
        // Asked per entry, immediately before adding it, rather than once at the start: the
        // walk that produced this list happened before the approval was claimed, and
        // ZipArchive opens the path itself, after every check this tool made.
        // {@see self::entry_refusal()}.
        $changed = self::entry_refusal( $file, $resolved['base'] );
        if ( $changed !== '' ) {
          throw new \RuntimeException( 'changed:' . $changed );
        }
        if ( !$zip->addFile( $file['path'], $file['rel'] ) ) {
          throw new \RuntimeException( 'addFile' );
        }
      }
      if ( !$zip->close() ) {
        throw new \RuntimeException( 'close' );
      }
      $closed = true;
      // After close(), because addFile() only records the path and reads it when the archive
      // is written: this is the check on the bytes that actually reached the archive.
      $changed = self::archive_refusal( $tmp, $scan['files'] );
      if ( $changed !== '' ) {
        throw new \RuntimeException( 'changed:' . $changed );
      }
    }
    catch ( \Throwable $e ) {
      if ( !$closed ) {
        $zip->close();
      }
      @unlink( $tmp );
      // Written, because the reply below says the log has it. The message can carry a path and
      // is for the operator reading the site error log, not for the caller: a tool reply is
      // copied into the audit log, which is why it is not returned.
      GMCP_Logging::error( 'Export archive build failed for ' . $resolved['root'] . '/' . $resolved['relative'] . ': ' . $e->getMessage() );
      if ( $changed !== '' ) {
        return $this->error( $r, 'The tree changed while this archive was being built (the check that failed was "' . $changed . '"), so nothing was kept. Run mode=manifest again: the approval is for the bytes that were there when it was given, not for whatever is there now.' );
      }
      return $this->error( $r, 'The archive could not be built, so nothing was kept. Its message is not returned; the site error log has it.' );
    }
    @chmod( $tmp, 0600 );

    try {
      $token = bin2hex( random_bytes( 32 ) );
    }
    catch ( \Throwable $e ) {
      @unlink( $tmp );
      return $this->error( $r, 'A one-time token could not be generated, so the archive was removed and no URL was issued.' );
    }
    $archive_name = sanitize_file_name( 'export-' . $resolved['root'] . '-' . gmdate( 'Ymd-His' ) . '.zip' );
    $stored = [
      'path' => $tmp,
      'filename' => $archive_name,
      'expires' => time() + self::TOKEN_TTL,
      'site' => get_current_blog_id(),
      'bytes' => (int) $scan['total_bytes'],
      'files' => (int) $scan['file_count'],
    ];
    if ( !add_option( self::TOKEN_OPTION_PREFIX . $token, $stored, '', false ) ) {
      @unlink( $tmp );
      return $this->error( $r, 'The one-time token could not be stored, so the archive was removed and no URL was issued.' );
    }
    $keep = true;
    wp_schedule_single_event( time() + self::TOKEN_TTL + MINUTE_IN_SECONDS, self::CRON_HOOK );

    $url = rest_url( 'mcp/v1/export/' . $token );
    return $this->json( $r, [
      'root' => $resolved['root'],
      'path' => $resolved['relative'],
      'archive' => $archive_name,
      // Early in the reply on purpose: the audit log keeps only the first 1000 characters of
      // a result text, and a token placed after that would be absent from the log whether or
      // not the scrubber worked. Keeping it here makes the suite's absence check meaningful.
      'export_url' => $url,
      'expires_in' => '5 minutes',
      'file_count' => (int) $scan['file_count'],
      'total_bytes' => (int) $scan['total_bytes'],
      'manifest_digest' => (string) $scan['manifest_digest'],
      'excluded_count' => (int) $scan['excluded_count'],
      'note' => 'One use only. The zip is streamed by the endpoint and deleted after it is sent; its bytes are never put in a tool reply or in the audit log. The token in this URL is redacted from the log.',
    ] );
  }

  /**
  * Walk the resolved path and describe it.
  *
  * The digest is computed from a sorted list rather than from the order the filesystem
  * happened to hand entries back, so two servers with the same files agree and a manifest
  * taken before an approval still matches on the retry.
  */
  private function scan( string $root_key, array $resolved ): array {
    $max_entries = (int) apply_filters( 'gmcp_export_max_entries', self::MAX_ENTRIES, $root_key, $resolved['relative'] );
    $max_bytes = (int) apply_filters( 'gmcp_export_max_bytes', self::MAX_BYTES, $root_key, $resolved['relative'] );
    $max_entries = max( 1, $max_entries );
    $max_bytes = max( 1, $max_bytes );

    // Which files in uploads hang from a form submission. Asked once per walk, before any file
    // is read, and a failure to ask is a refusal and not an empty set: a walk that could not
    // tell what is personal data would archive it.
    $private = null;
    if ( $root_key === 'uploads' ) {
      $private = self::personal_upload_paths();
      if ( $private === null ) {
        return [ 'refused' => true, 'message' => 'The database could not say which files in uploads belong to form submissions, so nothing was walked. Try again; the manifest and the pull both refuse rather than archive files that might be a visitor\'s.' ];
      }
    }
    $ctx = [
      'root' => $root_key,
      'resolved' => $resolved,
      'private' => $private,
      // Where the walk's base sits under the root, with a trailing slash, so a path found
      // below it can be compared with what the database stores (relative to uploads).
      'prefix' => ( $resolved['kind'] === 'dir' && $resolved['relative'] !== '' ) ? $resolved['relative'] . '/' : '',
    ];

    $included = [];
    $excluded = [];
    $entries_seen = 0;
    $total_bytes = 0;
    $depth = 0;

    $add_excluded = static function ( string $rel, string $reason ) use ( &$excluded ) {
      $excluded[] = [ 'path' => $rel, 'reason' => $reason ];
    };

    if ( $resolved['kind'] === 'file' ) {
      $entries_seen = 1;
      $entry = $this->file_entry( $resolved['base'], $resolved['relative'], $ctx, $add_excluded, null, $max_bytes );
      if ( isset( $entry['refuse'] ) ) {
        return $this->too_large( 'bytes', $entries_seen, $entry['size'], $max_entries, $max_bytes );
      }
      if ( $entry !== null ) {
        $included[] = $entry;
        $total_bytes += $entry['size'];
        $depth = 1;
      }
    }
    else {
      $stack = [ [ $resolved['base'], '', 0 ] ];
      while ( $stack ) {
        [ $dir, $dir_rel, $dir_depth ] = array_pop( $stack );
        // readdir() rather than scandir(): scandir() allocates and sorts the whole listing
        // before the first name is looked at, so one directory holding millions of entries
        // would exhaust the request's memory before the entry cap could refuse the walk — the
        // cap would then describe a fatal error rather than a refusal. readdir() hands back one
        // name at a time and the count below is enforced as it goes.
        $handle = @opendir( $dir );
        if ( $handle === false ) {
          if ( $dir_rel !== '' ) {
            $add_excluded( $dir_rel, 'unreadable_directory' );
          }
          continue;
        }
        try {
          while ( ( $name = readdir( $handle ) ) !== false ) {
            if ( $name === '.' || $name === '..' ) {
              continue;
            }
            $entries_seen++;
            if ( $entries_seen > $max_entries ) {
              return $this->too_large( 'entries', $entries_seen, $total_bytes, $max_entries, $max_bytes );
            }
            $rel = $dir_rel === '' ? $name : $dir_rel . '/' . $name;
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $lstat = @lstat( $path );
            if ( $lstat === false ) {
              $add_excluded( $rel, 'unreadable' );
              continue;
            }
            $type = (int) $lstat['mode'] & 0170000;
            if ( $type === 0120000 ) {
              // lstat, so this is the link itself. Never realpath() it and never descend.
              $add_excluded( $rel, 'symbolic_link' );
              continue;
            }
            if ( $type === 0040000 ) {
              // Named before it is entered, so a private directory costs the walk nothing: its
              // entries are neither read nor counted against the caps.
              $why = self::name_refusal( $name, true, $root_key );
              if ( $why !== '' ) {
                $add_excluded( $rel, $why );
                continue;
              }
              $depth = max( $depth, $dir_depth + 1 );
              $included[] = [
                'type' => 'dir',
                'rel' => $rel,
                'path' => null,
                'size' => 0,
                'mtime' => (int) $lstat['mtime'],
              ];
              $stack[] = [ $path, $rel, $dir_depth + 1 ];
              continue;
            }
            if ( $type !== 0100000 ) {
              $add_excluded( $rel, 'not_regular_file' );
              continue;
            }
            $entry = $this->file_entry( $path, $rel, $ctx, $add_excluded, $lstat, $max_bytes - $total_bytes );
            if ( isset( $entry['refuse'] ) ) {
              return $this->too_large( 'bytes', $entries_seen, $total_bytes + $entry['size'], $max_entries, $max_bytes );
            }
            if ( $entry !== null ) {
              $included[] = $entry;
              $total_bytes += $entry['size'];
              $depth = max( $depth, $dir_depth + 1 );
            }
            if ( $total_bytes > $max_bytes ) {
              return $this->too_large( 'bytes', $entries_seen, $total_bytes, $max_entries, $max_bytes );
            }
          }
        }
        finally {
          // Also runs on the refusals above, which is why the handle is closed here rather
          // than after the loop.
          closedir( $handle );
        }
      }
    }

    if ( $entries_seen > $max_entries ) {
      return $this->too_large( 'entries', $entries_seen, $total_bytes, $max_entries, $max_bytes );
    }
    if ( $total_bytes > $max_bytes ) {
      return $this->too_large( 'bytes', $entries_seen, $total_bytes, $max_entries, $max_bytes );
    }

    usort( $included, static function ( $a, $b ) {
      return strcmp( $a['rel'], $b['rel'] );
    } );
    usort( $excluded, static function ( $a, $b ) {
      return strcmp( $a['path'], $b['path'] );
    } );

    $digest = hash_init( 'sha256' );
    // v2, because v1 covered names, sizes and mtimes only: a file rewritten in place with the
    // same number of bytes inside the same second left a v1 digest unchanged, so an approval
    // taken before the rewrite still matched it.
    hash_update( $digest, "gmcp-export-manifest-v2\0" . $root_key . "\0" . $resolved['relative'] . "\0" );
    $files = [];
    $directories = [];
    $file_count = 0;
    foreach ( $included as $entry ) {
      $is_file = $entry['type'] === 'file';
      if ( $is_file ) {
        // The whole entry, not just its path: the pull re-checks the device, the inode, the
        // size and the content hash of each one as it adds it, so everything it compares
        // against has to travel with the list it was built from.
        $files[] = $entry;
        $file_count++;
      } else {
        $directories[] = $entry['rel'];
      }
      self::hash_entry( $digest, $is_file ? 'F' : 'D', $entry['rel'], (int) $entry['size'], (int) $entry['mtime'], '', $is_file ? (string) $entry['sha256'] : '', $is_file ? (string) $entry['crc32'] : '' );
    }
    // Exclusions are part of the manifest: a symlink appearing after a person approved the
    // manifest still changes what the archive would contain, so it changes the digest too.
    foreach ( $excluded as $entry ) {
      self::hash_entry( $digest, 'X', $entry['path'], 0, 0, $entry['reason'] );
    }
    $directories = array_values( array_filter( $directories, static function ( $rel ) {
      return $rel !== '';
    } ) );

    $by_reason = [];
    foreach ( $excluded as $entry ) {
      $by_reason[ $entry['reason'] ] = ( $by_reason[ $entry['reason'] ] ?? 0 ) + 1;
    }
    ksort( $by_reason );

    return [
      'root' => $root_key,
      'path' => $resolved['relative'],
      'kind' => $resolved['kind'],
      'file_count' => $file_count,
      'directory_count' => count( $directories ),
      'total_bytes' => $total_bytes,
      'depth' => $depth,
      'manifest_digest' => hash_final( $digest ),
      'caps' => [ 'max_entries' => $max_entries, 'max_bytes' => $max_bytes ],
      'excluded_count' => count( $excluded ),
      'excluded_by_reason' => (object) $by_reason,
      'excluded' => array_slice( $excluded, 0, self::EXCLUDED_LIST_MAX ),
      'excluded_truncated' => count( $excluded ) > self::EXCLUDED_LIST_MAX,
      // Only pull needs these; they are assembled from the same walk that produced the
      // digest, so the archive cannot be built from a different list than the one approved.
      'files' => $files,
      'directories' => $directories,
      'note' => 'Counts, a digest and the entries that WOULD be excluded. A manifest reads each file once, in one pass, only to hash its contents, and returns no file bytes: the digest covers names, sizes and contents. Over the caps this call refuses rather than returning a truncated count.',
    ];
  }

  /**
  * One regular file, or an exclusion. The lstat is passed in when the walk already has it;
  * a direct file path does its own.
  *
  * The file's bytes are read here, in one streaming pass, and their SHA-256 and CRC-32 are kept.
  * The digest a person approves has to cover what is IN the file: a name, a size and an mtime all
  * survive an equal-length rewrite inside the same second, so a manifest built from those three
  * alone can be approved for one tree and pulled from another. Nothing is buffered here and no
  * bytes leave this function; only the two hashes and the identity of the inode go into the
  * manifest, and the pull re-checks all of them as it adds each entry.
  */
  private function file_entry( string $path, string $rel, array $ctx, callable $add_excluded, ?array $lstat = null, int $room = PHP_INT_MAX ): ?array {
    $lstat = $lstat ?? @lstat( $path );
    if ( $lstat === false ) {
      $add_excluded( $rel, 'unreadable' );
      return null;
    }
    // Before anything is opened: a private file is withheld, not read and then dropped.
    $why = self::name_refusal( basename( $rel ), false, (string) $ctx['root'] );
    if ( $why === '' && $ctx['private'] !== null && self::is_personal_upload( (string) $ctx['prefix'] . $rel, $ctx['private'] ) ) {
      $why = 'personal_data_upload';
    }
    if ( $why !== '' ) {
      $add_excluded( $rel, $why );
      return null;
    }
    if ( (int) $lstat['nlink'] > 1 ) {
      $add_excluded( $rel, 'hard_link' );
      return null;
    }
    if ( !is_readable( $path ) ) {
      $add_excluded( $rel, 'unreadable' );
      return null;
    }
    $real = realpath( $path );
    if ( $real === false || !self::inside( $real, $ctx['resolved']['base'] ) ) {
      $add_excluded( $rel, 'outside_root' );
      return null;
    }
    // The size is known from the lstat, so a file that would take the walk over the byte cap is
    // refused here, before a byte of it is read. The check used to run after the hash, which
    // made the cost of a refusal the size of the largest file: one 4 GiB file kept a worker
    // reading for 19 seconds to be told no (measured).
    if ( (int) $lstat['size'] > $room ) {
      return [ 'refuse' => 'bytes', 'size' => (int) $lstat['size'] ];
    }
    $contents = self::hash_contents( $real, (int) $lstat['size'] );
    if ( $contents === null ) {
      $add_excluded( $rel, 'unreadable' );
      return null;
    }
    return [
      'type' => 'file',
      'rel' => $rel,
      'path' => $real,
      'size' => (int) $lstat['size'],
      'mtime' => (int) $lstat['mtime'],
      // The inode identity and the size as they were when the bytes were hashed, so the pull
      // can tell a file that is still that file from one that has been replaced by another of
      // the same name and size.
      'dev' => (int) ( $lstat['dev'] ?? 0 ),
      'ino' => (int) ( $lstat['ino'] ?? 0 ),
      'sha256' => $contents['sha256'],
      'crc32' => $contents['crc32'],
    ];
  }

  /**
  * SHA-256 and CRC-32 of a regular file, read once with one fixed buffer.
  *
  * One pass, because the walk is already reading every file to hash it: sizing the tree first and
  * hashing it afterwards would read each byte twice. The CRC-32 is kept as well because a zip
  * records a CRC-32 per entry and nothing stronger, which is what lets the closed archive be
  * compared with the manifest without reading the tree again. {@see self::archive_refusal()}.
  *
  * @return array{sha256:string,crc32:string}|null Null when the bytes could not be read whole.
  */
  private static function hash_contents( string $path, int $expected_size ): ?array {
    $fh = @fopen( $path, 'rb' );
    if ( $fh === false ) {
      return null;
    }
    $sha = hash_init( 'sha256' );
    $crc = hash_init( 'crc32b' );
    $read = 0;
    while ( !feof( $fh ) ) {
      // Never past one byte beyond the size the walk saw. A file that is being appended to
      // would otherwise be read for as long as it grows, with no cap anywhere on the pass.
      $chunk = fread( $fh, (int) min( 1048576, $expected_size - $read + 1 ) );
      if ( $chunk === false || $chunk === '' ) {
        break;
      }
      $read += strlen( $chunk );
      if ( $read > $expected_size ) {
        fclose( $fh );
        return null;
      }
      hash_update( $sha, $chunk );
      hash_update( $crc, $chunk );
    }
    $whole = feof( $fh ) && $read === $expected_size;
    fclose( $fh );
    if ( !$whole ) {
      return null;
    }
    return [ 'sha256' => hash_final( $sha ), 'crc32' => hash_final( $crc ) ];
  }

  /**
  * Re-check one manifest entry immediately before it is added to the archive.
  *
  * ZipArchive::addFile() opens the path itself, later than every check this tool made, so between
  * the walk and that open an entry can be replaced by a symbolic link, by a different inode, by
  * something that is no longer a regular file, by a hard link, or by a path that now resolves
  * outside the root. Re-running is_link(), lstat() and realpath() here, and comparing the device
  * and inode with the ones the walk recorded, refuses every one of those that can be seen from
  * the path at this moment.
  *
  * WHAT THIS DOES NOT CLOSE, plainly. There is no snapshot of a file tree without copying it, so
  * the gap between this check, the content check below it and the read ZipArchive does at close()
  * stays open. A writer that can replace a file inside that window is caught afterwards only by
  * {@see self::archive_refusal()}, which compares the closed archive's size and CRC-32 with the
  * manifest: enough for an accidental change and for a naive rewrite, not enough against somebody
  * who builds a CRC-32 collision on purpose. Closing it would mean copying every file, which
  * doubles the temporary space this tool needs; that trade was not made, and it is written here
  * rather than left to be assumed closed.
  *
  * @return string Empty when the entry is still the file the manifest described, otherwise the
  *   name of the check that failed.
  */
  private static function entry_refusal( array $file, string $base ): string {
    $path = (string) $file['path'];
    if ( is_link( $path ) ) {
      return 'entry_replaced_by_symlink';
    }
    $lstat = @lstat( $path );
    if ( $lstat === false ) {
      return 'entry_vanished';
    }
    if ( ( (int) $lstat['mode'] & 0170000 ) !== 0100000 ) {
      return 'entry_not_a_regular_file';
    }
    if ( (int) $lstat['nlink'] > 1 ) {
      return 'entry_became_a_hard_link';
    }
    if ( (int) ( $lstat['dev'] ?? 0 ) !== (int) $file['dev'] || (int) ( $lstat['ino'] ?? 0 ) !== (int) $file['ino'] ) {
      return 'entry_inode_changed';
    }
    $real = realpath( $path );
    if ( $real === false || $real !== $path || !self::inside( $real, $base ) ) {
      return 'entry_outside_root';
    }
    if ( (int) $lstat['size'] !== (int) $file['size'] ) {
      return 'entry_size_changed';
    }
    $contents = self::hash_contents( $path, (int) $file['size'] );
    if ( $contents === null ) {
      return 'entry_unreadable';
    }
    if ( !hash_equals( (string) $file['sha256'], $contents['sha256'] ) ) {
      return 'entry_contents_changed';
    }
    return '';
  }

  /**
  * Compare the archive's own record of each entry with the manifest.
  *
  * ZipArchive::addFile() does not read the file when it is called: it records the path and reads
  * it at close(). So the check before addFile() is a check on the call, and this is the check on
  * the bytes: a zip records an uncompressed size and a CRC-32 per entry, which is what can be
  * compared without reading the tree a fourth time. {@see self::entry_refusal()} for the part of
  * the window this cannot see.
  *
  * @return string Empty when every entry matches, otherwise the name of the check that failed.
  */
  private static function archive_refusal( string $tmp, array $files ): string {
    $zip = new ZipArchive();
    if ( $zip->open( $tmp ) !== true ) {
      return 'archive_unreadable';
    }
    $refusal = '';
    foreach ( $files as $file ) {
      $stat = $zip->statName( (string) $file['rel'] );
      if ( !is_array( $stat ) ) {
        $refusal = 'archive_entry_missing';
        break;
      }
      if ( (int) $stat['size'] !== (int) $file['size'] ) {
        $refusal = 'archive_size_changed';
        break;
      }
      if ( (int) $stat['crc'] !== (int) hexdec( (string) $file['crc32'] ) ) {
        $refusal = 'archive_contents_changed';
        break;
      }
    }
    $zip->close();
    return $refusal;
  }

  private function too_large( string $which, int $entries, int $bytes, int $max_entries, int $max_bytes ): array {
    $message = $which === 'entries'
      ? sprintf( 'This tree is over the %d-entry cap (the walk had already seen %d), so it was refused rather than truncated. Narrow path, or raise gmcp_export_max_entries on a site that has decided it wants this archive.', $max_entries, $entries )
      : sprintf( 'This tree is over the %s cap (the walk had already counted %s), so it was refused rather than truncated. Narrow path, or raise gmcp_export_max_bytes on a site that has decided it wants this archive.', self::bytes_label( $max_bytes ), self::bytes_label( $bytes ) );
    return [
      'refused' => true,
      'reason' => $which === 'entries' ? 'entry_cap' : 'byte_cap',
      'entries_seen' => $entries,
      'bytes_seen' => $bytes,
      'caps' => [ 'max_entries' => $max_entries, 'max_bytes' => $max_bytes ],
      'message' => $message,
    ];
  }

  private static function bytes_label( int $bytes ): string {
    if ( $bytes >= MB_IN_BYTES ) {
      return round( $bytes / MB_IN_BYTES, 1 ) . ' MiB';
    }
    if ( $bytes >= KB_IN_BYTES ) {
      return round( $bytes / KB_IN_BYTES, 1 ) . ' KiB';
    }
    return $bytes . ' bytes';
  }

  /**
  * Fold one manifest entry into the digest.
  *
  * Every varying field is length-prefixed, so two different manifests cannot produce the same
  * bytes here: a file called `a/b` and a directory `a` holding `b` stay distinguishable. The
  * content hash is the reason this exists in its current shape: without it the digest describes a
  * listing, and a listing survives an equal-length rewrite inside the same second.
  */
  private static function hash_entry( $digest, string $type, string $rel, int $size, int $mtime, string $reason, string $sha256 = '', string $crc32 = '' ): void {
    hash_update( $digest, $type . pack( 'N', strlen( $rel ) ) . $rel . pack( 'J', $size ) . pack( 'J', $mtime ) . pack( 'N', strlen( $reason ) ) . $reason . $sha256 . $crc32 );
  }

  /**
  * Why a name is never exported, or '' when it may be.
  *
  * Two different reasons, so the manifest can tell a person which kind of thing was left out:
  *
  *   unsafe_name   the name is not valid UTF-8, or holds a backslash, a control character or a
  *                 bidirectional or zero-width character. A zip entry called `a\..\..\x` is a
  *                 single file on this server and a path traversal for an extractor that reads a
  *                 backslash as a separator (measured: it went into the archive as written), and a
  *                 name that is not UTF-8 made the finished archive disagree with the manifest, so
  *                 one such file refused the whole directory with a message about the tree
  *                 changing.
  *   private_name  {@see self::PRIVATE_NAME_GLOBS}, and for the uploads root
  *                 {@see self::PRIVATE_UPLOAD_DIRS}.
  */
  private static function name_refusal( string $name, bool $is_dir, string $root_key ): string {
    if ( preg_match( '//u', $name ) !== 1 || preg_match( '/[\x{0}-\x{1F}\x{7F}\\\\\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $name ) === 1 ) {
      return 'unsafe_name';
    }
    // Both readings: the name as typed (so `...` stays hidden by `.*` although it normalises to
    // nothing) and the name as it is taken for.
    $reads = array_values( array_unique( [ strtolower( $name ), self::match_name( $name ) ] ) );
    static $patterns = null;
    if ( $patterns === null ) {
      $globs = array_merge( self::PRIVATE_NAME_GLOBS, array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'gmcp_export_private_names', [] ) ) ) );
      $patterns = [];
      foreach ( array_unique( $globs ) as $glob ) {
        $patterns[] = '/^' . str_replace( '\\*', '.*', preg_quote( $glob, '/' ) ) . '$/s';
      }
    }
    foreach ( $reads as $lower ) {
      foreach ( $patterns as $re ) {
        if ( preg_match( $re, $lower ) === 1 ) {
          return 'private_name';
        }
      }
      if ( !$is_dir && preg_match( '/(backup|dump|snapshot).*\.(zip|gz|tgz|tar|bz2|xz|7z|rar)$/', $lower ) === 1 ) {
        return 'private_name';
      }
    }
    if ( $is_dir && $root_key === 'uploads' ) {
      static $dirs = null;
      if ( $dirs === null ) {
        $dirs = array_merge( self::PRIVATE_UPLOAD_DIRS, array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'gmcp_export_private_directories', [] ) ) ) );
      }
      foreach ( $reads as $lower ) {
        if ( in_array( $lower, $dirs, true ) ) {
          return 'private_name';
        }
        foreach ( self::PRIVATE_UPLOAD_DIR_PREFIXES as $prefix ) {
          if ( strpos( $lower, $prefix ) === 0 ) {
            return 'private_name';
          }
        }
      }
    }
    return '';
  }

  /**
  * A file name as the private-name rules should read it: the name a person, a web server or an
  * operating system would take it for.
  *
  * NFKC where the intl extension exists (full-width letters and the Kelvin sign become the plain
  * ones; without intl the full-width ASCII block is mapped by hand), every format character
  * removed (a soft hyphen or word joiner inside `.env` leaves it `.env`), then trailing dots and
  * any kind of space or control character removed, and leading ones too (Windows drops a
  * trailing dot or space when it creates a file, so `server.key.` is `server.key` there, and a
  * leading no-break space before `.env` is a thin disguise), then case folded. The archive entry
  * keeps the real name; this is for matching only.
  */
  private static function match_name( string $name ): string {
    $n = $name;
    if ( class_exists( 'Normalizer' ) ) {
      $folded = \Normalizer::normalize( $n, \Normalizer::FORM_KC );
      if ( is_string( $folded ) ) {
        $n = $folded;
      }
    }
    else {
      $n = (string) preg_replace_callback( '/[\x{FF01}-\x{FF5E}]/u', static function ( $m ) {
        return chr( mb_ord( $m[0], 'UTF-8' ) - 0xFEE0 );
      }, $n );
    }
    $n = (string) preg_replace( '/[\p{Cf}]/u', '', $n );
    $n = (string) preg_replace( '/^[\s\p{Z}\p{Cc}]+/u', '', $n );
    $n = (string) preg_replace( '/[\s\p{Z}\p{Cc}.]+$/u', '', $n );
    return function_exists( 'mb_strtolower' ) ? mb_strtolower( $n, 'UTF-8' ) : strtolower( $n );
  }

  /** A path as the comparison below wants it: lower case, NFC where the intl extension exists. */
  private static function fold_path( string $path ): string {
    $path = ltrim( str_replace( '\\', '/', $path ), '/' );
    if ( class_exists( 'Normalizer' ) ) {
      $n = \Normalizer::normalize( $path, \Normalizer::FORM_C );
      if ( is_string( $n ) ) {
        $path = $n;
      }
    }
    return function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
  }

  /**
  * The files in uploads that belong to something a visitor sent: attachments whose
  * post_parent chain reaches a form-submission post type, the same tree the read guards judge
  * ({@see GMCP_Core::unreadable_post_clauses()}). `wp_download_request` refuses such a file at
  * every level, and an archive of the whole of uploads would carry it out by another door.
  *
  * What is collected for each attachment: the file itself, every generated size, the original
  * of a scaled image, the backups of an edited one, and each alternative format a size lists.
  * Anything else that sits beside one of them and starts with its name (an optimiser's
  * `photo.jpg.webp`) is matched by stem in is_personal_upload().
  *
  * @return array{files:array<string,true>,stems:array<string,true>}|null Null when the database
  *   could not be asked, which the caller reads as a refusal and never as "none".
  */
  private static function personal_upload_paths(): ?array {
    global $wpdb;
    if ( !class_exists( 'GMCP_Core' ) ) {
      return null;
    }
    $clauses = GMCP_Core::unreadable_post_clauses( 'gmcp_x.post_type', 'gmcp_x.post_parent', 'gmcp_xu' );
    $files = [];
    $stems = [];
    if ( $clauses['where'] === '' ) {
      return [ 'files' => $files, 'stems' => $stems ];
    }
    $noisy = $wpdb->suppress_errors( true );
    try {
      $last = 0;
      while ( true ) {
        $ids = $wpdb->get_col( $wpdb->prepare(
          "SELECT gmcp_x.ID FROM {$wpdb->posts} gmcp_x {$clauses['join']}
            WHERE gmcp_x.post_type = 'attachment' AND gmcp_x.ID > %d AND NOT ( {$clauses['where']} )
            ORDER BY gmcp_x.ID ASC LIMIT 500",
          $last
        ) );
        if ( $wpdb->last_error ) {
          return null;
        }
        if ( !$ids ) {
          break;
        }
        foreach ( $ids as $id ) {
          $id = (int) $id;
          $last = max( $last, $id );
          $attached = get_post_meta( $id, '_wp_attached_file', true );
          $meta = get_post_meta( $id, '_wp_attachment_metadata', true );
          $backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
          $names = [];
          if ( is_string( $attached ) && $attached !== '' ) {
            $names[] = $attached;
          }
          $dir = is_string( $attached ) && strpos( $attached, '/' ) !== false ? dirname( $attached ) : '';
          $add = static function ( $file ) use ( &$names, $dir ) {
            if ( is_string( $file ) && $file !== '' ) {
              $names[] = ( $dir !== '' && strpos( $file, '/' ) === false ) ? $dir . '/' . $file : $file;
            }
          };
          if ( is_array( $meta ) ) {
            $add( $meta['file'] ?? null );
            $add( $meta['original_image'] ?? null );
            foreach ( (array) ( $meta['sources'] ?? [] ) as $source ) {
              $add( is_array( $source ) ? ( $source['file'] ?? null ) : null );
            }
            foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
              if ( !is_array( $size ) ) {
                continue;
              }
              $add( $size['file'] ?? null );
              foreach ( (array) ( $size['sources'] ?? [] ) as $source ) {
                $add( is_array( $source ) ? ( $source['file'] ?? null ) : null );
              }
            }
          }
          foreach ( is_array( $backups ) ? $backups : [] as $backup ) {
            $add( is_array( $backup ) ? ( $backup['file'] ?? null ) : null );
          }
          foreach ( $names as $name ) {
            $folded = self::fold_path( $name );
            $files[ $folded ] = true;
            $base = basename( $folded );
            $stem = preg_replace( '/\.[^.]*$/', '', $base );
            $stem = preg_replace( '/-(?:scaled|rotated|e\d+|\d+x\d+)$/', '', (string) $stem );
            if ( is_string( $stem ) && $stem !== '' ) {
              $d = dirname( $folded );
              $stems[ ( $d === '.' ? '' : $d . '/' ) . $stem ] = true;
            }
          }
        }
      }
    }
    finally {
      $wpdb->suppress_errors( $noisy );
    }
    return [ 'files' => $files, 'stems' => $stems ];
  }

  /** Whether a path under uploads is one of those files, or sits beside one under its name. */
  private static function is_personal_upload( string $rel_to_uploads, array $private ): bool {
    $folded = self::fold_path( $rel_to_uploads );
    if ( isset( $private['files'][ $folded ] ) ) {
      return true;
    }
    if ( !$private['stems'] ) {
      return false;
    }
    $d = dirname( $folded );
    $dir = $d === '.' ? '' : $d . '/';
    $base = basename( $folded );
    // The stem followed by a dot, a dash or the end: `passport` covers `passport.jpg.webp` and
    // `passport-300x200.jpg`, not `passports.jpg`.
    foreach ( [ $base, preg_replace( '/\.[^.]*$/', '', $base ) ] as $candidate ) {
      $candidate = (string) $candidate;
      while ( $candidate !== '' ) {
        if ( isset( $private['stems'][ $dir . $candidate ] ) ) {
          return true;
        }
        $cut = max( (int) strrpos( $candidate, '.' ), (int) strrpos( $candidate, '-' ) );
        if ( $cut <= 0 ) {
          break;
        }
        $candidate = substr( $candidate, 0, $cut );
      }
    }
    return false;
  }

  #endregion

  #region Roots and containment

  /**
  * Resolve one of the four names and an optional relative path.
  *
  * Every component is lstat'ed. A symlink anywhere in the caller's path is refused, not
  * followed, and a path that is itself a hard link is refused rather than archived under a
  * name that does not own the file. The final realpath is checked against the realpath of the
  * named root on a directory boundary.
  *
  * @return array{base:string,relative:string,root:string,kind:string}|array{error:string}
  */
  private function resolve( string $root_key, string $path ): array {
    if ( !in_array( $root_key, self::ROOTS, true ) ) {
      return [ 'error' => 'root must be one of: ' . implode( ', ', self::ROOTS ) . '.' ];
    }
    $roots = self::roots();
    if ( empty( $roots[ $root_key ] ) ) {
      return [ 'error' => 'That root is not defined on this site, so there is nothing to read.' ];
    }
    $root_real = realpath( (string) $roots[ $root_key ] );
    if ( $root_real === false || !is_dir( $root_real ) ) {
      return [ 'error' => 'That root does not exist or is not a directory on this server.' ];
    }

    $path = trim( $path );
    if ( strpos( $path, "\0" ) !== false ) {
      return [ 'error' => 'A path may not contain a null byte.' ];
    }
    if ( $path === '' ) {
      return [ 'base' => $root_real, 'relative' => '', 'root' => $root_key, 'kind' => 'dir' ];
    }
    if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path ) || strpos( $path, '://' ) !== false ) {
      return [ 'error' => 'A path may not use a stream wrapper. Name a relative path under the root instead.' ];
    }
    if ( $path[0] === '/' || $path[0] === '\\' || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) ) {
      return [ 'error' => 'A path must be relative to the named root. Absolute paths are refused.' ];
    }

    $segments = preg_split( '#[/\\\\]+#', $path );
    if ( !is_array( $segments ) || $segments === [] ) {
      return [ 'error' => 'That path is not a usable relative path.' ];
    }
    $cursor = $root_real;
    $last_index = count( $segments ) - 1;
    foreach ( $segments as $index => $segment ) {
      if ( $segment === '' || $segment === '.' || $segment === '..' ) {
        return [ 'error' => 'A path may not contain an empty, "." or ".." segment. Name a path under the root.' ];
      }
      $next = $cursor . DIRECTORY_SEPARATOR . $segment;
      if ( is_link( $next ) ) {
        return [ 'error' => 'That path runs through a symbolic link, which this tool refuses rather than follows.' ];
      }
      $lstat = @lstat( $next );
      if ( $lstat === false ) {
        return [ 'error' => 'That path does not exist under the named root.' ];
      }
      $type = (int) $lstat['mode'] & 0170000;
      if ( $index < $last_index ) {
        if ( $type !== 0040000 ) {
          return [ 'error' => 'That path runs through something that is not a directory.' ];
        }
        $why = self::name_refusal( $segment, true, $root_key );
        if ( $why !== '' ) {
          return [ 'error' => self::withheld_path_error( $why ) ];
        }
        $cursor = $next;
        continue;
      }

      if ( $type === 0040000 ) {
        $why = self::name_refusal( $segment, true, $root_key );
        if ( $why !== '' ) {
          return [ 'error' => self::withheld_path_error( $why ) ];
        }
        if ( !is_readable( $next ) ) {
          return [ 'error' => 'That directory is not readable by the server.' ];
        }
        $cursor = $next;
        break;
      }
      if ( $type !== 0100000 ) {
        return [ 'error' => 'That path is not a regular file or directory, so it is not exported.' ];
      }
      $why = self::name_refusal( $segment, false, $root_key );
      if ( $why !== '' ) {
        return [ 'error' => self::withheld_path_error( $why ) ];
      }
      if ( (int) $lstat['nlink'] > 1 ) {
        return [ 'error' => 'That path is a hard link. It is refused rather than exported under a name that does not own the file.' ];
      }
      if ( !is_readable( $next ) ) {
        return [ 'error' => 'That file is not readable by the server.' ];
      }
      $real = realpath( $next );
      if ( $real === false || !self::inside( $real, $root_real ) ) {
        return [ 'error' => 'That path resolves outside the named root, so it is refused.' ];
      }
      return [ 'base' => $real, 'relative' => implode( '/', $segments ), 'root' => $root_key, 'kind' => 'file' ];
    }

    $real = realpath( $cursor );
    if ( $real === false || !self::inside( $real, $root_real ) ) {
      return [ 'error' => 'That path resolves outside the named root, so it is refused.' ];
    }
    return [ 'base' => $real, 'relative' => implode( '/', $segments ), 'root' => $root_key, 'kind' => 'dir' ];
  }

  /** The refusal for a `path` that names, or runs through, a name this tool never exports. */
  private static function withheld_path_error( string $why ): string {
    return $why === 'unsafe_name'
      ? 'That path names, or runs through, a file or directory whose name is not safe to put in an archive (it is not UTF-8, or holds a backslash or a control character), so it is not exported.'
      : 'That path names, or runs through, a file or directory this tool never exports (dot-files, wp-config, keys, database dumps, logs, backups and the private directories of form and shop plugins). Name a path that does not.';
  }

  private static function roots(): array {
    return [
      'plugins' => defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins',
      'themes' => (string) get_theme_root(),
      'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
      'uploads' => (string) ( wp_get_upload_dir()['basedir'] ?? '' ),
    ];
  }

  /** Whether $path is $root or sits below it, on a directory boundary. */
  private static function inside( string $path, string $root ): bool {
    $root = rtrim( $root, '/\\' );
    if ( $root === '' ) {
      return false;
    }
    return $path === $root || strpos( $path, $root . DIRECTORY_SEPARATOR ) === 0;
  }

  #endregion

  #region Tokens and temporary files

  /**
  * A temporary directory that is outside every web-served path this site knows about.
  *
  * get_temp_dir() is not used: it falls back to WP_CONTENT_DIR, which is web-served on most
  * hosts, and an archive there would be fetchable by URL regardless of the token. If no
  * candidate is outside the site, the pull refuses rather than putting a zip where the web
  * server can serve it.
  *
  * BOTH SIDES ARE COMPARED AS REALPATHS. The candidate always was. ABSPATH and WP_CONTENT_DIR
  * were not, and on a deployment where the site directory (or wp-content) is a symlink those
  * constants carry the link, so a canonical temporary directory inside the real directory did
  * not look like it was inside the web root: `/var/www/html/wp-content/tmp` is inside
  * `/mnt/data/site/wp-content` but not inside a string starting `/var/www/html`. A directory
  * the web server serves would then have been accepted as private, which is the one thing this
  * function exists to prevent.
  */
  public static function temp_dir(): ?string {
    static $resolved = false;
    static $dir = null;
    if ( $resolved ) {
      return $dir;
    }
    $resolved = true;
    $candidates = [];
    if ( defined( 'WP_TEMP_DIR' ) && WP_TEMP_DIR ) {
      $candidates[] = (string) WP_TEMP_DIR;
    }
    $candidates[] = sys_get_temp_dir();

    $uploads = wp_get_upload_dir();
    // Every path the web server can serve, canonicalised. A constant that could not be resolved
    // contributes nothing, which cannot accept a directory: the ownership of the temp directory
    // is decided by the comparison below, and a missing root only makes that comparison smaller.
    $web_served = [];
    foreach ( [ ABSPATH, (string) WP_CONTENT_DIR ] as $web_path ) {
      $real_web = realpath( (string) $web_path );
      if ( $real_web !== false ) {
        $web_served[] = $real_web;
      }
    }
    if ( !empty( $uploads['basedir'] ) ) {
      $real_uploads = realpath( (string) $uploads['basedir'] );
      if ( $real_uploads !== false ) {
        $web_served[] = $real_uploads;
      }
    }

    foreach ( array_unique( $candidates ) as $candidate ) {
      $real = realpath( $candidate );
      if ( $real === false || !is_dir( $real ) || !is_writable( $real ) ) {
        continue;
      }
      $served = false;
      foreach ( $web_served as $web_path ) {
        if ( self::inside( $real, $web_path ) ) {
          $served = true;
          break;
        }
      }
      if ( $served ) {
        continue;
      }
      $dir = $real;
      return $dir;
    }
    return null;
  }

  /**
  * Consume one token and hand back the file behind it.
  *
  * Read, then delete with a CONDITIONAL statement, so two concurrent requests cannot both
  * stream the same archive. The expiry check is here rather than left to an option cache,
  * because the row can outlive its own deadline.
  *
  * @return array{path:string,filename:string}|array{error:string,status:int}
  */
  public static function take_token( string $token ): array {
    global $wpdb;
    if ( !preg_match( '/^[A-Za-z0-9]{16,128}$/', $token ) ) {
      return [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    }
    $name = self::TOKEN_OPTION_PREFIX . $token;
    $stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
    if ( $stored === null ) {
      return [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    }
    $won = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $stored ) );
    wp_cache_delete( $name, 'options' );
    if ( $won !== 1 ) {
      return [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    }
    $data = maybe_unserialize( $stored );
    if ( !is_array( $data ) || empty( $data['path'] ) ) {
      return [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    }
    if ( empty( $data['filename'] ) ) {
      self::unlink_archive( (string) $data['path'] );
      return [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    }
    if ( (int) ( $data['expires'] ?? 0 ) < time() ) {
      self::unlink_archive( (string) $data['path'] );
      return [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    }
    $path = (string) $data['path'];
    if ( !self::safe_archive_path( $path ) ) {
      self::unlink_archive( $path );
      return [ 'error' => 'That archive is no longer available.', 'status' => 403 ];
    }
    return [ 'path' => $path, 'filename' => (string) $data['filename'] ];
  }

  /**
  * Look at a token without spending it: what a HEAD request is answered from.
  *
  * A link previewer, a mail scanner or a chat client's unfurler sends HEAD (or GET, which this
  * cannot tell from a person) to a URL it was shown, and WordPress routes HEAD to the GET
  * handler. When that handler spent the token on the way to answering, the archive was gone
  * before the person it was minted for had clicked. This checks everything take_token() checks
  * and changes nothing: no row is deleted and no file is removed, not even an expired one (the
  * GET that follows, or the sweep, does that).
  *
  * @return array{filename:string,size:int}|array{error:string,status:int}
  */
  public static function peek_token( string $token ): array {
    global $wpdb;
    $invalid = [ 'error' => 'Invalid or expired export token.', 'status' => 403 ];
    if ( !preg_match( '/^[A-Za-z0-9]{16,128}$/', $token ) ) {
      return $invalid;
    }
    $stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::TOKEN_OPTION_PREFIX . $token ) );
    if ( $stored === null ) {
      return $invalid;
    }
    $data = maybe_unserialize( $stored );
    if ( !is_array( $data ) || empty( $data['path'] ) || empty( $data['filename'] ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
      return $invalid;
    }
    $path = (string) $data['path'];
    if ( !self::safe_archive_path( $path ) || is_link( $path ) || !is_file( $path ) || !is_readable( $path ) ) {
      return [ 'error' => 'That archive is no longer available.', 'status' => 403 ];
    }
    $size = filesize( $path );
    return [ 'filename' => (string) $data['filename'], 'size' => $size === false ? 0 : (int) $size ];
  }

  /** Drop an unspent token and its file, e.g. because the group has been switched off. */
  public static function discard_token( string $token ): void {
    if ( !preg_match( '/^[A-Za-z0-9]{16,128}$/', $token ) ) {
      return;
    }
    $name = self::TOKEN_OPTION_PREFIX . $token;
    $stored = get_option( $name, null );
    delete_option( $name );
    if ( is_array( $stored ) && !empty( $stored['path'] ) ) {
      self::unlink_archive( (string) $stored['path'] );
    }
  }

  /**
  * Remove token rows that have expired and temporary archives that no row owns.
  *
  * Called by the cron event, by every export tool call and by the REST endpoint. It is
  * bounded by the number of live exports, which is small; a site with none pays one LIKE.
  */
  public static function cleanup_expired(): void {
    global $wpdb;
    $like = $wpdb->esc_like( self::TOKEN_OPTION_PREFIX ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
    foreach ( (array) $rows as $row ) {
      $data = maybe_unserialize( $row['option_value'] ?? '' );
      $expires = is_array( $data ) ? (int) ( $data['expires'] ?? 0 ) : 0;
      if ( $expires > 0 && $expires >= time() ) {
        continue;
      }
      if ( is_array( $data ) && !empty( $data['path'] ) ) {
        self::unlink_archive( (string) $data['path'] );
      }
      delete_option( (string) ( $row['option_name'] ?? '' ) );
    }
    $dir = self::temp_dir();
    if ( $dir !== null ) {
      foreach ( (array) glob( $dir . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . '*' ) as $file ) {
        $mtime = @filemtime( $file );
        if ( $mtime !== false && $mtime < time() - self::TOKEN_TTL - MINUTE_IN_SECONDS ) {
          @unlink( $file );
        }
      }
    }
  }

  /** Whether a path is a regular file we created, directly in our temp directory. */
  private static function safe_archive_path( string $path ): bool {
    $dir = self::temp_dir();
    if ( $dir === null || is_link( $path ) || !is_file( $path ) ) {
      return false;
    }
    $real = realpath( $path );
    if ( $real === false || !self::inside( $real, $dir ) || dirname( $real ) !== $dir ) {
      return false;
    }
    return strpos( basename( $real ), self::TEMP_PREFIX ) === 0;
  }

  /** Unlink a temp archive without following a link or leaving the temp directory. */
  public static function unlink_archive( string $path ): void {
    $dir = self::temp_dir();
    if ( $dir === null ) {
      return;
    }
    if ( is_link( $path ) ) {
      @unlink( $path );
      return;
    }
    $real = realpath( $path );
    if ( $real === false || dirname( $real ) !== $dir || strpos( basename( $real ), self::TEMP_PREFIX ) !== 0 ) {
      return;
    }
    if ( is_file( $real ) ) {
      @unlink( $real );
    }
  }

  #endregion

  #region Helpers

  private function error( array $r, string $message, int $code = -32602 ): array {
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message . ' [error ' . $code . ']' ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }

  private function json( array $r, $data ): array {
    $r['result'] = [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ] ] ];
    return $r;
  }

  #endregion
}
