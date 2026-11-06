# Guardrails

Why each guard exists, and what went wrong before it did. This is the reasoning
record for the plugin's guards — the "why" rather than the "what". For what the
tools are and how to call them, see the [Tools section of the README](../README.md#tools).

The plugin is built on one assumption: the agent will occasionally get it wrong.
An agent administering your site also reads your comments, your post bodies and
your plugin descriptions, all written by anonymous people, and it has no
reliable way to tell an instruction from content. So the guards are placed where
a model cannot argue its way past them.

## Content tools

Four tools exist because a value can be too large to survive a tool argument.
`wp_copy_post_meta` and `wp_duplicate_post` copy inside PHP, so a page design of
100KB never leaves the server. `wp_write_post_meta_chunk` is the general answer,
staging a value across several calls and writing the meta row only on the last
one, so a half-written value is never on the post for something else to read as
finished. `wp_read_post_meta_chunk` is its mirror, so the round trip closes: it
walks a value by byte offset, says how large the whole thing is and whether more
remains, and returns each piece as base64. A single piece is capped at 256KB,
and that number is a policy rather than a limit: nothing failed in testing until
the memory limit was lowered well below a stock host's, and on a normal one the
tool will hand back any meta value the database can deliver. A duplicate is a
draft unless you ask otherwise, because a copy that inherits `publish` goes live
on a misread instruction.

Escaping used to be a second reason to reach for those, and is no longer.
`update_metadata()` unslashes whatever it is handed, so a value carrying
backslashes was stored stripped: a regex stopped matching, a Windows path lost
its separators, and a JSON payload stopped parsing, while the tool still answered
that the meta was updated. The chunk writer had always compensated and
`wp_update_post_meta` had not, so the same bytes were stored two different ways
depending on which tool you asked. Both now go through one function that
slashes the value and decodes a JSON string for an array, which also means a
small array no longer needs the chunk API. Size is the only remaining reason to
prefer it.

The base64 on the read side is not fussiness either. A chunk boundary falls
wherever the byte count lands, which is routinely inside a multi-byte character,
and a half-character is fine only if nothing tries to repair it. Sent as text it
does not survive: `wp_json_encode` hands invalid UTF-8 to WordPress's own sanity
check, which substitutes a placeholder and reports no error, so a slice ending on
the first byte of an emoji comes back the same length with that byte turned into
a question mark. Every cheap check passes and the reassembled document differs
from the stored one. Base64 carries those bytes through untouched, and each
chunk also carries a hash of the whole value, so a caller can tell that the
value was rewritten under it mid-walk and that what it reassembled is what was
stored.

## Site administration

These tools install code and change how the site renders, so they are opt-in
and carry their own guards:

- Installs come from the wordpress.org repository by slug. An arbitrary ZIP URL
  is refused unless the site opts in through the `gmcp_allow_remote_install`
  filter, and the download host is checked so a plugin cannot rewrite the
  repository's answer. If you do open that filter, note that the URL you approve
  is the one before redirects: `download_url()` follows up to five, and
  WordPress only blocks non-HTTP schemes, odd ports and IPv4 private ranges along
  the way. Allowlist hosts you control, and be aware an open redirect on one of
  them defeats the check.
- Deleting a plugin, a theme or a menu takes two calls, as does changing the
  administration email. The first changes nothing and returns a token bound to
  that exact target; only the second proceeds, so a single instruction cannot
  complete one, which matters because this agent reads comments and post content
  that other people wrote. Content deletions are not on that list: they usually
  go to the trash and can be restored, and the irreversible form, `force: true`,
  is one call. Use `preview` on it to see what would go, including the comments
  and attachments that go with it. Usually, because two ordinary situations have
  no trash to go to: WordPress has none for attachments, and a site with
  `EMPTY_TRASH_DAYS` set to 0 has none for anything. A call without `force` then
  destroys the thing, so the reply says which of the two happened rather than
  reporting both as "deleted", and the audit log records the same sentence.
- The plugin refuses to deactivate or delete itself, to delete the active theme
  or its parent, and to activate a theme this server cannot run.
- Options can be deleted, not only set. A stale cache is sometimes clearable
  only by removing the row, and some code treats an empty array as computed and
  so never rebuilds; Elementor's theme-builder conditions are exactly that.
  Nine options are refused, each carrying the sentence for what breaks if it
  goes, and so is anything the shared write policy already refuses to change,
  since deleting a row is the harsher edit of the two and a key too dangerous to
  set cannot be safe to drop. `rewrite_rules` is deliberately not among them,
  because WordPress regenerates it and deleting it is an ordinary repair. A
  deletion is in the audit log but cannot be undone: WordPress passes only the
  name to `deleted_option`, so nothing keeps the value. The reply therefore
  carries the value that was removed, since it is the only copy anyone gets,
  withheld when it looks credential-shaped by the same test that keeps such
  values out of the journal.
- `wp_flush_cache` purges the object cache, expired transients, or one post. It
  follows the same rule as everything else here about caches: purge what can be
  named, and say what could not. The reply lists the page-cache plugins it
  recognised and then names, in as many words, the CDN or reverse proxy that no
  PHP can reach and that the caller still has to purge. A cache tool that implied
  the front end was now fresh would be worse than none, which is why that half of
  the answer is as prominent as the first.
- Scheduled events can be listed, run and removed, which is what a cron event
  Site Health keeps flagging needs. Only events the site itself already
  scheduled can be run: a tool that fires any hook you name is a tool for running
  arbitrary code on an instruction, and hook names arrive in the same text as
  everything else. This plugin's own housekeeping is refused outright. The
  listing says whether each hook still has a callback, because an event orphaned
  by a deactivated plugin can never succeed and is the usual reason one keeps
  failing, and it says whether cron runs on this site at all, since when it does
  not every event is overdue by design. Removing an event takes two calls, and
  the undo journal cannot put it back: WordPress passes only the name to
  `deleted_option`, so nothing records what the schedule held.
- Post and widget content is always filtered, regardless of the caller's
  capabilities. WordPress normally lets an administrator store raw HTML, but the
  caller being an administrator says nothing about who wrote the markup, and
  these tools sit at the `write` access level, so a deliberately limited token
  could otherwise plant a script on a public page. Blocks, shortcodes, inline
  styles and `data-` attributes all survive; `<iframe>`, inline `<svg>`,
  `<style>` blocks and Outlook conditional comments do not, unless you open
  `gmcp_allow_unfiltered_post_html`.
- Block markup is filtered structurally rather than with `wp_kses_post` alone.
  Gutenberg escapes quotes and angle brackets inside block attributes as HTML
  entities, and kses does not recognise such a delimiter comment: it escapes the
  opener and destroys the block. Only the rendered HTML inside each block is
  filtered, and the attributes round-trip as JSON.
- Markdown given to `wp_write_blocks` is converted to blocks by
  `GMCP_Markdown_Blocks`, which escapes every piece of HTML in the source instead of
  passing it through, and accepts links only for `http`, `https` and `mailto`, and
  images only for `http` and `https`. The converted markup then goes through the same
  `store_html()` path as block markup written directly, so the rules above apply to
  it too. Neither layer is trusted alone: the converter is the one that knows what the
  source meant, and the filter is the one that knows what the editor will render.
  Block specs given to the same tool are filtered leaf by leaf with `wp_kses_post()`
  as each block is rendered, without unslashing (the text arrives from JSON, not from
  `$_POST`, so a backslash in it is the author's), and that filtering is unconditional:
  it does not honour `gmcp_allow_unfiltered_post_html`, which `store_html()` does, so a
  site that opted into raw HTML still gets filtered text from block specs. That is the
  stricter of the two and was left that way on purpose.
- Tool annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`,
  `openWorldHint`) are advisory and are not a guard. Clients may use them to decide
  whether to ask first, so a wrong hint costs a prompt or skips one; it does not
  change what the server allows. They are derived from the access level and the
  tool's name, and an individual tool may override them. `readOnlyHint` is set for a
  `read`-level tool and for a tool with a read verb directly after the group prefix,
  because about two dozen admin-level tools only read (some of them personal data,
  such as form entries and customers; others options and theme mods). That makes it
  a statement about what the registration looks like, not a measurement of what the
  code does, and it is not claimed to be exact. The handlers of the tools it marks
  were read for direct calls that write, reach out or schedule (the code of a
  third-party plugin a read calls into was not exercised for the optional groups,
  and a read that calls WordPress may prime an object cache, which is not counted):
  `wp_get_site_health` was the one that did (it runs WordPress's own Site Health tests, which ask
  api.wordpress.org about PHP and store the answer as a site transient), and it now
  says so itself with `readOnlyHint: false` and `openWorldHint: true`. A
  read that writes is a defect to fix or to override, and the hint does not cover it
  either way. Refusal and two-step confirmation are enforced by the tools, whatever
  the hint says.
- The server's `instructions` text tells the model that content read from the site
  is data and not instructions. That is guidance, not a control: it is the reason
  the guards exist, and it must not be the only thing standing between a comment and
  an action.
- The default role for public registration is checked by construction: a role
  granting anything beyond a subscriber is refused, rather than checking a list
  of capabilities that would never stay complete.
- Changing the administration email takes a confirmation step and is rate
  limited, because it mails an arbitrary address from your domain with body text
  drawn from the site title.
- Menu items refuse draft, private and password-protected targets, since a menu
  item stores its own copy of the title and WordPress renders it regardless of
  the target's status.
- A menu item can be edited rather than rebuilt, and that is harder than it
  sounds. `wp_update_nav_menu_item()` blanks every field you do not name, so a
  rename clears the URL and the item stays in the menu and quietly stops working.
  The obvious repair, reading every field back and writing it all in,
  introduces a second fault: a position of `0` means "not specified, append",
  while the first item of any menu is genuinely stored at `0`, so renaming the
  top item moves it to the bottom. The fields are read from the post row and its
  meta rather than through `wp_setup_nav_menu_item()`, which would turn a title
  derived from the target into a stored one, and the position is pinned on its
  way into the write rather than corrected afterwards, because `menu_order` is a
  field the change journal watches and a correction would have the log describe
  a move that never happened.
- Re-parenting is checked before it is done. An item under itself, an item under
  its own descendant, or a parent in another menu each break a menu with no error
  anywhere, the last by removing the item from the rendered menu while leaving it
  in the database. The walk up the parent chain is bounded as well as checked, so
  a menu that already contains a loop cannot hang the request.
- A new menu can be given its slug. WordPress derives one from the name and
  appends a number when it is taken, and the slug is what an Elementor Nav Menu
  widget stores, so a menu created as `main-menu-2` leaves that widget pointing at
  the other menu and rendering nothing. A taken slug is refused, naming what
  holds it, rather than accepted with a suffix.
- `wp_menu_health` reports what is wrong with the menus and changes nothing:
  items whose target is missing or unpublished, items orphaned by a deleted
  parent, theme locations with no menu, and every Elementor template or page
  whose stored design names a menu slug, saying whether that slug exists. That
  last one turns a silent failure into a sentence, since a widget pointing at a
  missing slug renders an empty nav with no error on the front end or in wp-admin.
- Settings are an allowlist, not a blocklist. `siteurl` and `home` are refused
  outright, since a wrong value makes the site and this endpoint unreachable
  with no way back. A default role that can edit content is refused, because
  open registration plus an editing default role is a way in.
- Those refusals belong to the option, not to the tool that asks. Every one of
  them lived in the settings tool and nowhere else, so naming the same row
  through the generic `wp_update_option` went straight through: the site URL, the
  administration email past the confirmation that exists to protect it, and a
  registration default of `administrator`. Undo was a third way in, since putting
  a value back is still writing it. All three now ask one policy, and a tool
  added later gets the rule by asking rather than by remembering to reimplement
  it.
- There is no token-in-URL endpoint any more, so there is no reduced ceiling to
  describe. It existed for hosts that strip the `Authorization` header, and it
  put the credential in the request path, where every proxy and web server in
  front of the site wrote a copy into its access log, one per request. Measured
  on a development site: 27 copies of a working administrator credential in the
  access log, and none in the plugin's own debug trace. What made it removable
  rather than merely unwise is that the plugin recovers the header from
  `REDIRECT_HTTP_AUTHORIZATION` and `apache_request_headers()`, which is where
  Apache usually hides it.

None of this plugin's own rows are readable or writable through the option
tools, so the bearer token cannot be read back out or overwritten through the
API. Any option name containing `gmcp_` is refused, anywhere in the name rather
than only at the start, which is deliberate: the one-time plaintext of a newly
minted key lives at `_transient_gmcp_new_key_<user>`, and a rule anchored to the
start of the name would miss the row it most needs to catch. It replaced a list
of exact names, which had been wrong twice: the change journal was readable until
somebody named it, and that transient was never on it.

### Diagnostics: the error log, a page as a visitor sees it, and draft preview links

Four tools in the administration group, for the question "why is this page
broken, and what does the client see". Each is a place where an agent is handed
something that looks harmless and is not, so each says what it cannot promise.

- **`wp_get_php_error_log`** (`admin`) reads the end of the file PHP is
  configured to log to and nothing else. There is no path argument, so there is
  no path to validate: the file is whatever `ini_get('error_log')` says, which
  WordPress itself points at `wp-content/debug.log` or at the `WP_DEBUG_LOG`
  path. A configured file is still refused if its name is not log-shaped, if it
  is a script or a configuration file, or if it is a symbolic link, so a
  misconfigured or swapped value cannot turn it into a general file reader.
  A relative `error_log` (a bare `error_log`, which some hosting panels set) is refused
  with a message saying so, and was read before the anchoring existed: PHP opens a
  relative path against a directory that depends on how PHP is run (the script's, the
  server's, `/`), so resolving it against the WordPress root or the content directory
  would show the caller a file other than the one PHP writes, and resolving it against
  the process's working directory would make the answer depend on where the request
  happened to start. The site owner makes it readable by setting an absolute path or
  `WP_DEBUG_LOG`.
  The file is read through one handle that was checked after it was opened, and the
  path is never consulted again:
  the path is looked at without following a link (`lstat`); it is opened non-blocking
  (`fopen` mode `n`, so a FIFO swapped in after that look returns at once and is
  refused instead of holding the worker in `open()` for good: measured, a blocking
  open does hang); the handle must be a regular file with the device and inode that
  were looked at; the path is looked at again and must still be that file; and, where
  `/proc/self/fd` exists, the kernel is asked where the open file really is and the
  answer must equal the path the log is configured to live at, which is what catches a
  directory above the log swapped for a link (the other checks cannot see that: the look
  and the open both walk the swapped directory and agree). A log under the content
  directory or the WordPress root is anchored to that root and not canonicalised as a
  whole, a directory below the root that is a link is refused, and `.` or `..` in the
  configured path is refused. A directory on the way that other accounts can write to
  (world-writable without the sticky bit, or group-writable by a group the web user is
  not in) is reported in a `warnings` field and not refused, because refusing would
  disable the tool on shared hosts for a condition the checks already catch when it is
  acted on.
  What remains, and it cannot be zero in PHP, which has no `O_NOFOLLOW` and no
  `openat`: (1) the window between the first `lstat` and `open()` is narrowed, not
  closed, and whatever is opened in it is refused only after the open. A swap to a
  device or other special file is opened (non-blocking) and refused, so a device whose
  open has side effects is still opened once. (2) A HARD LINK to a file on the same
  filesystem put at the log's path is a regular file with its own directory entry, and
  nothing here can tell it from the log; measured in the race test, the canary is returned whenever the link is in place.
  On Linux with `fs.protected_hardlinks` on (the default on current kernels), an
  unprivileged account cannot make one to a file it does not own or cannot read and
  write. (3) Where `/proc` is unavailable (not Linux, or hidden from the process) the
  open file's location cannot be confirmed; the chain of directories is looked at again
  after the read and the lines are discarded if it no longer holds, which narrows a
  swapped directory and does not exclude it; that path has not been exercised here. (4)
  A log outside both roots (`/var/log/php/error.log`) has no anchor: its directory is
  canonicalised once and trusted from there up. (5) A site where an attacker can write
  to the log directory is outside what this protects; the checks make a swap visible and
  refuse it, they do not stop an attacker who owns the directory from trying again.
  `.dev/stress-log-race.php` races a swapper against the reader (directory swapped for a
  link, the log swapped for a link to a canary file, a pipe, a hard link) with the logic
  this replaced as a control: under the directory swap the old logic returned the canary
  and the new one never did; the pipe made the old logic block in `open()`; the link
  swap was already refused by the old inode comparison, and is a control that the new
  reader has not lost it; the hard link is measured and not asserted.
  It reads from the end of the file, at most 256KB of it, so a multi-gigabyte log
  costs what a small one does. It has no search and no pattern argument; the model asks for the last
  N lines and filters them itself.
- Every line is scrubbed before it leaves: values under credential-shaped names
  (the same `gmcp_credential_field_patterns` list the audit log and journal
  use), bearer and basic credentials, user information in URLs, JWTs, password
  hashes, this plugin's own keys, long opaque tokens, the value in a
  `define( 'DB_PASSWORD', '...' )` of a credential-named constant, `pw=...`, AWS
  access key ids and 40-character AWS-style secret keys, e-mail addresses, public IP
  addresses (loopback and private ones stay, because `connect to 127.0.0.1` is
  the diagnosis), the argument values in stack frames, and the values in a failed
  SQL query past its first 120 characters. Also: a `Cookie` or `Set-Cookie`
  header to the end of its line, and the WordPress login, secure-login and
  post-password cookies, PHP session id and the commenter-details cookies by name
  wherever a dump prints them; credentials in any `scheme://user:password@host`
  address (an empty user name and a password containing `@` included; a password
  containing a literal `/` is not a valid URL and is not covered); the same
  credential-named values inside JSON that is itself inside a log line, where the
  quotes arrive escaped, or the whole object is percent-encoded inside a URL
  (`%22access_token%22%3A%22...%22`); an element named like a secret, including one whose
  content is a CDATA section; values in curly quotes, an escaped quote inside a quoted value,
  and the fullwidth `＝` and `：` as separators (not covered: a value split across two lines
  or arguments, base64 of a credential, and a secret that is written with no name in front
  of it); keys with a fixed vendor prefix (Stripe, GitHub, Slack,
  SendGrid, Google API, GitLab, Mailgun); a private key block, one line of which is
  withheld along with every line up to its end marker (a missing end marker ends at
  the next log record or after 100 lines; a line that closes one block and opens a
  second is still inside a key), and any line that is nothing but 40 or more
  base64 characters, which is what remains when the window starts inside a key.
  Also, because a dump does not print `name=value`: PHP serialized strings
  (`s:9:"user_pass";s:7:"...";`; a value with a quote inside it is cut at the quote and
  the rest stays), an element named like a secret and closed on the same line
  (`<Password>...</Password>`; one left open or spread over lines is not recognised), the
  value on the line after a name that ends in `=>` (what `var_dump`, `var_export` and
  `print_r` print for a nested value, unless the next line opens an array or object), a
  database client's `-pPASSWORD` (only after `mysql*` and `mariadb*` commands; `-p` alone
  is a prompt and is left), `--password VALUE` and the like for option names that are a
  secret by themselves (`--password`, `--token`, `--api-key`, and so on; the `=` form is
  the name rule's), URL-encoded assignments (`token%3D...`, `%26password%3D...`, double
  encoded too), and an Azure shared access signature's `sig=` value. A name that merely
  contains a credential word is already redacted when it is followed by a separator
  (`tokenizer: ok` reads `tokenizer: [redacted]`), which is deliberate and over-redacts.
  Public certificates are not withheld. Paths are shown as `[content]/` and
  `[abspath]/`. This recognises shapes. A secret written into an ordinary
  sentence survives it, and so does personal data in a message PHP assembled from
  a form, which is why the tool is `admin` and not `read`. A line is cut at 2000
  characters before any rule runs, and the rules are timed on pathological lines
  with the regular-expression JIT on and off (`.dev/test-diagnostics-util.php`).
- The reply distinguishes the cases that all look like "nothing to see":
  logging is not going to a file at all, a file is configured and does not exist
  yet, it exists and is empty, it has lines. It also reports `WP_DEBUG`,
  `WP_DEBUG_LOG` and `log_errors` next to the answer, and says when the newest
  entry is a week old and logging looks off. "Empty" is a statement about a
  file, and the reply says it is not proof that logging is on.
- **`wp_fetch_page`** (`admin`) makes the server request one URL of its own site
  and reports status, redirects, title, robots signals, canonical and a bounded
  excerpt of the visible text, optionally counting an expected string. It is the
  only tool here that makes an outbound request, so it is built around the one
  way that goes wrong. The URL must have the same scheme, host and port as the
  site's address and a path inside the site's own path; it must be plain ASCII
  with no backslash or user information, and the URL actually sent is rebuilt
  from the parsed parts, never the string supplied, so a parser disagreement
  between PHP and the HTTP client has nothing to exploit. The host is resolved
  here, every address returned must be acceptable (a name with one public and one
  internal record is refused), and the connection is pinned to the address that
  was checked, so the answer cannot change between the check and the connect. Only
  cURL can pin, so the tool asks WordPress's HTTP layer which transport it will use
  and refuses, before anything is sent, when that is not cURL (the others resolve the
  host name again when they connect). A plugin that swaps the transport for one request
  is caught again in the last hook before the transport is chosen, which aborts the
  request; a response that still arrives with no pin applied (another plugin
  short-circuits the HTTP API) is discarded. Both hooks are live for exactly the one
  request this tool makes and act on every request made in that window; neither
  compares the URL it is handed with the one that was checked, because the HTTP
  library rewrites the address (it upper-cases percent escapes) before the hooks run,
  and a comparison made the guard skip any lowercase escape: a transport swapped to
  something other than cURL then sent `/caf%c3%a9/` and only the answer was discarded.
  The URL that is checked is also spelled the way it will be sent (escapes in upper
  case). A pin that cURL refuses to apply aborts the request before it is sent. Measured
  with a recording transport that really sends: a lowercase-escape URL reached the site
  once before this and zero times now, and the plain-path control was refused before
  and after. The branch where `curl_setopt` itself fails was not exercised (cURL accepts
  the options on any valid handle), so that abort is read, not measured.
  Link-local, reserved
  and multicast addresses are refused for every site, which is where the cloud
  metadata service lives. Private and loopback addresses are accepted only when
  the site's own host name is local (`localhost`, a bare name, an IP in those
  ranges, `.test`, `.local` and similar); a production host name that resolves
  privately is refused unless the site opts in through `gmcp_fetch_allow_private`.
  Redirects are followed by hand, up to five, and each hop goes through the same
  check, so a redirect off the site, to another port or to a metadata address is
  reported and not followed. No cookies and no credentials are sent. The body is
  cut at 1MB and only HTML is read.
- Why `admin` and not `read`: the request originates from the server, so it
  arrives from the one address a site's own IP allowlists and loopback-only
  handlers trust. `wp-admin`, `wp-json`, `wp-login.php`, `wp-cron.php`,
  `xmlrpc.php`, any other `.php` file (and `index.php` with anything after it,
  which is how WordPress is reached as `/index.php/wp-json/...`) and a short
  list of query parameters that change state on common plugins (`add-to-cart`,
  `wc-ajax`, `action` and others) are refused. The comparison is made on what a
  server would route, not on the text that was sent: the path is decoded and
  refused if it carries an encoded letter, digit, hyphen, underscore or tilde, an
  encoded dot, slash, backslash or NUL, or a second layer of encoding (so
  `/wp%2Djson/` and `/wp-login.ph%70` are the plain request), and a query key is
  compared as PHP will name it (`rest.route`, `rest+route` and `rest[route` are
  all `rest_route`; `gmcp.preview` is the preview parameter). That list is a list. A front-end route on this site whose GET has
  a side effect and an unfamiliar name is still reachable, and the tool says so
  in its description rather than implying otherwise.
- Page text is somebody else's words. Everything under `page_content_untrusted`
  was written by whoever controls the page, and the reply says to treat it as
  data. Elements marked `hidden`, `aria-hidden` or `display:none` inline, and
  scripts, styles and templates, are left out so the excerpt is what is shown
  and not what is merely present; a page that hides text with a CSS class cannot
  be seen through from here and is not claimed to be.
- **`wp_create_preview_link`** and **`wp_revoke_preview_links`** (`write`)
  give someone with no account a way to read ONE unpublished post. A link is
  `?p=ID&gmcp_preview=TOKEN` (`page_id` for a page). The token is 160 random
  bits, shown once; the site keeps only its hash, the post id and an expiry in an
  option whose name contains `gmcp_`, so every generic option tool refuses it and
  an agent cannot lengthen a link by editing the row. Stored rather than signed,
  because a signature cannot be revoked, carries whatever lifetime it was signed
  with, and would bring every old link back when a published post is reverted to
  a draft.
  Ending every link on the site (`all: true`) needs an admin-level key, because it
  also ends links other keys and other people made; every key acts as an
  administrator user, so the user recorded with a link cannot tell keys apart and the
  access level is the cut that exists. A key below admin can still end the links for
  one post it can edit.
- Verification happens on the front end, on the MAIN query only. `pre_get_posts`
  accepts the token only when the request is already a single-post request for
  exactly the post the token was issued for, and only then lets that query
  return a draft, pending or scheduled post. `posts_results` empties the result
  unless it is exactly that post in one of those states, and `template_redirect`
  checks the queried object and expiry again before sending `X-Robots-Tag:
  noindex`, `Referrer-Policy: no-referrer` (the token is in the URL, and the
  Referer header would carry it to every external image on the page) and
  no-store headers, and switching off canonical redirects that would drop it.
  The statuses it can ever show are `draft`, `pending` and `future`: on a
  published, private or trashed post the query matches nothing and the answer is
  a 404, and publishing, trashing or deleting a post deletes its links so
  reverting does not revive them. Lifetime is 24 hours by default, at most 168.
  Feeds, embeds, trackbacks and the REST API never honour a token.
- The link is a bearer credential and it travels in a URL, where no field name
  marks it secret. `GMCP_Core::redact()` therefore scrubs the `gmcp_preview`
  parameter (in any spelling PHP maps to it: `gmcp.preview`, `gmcp+preview`,
  `gmcp[preview]`, percent-encoded characters in the name or the `=`) out of every
  string it is handed on the logging paths, which is what the audit log's
  arguments and the debug trace both use, and the audit log applies the same
  scrub to the reply it keeps. A credential written as an assignment inside a
  string is handled by channel, because the right answer differs. The debug log
  blanks any value after a name that field_looks_secret() flags (see below): over-
  redacting a log is acceptable. The audit log and the journal do not use that rule,
  because it turned `To reset your password: click the link we sent. Our author:
  Jane Doe.` into `password: [redacted] the link we sent. Our author: [redacted] Doe.`
  in a record of what a post said. They use `GMCP_Credential_Scan`, which flags an
  assignment only when its VALUE looks like a credential, and the audit arguments
  blank just the span it found. It is structural, not a guess about content: a bare token with no key in
  front of it and no known prefix is not recognised, and nothing says it is. It does not scrub what a
  tool returns to its caller: a stored theme mod or field that happens to contain
  the text is the site's own
  data, and the readers pass `scrub_preview = false` so it comes back as stored. The
  debug trace prints a call's arguments through `log_call_args()`, which starts from
  `redact_call_args()`, the same answer the audit log gives, so a value under a
  credential-named option or meta key is not printed there either. The name of the row
  itself (the top-level `key`, `meta_key` or `option_key`, which names a row and does not
  authenticate to anything) is printed under the labels `target`, `meta_target` and
  `option_target` unless its own text is credential-shaped, and then it is hidden; the audit
  arguments and the approval view make the same decision about it. A `key` nested inside a
  settings value, and every other argument named like a secret, are still redacted by name. Every other value the trace writes that a client sent (the
  tool name, the client's own name and version, a requested redirect URI, a grant
  type, an exception message, the reason an authorize request was refused) goes through
  `GMCP_Core::log_text()`, and a session id is written as its first eight characters.
  `log_text()` is the widest scrub in the plugin because a log is the channel nobody is
  told about before reading it. First it turns every kind of whitespace (tab, newline,
  carriage return, vertical tab, form feed, U+0085, U+2028, U+2029, the no-break space and
  the other fixed-width spaces) into one plain space, before any scrub reads the line:
  a newline in a request value used to be written as a second line, so a client could forge
  `[Guarded MCP]` lines, and escaping it to the two characters backslash and "n" first meant
  that `smtp_pass<TAB>=<TAB>x` and `Bearer<TAB>x` slipped past the separator rule and the
  Bearer rule, which is the defect this order fixes (it reached the 30-character query and
  message previews, the redirect URI, the scope, the client name and version, and exception
  messages). Any other control character is shown as a visible escape. Then it runs the line
  through the same scrubber the error-log reader uses (vendor key prefixes, PEM blocks, URL
  user information, JWTs, escaped JSON, `define()` lines, `pw=`), and takes the whole value
  after a secret-named key: an unquoted one up to `&` or the end of the line, a quoted one up
  to its closing quote, so `smtp_pwd=correct horse battery` no longer leaves `horse battery`.
  A backslash and the character after it count as one inside a quoted value, so an escaped
  quote (`'ab\'cdef'`) does not end the value and leave its tail (measured for the debug log,
  the error-log reader and the scanner). It reads at
  most 2,500 bytes of a value, and a call's arguments only up to a byte budget of four times
  that, whatever number of leaves they come in (the redaction walk used to cost about as much
  per leaf as per byte: a million one-character strings took 2.5 s, a million assignments 17
  s with the JIT off; now 0.1 to 0.4 s, measured in `.dev/test-redact-timing.php`). When PCRE gives up the
  line says it was withheld rather than coming out empty. It is one line of text: a secret
  that is split across two log lines, or across two arguments, is not joined. `GMCP_Logging` writes every line through the same function. Without it the tool that hands the link out would
  have written it into the audit log in the clear in the same call, which the
  first test of it showed. The fetch tool refuses a URL carrying the parameter
  for the same reason: it would be recorded as an argument, and a preview
  credential has no business being fetched by a tool that cannot show a draft
  anyway.
- Nobody can read a token back: no tool returns one after the call that made it,
  there is no tool that lists links, and the stored row holds only the SHA-256 of
  each token, which `wp_get_option` and every other option tool refuse to read at
  any level. Measured with three keys (write, drafts-only, admin) that each made and
  opened a link: the exact tokens were searched for in every column of every audit
  row, the change journal, the whole options table, post meta, the link table and the
  debug log, and in the replies of `wp_list_changes`, `wp_get_audit_log` and
  `wp_get_option`, and were in none of them; the reply that made each one was the
  control and held it. `wp_get_audit_log` is admin-level, so a write-level or
  drafts-only key cannot read the log at all. What is readable: `wp_revoke_preview_links`
  for one post reports how many links it ended and how many remain, to any key that can
  edit that post (every key acts as an administrator, so that is every post). That is a
  count, never a token or an id, and asking destroys what it counts, but it does tell a
  key whether a link existed for a post and ends it, including one made by another key
  or another person. Ending every link needs an admin-level key.
- Not closed, and said so: viewing a link is not logged (only issuing and
  revoking are); the token is in the URL, so the web server's access log, a
  proxy's, the browser's history and any Referer to a third party carry it,
  outside anything this plugin writes or can scrub (the preview response sends
  `Referrer-Policy: no-referrer`, which covers links followed from that page and not
  the logs; the access log of the sandbox server used for the measurement above does
  not record request URIs, so that part was not measured); anyone who is given the link can forward it; a link pasted into a
  post body or a message is outside what the preview scrub can see (the audit log's
  text test now blanks an argument that carries a credential-shaped string, but a
  preview link is not one of the shapes it knows); the
  window between the address check and the connect is closed by pinning only
  where the HTTP transport is cURL. What that rests on, exactly: the transport check
  calls a method of WordPress's HTTP library that is not public (through reflection,
  with the answer "not cURL" if it cannot), another plugin that can still alter the
  cURL handle after this tool has (the pin is applied at priority `PHP_INT_MAX`, so only
  an `http_api_curl` callback registered later at that same priority, or one that
  removes this one, can undo it: code running as the site, which is outside what this
  plugin defends against) can undo the pin, and a host name's pin is one address, the first checked.
  IPv6: the entry for a host name that resolves to an IPv6 address is bracketed
  (`name:80:[::1]`); libcurl 8.5 accepts it and connects to that address, which was
  verified only as far as the connect attempt, because the sandbox it was tested in has
  no IPv6 at all, so no IPv6 page was ever actually fetched. A site address that is an IP
  literal gets no entry (there is no name to resolve); an entry there, `::1:80:[::1]`,
  is rejected by libcurl and failed the whole request before this was changed. A proxy is never used for the fetch: libcurl
  honours `http_proxy` and `HTTPS_PROXY` from the environment, and a proxy
  resolves the host itself so the pin would hold nothing, so the request sets an
  empty proxy and `NO_PROXY=*` on its own handle, and the response is discarded
  unless both were accepted. A site whose WordPress proxy settings
  (`WP_PROXY_HOST`, or the `pre_http_send_through_proxy` filter) apply to its
  own host is refused outright rather than routed around. The reply's
  `connection` field says so only for a response that passed that test.

## WooCommerce

Separate from site administration because the risk is a different shape. The
administration tools can break a site; these read customer names, email
addresses and delivery addresses and hand them to a model, which is a decision a
shop owner should make deliberately rather than inherit.

- Refunds are not included. `wc_create_refund()` moves money through the payment
  gateway, and nothing here can put that back.
- Anything that emails a customer reports who was actually written to. The
  reply names the address if it was the customer's, measured by watching
  `wp_mail` during the change rather than predicting it from the status.
  Predicting it does not work: whether a move to `refunded` mails anyone depends
  on the transition rather than the target status, `on-hold` mails the customer
  and is what shops use for bank transfers, and `cancelled` mails only the shop.
  A hardcoded list was wrong in both directions and would go stale anyway as
  shops add statuses. Setting the status to refunded marks the order and moves
  no money.
- WooCommerce tools carrying personal data are `admin`, not `read`.
  `wp_get_users` is admin and returns no email address at all, so orders and
  customers, which carry names, email addresses and postal addresses, cannot
  sit below it. Products, stock and sales figures stay at `read`. This is not a
  claim about every tool everywhere: `wp_get_comments` is `read` and returns the
  commenter's name, because a comment is published text and its author is on the
  page already.
- Everything goes through the WooCommerce CRUD classes rather than posts and
  meta, because High-Performance Order Storage moves orders out of `wp_posts`
  entirely. A tool built on post queries works on a fresh install and returns
  nothing on most real shops.
- **The generic post tools judge an order by its id, not only by the post row at it.** With
  High-Performance Order Storage an order is a row in `wc_orders`, and `wp_posts` holds a
  `shop_order_placehold` row at the same id (or, on a shop that enabled the storage before
  WooCommerce reserved ids, nothing at all, or an ordinary page that took the id). Every commerce
  guard used to ask the post row's type, so an order with no post row, or with a page at its id,
  was judged by nothing. Measured on WooCommerce 11.2.2 with a write-level key: `wp_update_post_meta`
  wrote a post-meta row against an order that had no post row; `wp_update_post`, `wp_alter_post`,
  `wp_duplicate_post` and the meta writers changed the page that shared an order's id; and an
  admin key's `wp_delete_post` (no `force`) trashed that page, which marked every note of the order
  `post-trashed` so that WooCommerce showed it none (with `force` WooCommerce's own storage check
  stopped the deletion, which is not something this plugin relies on). `GMCP_Core::hpos_order_type()`
  asks `wc_orders` directly (so it holds with WooCommerce deactivated) and `post_chain_types()`,
  `commerce_post_guard()` and the SQL that leaves unreadable rows out (`unreadable_post_clauses()`,
  now given the column that holds each row's own id) all ask it: a post, a post-meta row or a
  comment under such an id is held like an order's, writes at every level and reads below admin.
  What it does not do: it knows WooCommerce's table and no other shop plugin's (another plugin's
  order TYPES are added with `gmcp_commerce_post_types`, its tables are not read), and a
  legitimate page that really shares an order's id cannot be edited with the generic tools (it can
  in wp-admin) until the clash is resolved, which is the price of the rule.
- **An order note is the order's own record.** Notes are comments of type `order_note` on the order's
  id, in either storage. `wp_create_comment`, `wp_update_comment` and `wp_delete_comment` (and the
  preview of the last) refuse a comment on an order's tree, an `order_note` whatever it hangs from,
  and a write that would make a comment one, at every access level; `wc_add_order_note` is how a note
  is added, and it goes through WooCommerce, so it is not held. Measured before this at write level,
  in both storages and on the placeholder row too: `wp_update_comment` rewrote a note's text,
  retyped it to an ordinary comment, and `wp_create_comment` put a comment on the order. Reading is
  held the same way as the order: below admin `wp_get_comments` leaves out comments on an order's
  tree and every `order_note` by type, in SQL, whether or not WooCommerce is there to hide that type
  from comment queries (it does, with a filter of its own that a deactivated plugin takes with it).
  The net under WordPress does not close an order's tree to a comment, because WooCommerce writes
  its own notes while a tool call is in flight; a third-party plugin's comment write is not caught,
  as with the other nets.
- A new product is created as a draft unless you ask otherwise, so a product with
  no price is never briefly for sale.

## Elementor

It exists because doing any of it through the generic post and meta tools
appears to work and does not.

- A header or footer is applied by two rows, not one. Elementor keeps a cached
  registry of which template applies where in an option of its own, separate from
  each template's conditions meta, and saving in the editor writes both. Writing
  only the meta leaves the cache stale, and Elementor declines to rebuild it
  whenever the stored value is already an array: an empty array reads as computed
  with nothing in it, so a header set up that way is invisible forever and no
  amount of reloading the front end fixes it. These tools read both halves, say
  whether they agree, and change them together. Where Elementor Pro's own
  conditions manager is reachable they ask it to rebuild, since that is the call
  the editor makes; otherwise they delete the cached option, because an absent
  value is the state Elementor heals from and an empty array is the one it
  cannot.
- The design itself is `_elementor_data`, a JSON string routinely over 100KB.
  `update_metadata()` unslashes whatever it is handed, so a value that went out
  through a tool argument and came back would lose every escape in it and return
  a broken document. Size was never the only problem, and the corruption is
  silent. `elementor_apply_template` moves it inside PHP so it never leaves the
  server. It defaults to the shortcode form, which keeps the page linked to the
  template so later edits to the template propagate rather than freezing a copy,
  and copies the design only when asked.
- That link can be asked about. `elementor_template_references` answers what
  still points at a template, covering both the shortcode written into a page's
  content and a template embedded inside another page's design, which is how
  Elementor's own widgets do it. The match is exact rather than textual: the
  shortcode is parsed and the design decoded, so template 12 is not reported as
  a reference to template 1. It answers even when Elementor is not active, which
  is when the answer is worth most, because every referencing page has just
  started printing its shortcode as literal text. Applying a template now also
  warns when it is replacing an existing link and when the template is not
  published, since Elementor renders nothing for a draft and the page shows an
  empty space.
- `elementor_kit_report` reads the site's global colours, fonts and layout
  settings from the active kit, and writes nothing at all. That is what makes it
  safe to ship against internals that move between Elementor versions: a version
  change can make the report incomplete, which is a bad afternoon, where a write
  against a changed shape corrupts a site's global styling. It checks the kit it
  was given back is the one it asked for, because Elementor substitutes an empty
  placeholder when the active kit is missing or trashed and that placeholder
  answers every question with the plugin's built-in defaults, as confidently as
  a real kit would.
- Conditions need the theme builder, which is Elementor Pro or PRO Elements. On
  a site with only the free plugin the rows are still written, nothing reads
  them, and the tools say so rather than reporting a success the site will never
  show.
- Deleting, trashing or unpublishing a template that other posts still render is
  refused, naming them. `elementor_template_references` answers the same question
  and refuses nothing, so until this the answer was only as good as a caller's
  habit of asking first. Both routes a reference takes are covered: the
  `[elementor-template]` shortcode, and a `template_id` setting inside another
  page's `_elementor_data`. Trashing counts, which departs from how deletion is
  treated everywhere else here, because from a referencing page's point of view
  a trashed template and a deleted one render identically.
  `despite_references` goes ahead anyway.
- That guard lives in the always-on content tools while the search lives with
  the Elementor ones, and it calls across rather than keeping a copy. Both
  halves matter: a guard that disappeared when the optional Elementor group is
  switched off would not be a guard, since the delete comes from the content
  tools; and a second copy of the search would be free to disagree with the one
  doing the reporting, in the direction of waving through a reference the tool
  can see.
- The kit is where most of "wire up a theme" lives: the site's global colours,
  fonts, layout defaults and theme styles. `elementor_set_active_kit` switches
  it, and does the second half with it. A kit compiles into generated CSS files,
  so switching the option alone leaves every page rendering the old design while
  the tool reports the new one, which is the same silent success the conditions
  tools exist for; the files are cleared here too. The reply names the kit it
  replaced, because that is the only record of what to switch back to: this is
  not journalled and there is no undo. Anything that is not a published kit is
  refused, since Elementor reads the option without checking and an id pointing
  at an ordinary template leaves the site with no usable global styles at all.
- Elementor's internals are not a stable contract across versions, so every call
  into one of its classes is guarded and reports what was missing instead of
  fataling. A wrong guess fails benignly.

## Kirki

It exists for the same reason the Elementor group does: writing a Kirki value
through the generic option tools appears to work and does not.

- Kirki stores each field's value in one of three places, and which one depends
  on the field's `option_type` and `option_name`: a theme_mod (the default), a
  single named option row holding a serialized array, or a standalone option row
  per field. Writing through the wrong one is a silent success: the value lands
  in a row nothing reads, and the front end keeps rendering the old value.
  `kirki_set_field_value` resolves the storage from the field's registration and
  writes through the right path, so the caller does not need to know the model.
- For `theme_mod` fields it uses `set_theme_mod`, which merges one key into the
  `theme_mods_<stylesheet>` array rather than replacing the whole row. A
  full-array replace on that row, which is what `wp_update_option` does, wipes
  `nav_menu_locations`, `sidebars_widgets`, `custom_logo` and every other mod.
  The partial update is the safe primitive.
- For `option` fields with an `option_name` it does a read-merge-write, so a
  sibling field in the same group survives. A full replace on the grouped option
  would wipe every other field in it.
- Every write passes through the same `option_guard` and `option_write_policy`
  as `wp_update_option`, so a field whose resolved option name is `siteurl` or a
  credential-shaped key is refused the same way. The guard is attached to the
  option, not to the tool.
- Kirki generates its CSS inline and recomputes it on every front-end page
  load, so a value written through `kirki_set_field_value` takes effect on the
  next load without an extra step. What can lag is the Google Fonts cache: Kirki
  downloads font files and caches the remote CSS, and changing a typography
  field does not invalidate that cache on its own. `kirki_regenerate_css` clears
  it, and says that a full-page cache is separate and not touched.
- `kirki_export_config` exports the site's Kirki configuration as JSON, with
  credential-shaped values redacted. There is no `kirki_import_config`,
  deliberately: importing rewrites the whole design system from an archive built
  elsewhere in a single call with no restore tool behind it, which is the same
  category the Elementor kit import is refused on.
- Kirki's controls, sections and panels are defined in PHP theme code, not in
  the database, so the MCP cannot add, remove, or modify them. The tools read
  what the theme registered and write the stored values; they do not change the
  registration.

## Yoast SEO

It exists for the same reason the Elementor and Kirki groups do: writing
`_yoast_wpseo_*` post meta through the generic post-meta tool appears to work
and does not.

- Since Yoast 14.0 the front end reads SEO metadata from `wp_yoast_indexable`, a
  derived table, not from `_yoast_wpseo_*` post meta. The post meta is still the
  source of truth — the admin editor writes it, and the
  `Indexable_Post_Watcher` builds the indexable from it — but writing the post
  meta directly leaves the indexable stale. The front end renders the old title;
  the admin meta box shows the new one. A human checking the admin thinks it
  worked; a visitor sees the old value.
- `yoast_set_post_seo` writes through `WPSEO_Meta::set_value()` and then
  rebuilds the indexable by calling the `Indexable_Post_Watcher` directly, not
  by firing `wp_insert_post` and hoping the watcher is hooked. The read-back goes
  through `YoastSEO()->meta->for_post()`, the same surface the front end uses, so
  the verification confirms what a visitor will see, not what the post meta
  holds.
- Computed analysis fields (`linkdex`, `content_score`, `inclusive_language_score`,
  `estimated-reading-time-minutes`) are refused. They are analysis outputs, not
  inputs, and writing them would be the same shape of silent success this group
  exists to prevent.
- `yoast_reindex` rebuilds a single post's indexable. A full-site reindex takes
  minutes on a large site and a tool call takes seconds, so it is refused and
  the tool says to use `wp yoast index` from WP-CLI — the same honesty as the
  backup tool.
- Yoast's internals move between versions, so every call into one is guarded and
  reports what was missing rather than fataling.

## Advanced Custom Fields

It exists for the same reason the other framework groups do: writing a custom
field through `update_post_meta` appears to work and does not.

- ACF stores values in post meta as `fieldname` = value, but also stores a
  hidden `_fieldname` = `field_123abc` (the field key reference). The reference is
  the only way ACF knows the field type, return format, and sub-field
  definitions. Without it, `get_field()` returns the wrong type: a bare ID
  instead of a `WP_Post` for post object fields, a bare attachment ID instead of
  an image array for image fields, a row count instead of rows for repeaters, or
  `null` for options-page fields on ACF 5.11+.
- `acf_set_field_value` writes through `update_field()`, which resolves the
  field definition, writes both the value and the key reference, and runs the
  field-type `update_value` filters that expand repeaters, store image IDs, and
  serialize arrays correctly. Writing an unregistered field is refused, because
  `update_field()` on an unregistered field writes the value without a key
  reference — the silent success this group exists to prevent.
- For options-page writes (`post_id = "option"`), the resolved option name
  passes through the same `option_guard` and `option_write_policy` as
  `wp_update_option`. The `post_id` is restricted to known forms (integer,
  `user_*`, `term_*`, `category_*`, `option`, `options`); arbitrary strings are
  refused because ACF treats any string as an option prefix, which could target
  any option row.
- Credential-shaped fields are refused on write, not just redacted on read. The
  value would be stored in plain text in post meta or `wp_options`, and the
  audit log would record it. This matches the option tools, which refuse
  credential-shaped writes rather than allowing and redacting.
- ACF's internals move between versions, so every call into one is guarded and
  reports what was missing rather than fataling.

## Gravity Forms

This group exists because the generic tools cannot reach the data at all. A form's
schema is a serialized array in `wp_gf_form_meta`; submissions live in `wp_gf_entry`
with their answers in `wp_gf_entry_meta` keyed by input id; notes live in
`wp_gf_entry_notes`. None of `wp_get_posts`, `wp_get_post_meta` or the option tools
touches any of it, so unlike the Yoast or Kirki groups there is no silent success to
fix — there is simply no other way in.

What shapes the design is that a submission is the one record on a WordPress site
that a member of the public wrote, that no administrator typed, and that a business
may be obliged to keep.

- **The bar is set by the effect, not by the tool's name.** `gf_update_entry_field`
  overwrites an answer in place. The previous value is not recorded anywhere, the
  change journal does not read Gravity Forms' tables so `wp_undo_change` cannot put
  it back, and this plugin has no restore tool by design. That is the same
  irreversible loss as deleting the entry, so it takes the same server-minted
  two-step: the first call changes nothing and returns a token, the second writes.
  Attaching the two-step only to tools whose names contain "delete" would leave the
  more injectable operation — "correct this value for me", arriving in the text the
  agent was asked to read — as a single call.
- **A token is bound to the entry, the input and the new value**, so one minted to
  change an answer on entry A cannot be replayed against entry B, and a token for one
  value cannot be used to write a different one. Writing the value an answer already
  holds is a no-op and needs no token, because nothing is destroyed.
- **Only plain answer fields are writable.** The field's type is checked against the
  form the entry belongs to before anything is written. A password field is refused
  because Gravity Forms hands its value back in plain text through this API, so
  writing or returning one is a credential leak into the model and the audit log; an
  upload, a signature, an opt-in/consent field, a repeater, and anything a plugin
  added are refused because changing them changes something the site relies on.
  Writing an entry field also re-runs the form's add-on hooks, so a call is not the
  isolated data edit its name suggests, and the description says so.
- **A password field is never returned on read either**, and the tracking fields (IP,
  user agent, source URL) are omitted unless asked for.
- **Gravity Forms fails open on a filter it does not understand, so the tools fail
  closed.** An unrecognised query operator is silently treated as "match everything"
  (measured), and a date it cannot parse matches every entry rather than none.
  `gf_list_entries` therefore validates the operator against a list, the filtered
  input id against the form, and the date by parsing it and requiring it to
  round-trip — a shape check alone lets `2024-02-30` through, which Gravity Forms
  rolls over rather than rejecting. Each is refused rather than passed on.
- **A value this group refuses to return is not readable through the query filters
  either.** Gravity Forms' own "any field" search compares the term against every
  meta value, including a password field's, and with the total in the reply that is a
  boolean oracle: a caller can test a guess one character at a time. A field filter on
  the same input is the same oracle with one branch. So `search` is rebuilt as a
  match-any over the form's readable answer fields (which is also what makes it a real
  substring search, since Gravity Forms' default is an exact match), `search` requires
  a form id for that reason, and a field filter on a password or credit-card field is
  refused.
- **Deletion is honest about what it does not do.** It removes the entry row, its
  answers and its notes; it does not necessarily remove files the entry uploaded or
  data an add-on stored in another table. There is no restore tool.
- **Access levels follow WooCommerce's reasoning.** A form definition is
  configuration, so reading it is `read`; anything that returns or changes a
  submission is `admin`, because a submission is somebody's personal data.

## LearnDash

A course's structure is a tree LearnDash keeps in course meta and its enrolment is a
per-user meta row, so the generic tools reach neither with any confidence. As with the
Gravity Forms group there is no silent success to fix, only a surface that does not
exist yet.

Two of LearnDash's own names mean something narrower than they read, and most of this
group's design is about not confusing them.

- **"Has access" is not "is enrolled".** `sfwd_lms_has_access()` answers whether a user
  can VIEW a course. It returns true for an open course, for a paynow course with no
  price, whenever the course's join setting is empty (the default, so it is true for
  most courses), for anyone who can auto-enrol such as an administrator, and for a group
  member — all before it looks at the user's own enrolment. Measured on 4.10.3: after
  the direct enrolment was removed, it still returned true. So no tool here uses it as
  an enrolment oracle. `access_state()` reads the enrolment from the user meta that
  records it and reports the other reasons separately.
- **"Enrolled" is not "reachable".** `learndash_user_get_enrolled_courses()` returns
  open courses as well as enrolled ones, and returns every course for an administrator.
  The tool that uses it is called `ld_list_user_courses` and its rows carry the reasons,
  so "reachable" is not read as "enrolled".

What follows:

- **`ld_update_course_access()`'s return value is not the evidence.** It returns false
  both for "already enrolled" and for bad input, so a caller that maps it to
  success/failure reports the opposite of the truth in one of the two cases. Every write
  here is verified by re-reading the enrolment meta, and the reply distinguishes
  "granted" from "already enrolled" and, separately, from "could already reach it".
- **Unenrolling two-steps and refuses off the direct path.** Removing an enrolment
  deletes the direct row but leaves group access, a purchase entitlement and open-course
  access intact, so deleting it and reporting "access removed" would be false. The tool
  refuses when the access is a group membership and names the group. It also states that
  the enrolment-date record survives the removal (LearnDash keeps it for reporting) and
  that re-enrolling writes a new date, so it is not a faithful undo — which is why it
  takes the two-step rather than being treated as reversible.
- **Answer keys are never returned.** A question's correct answers, feedback and hints
  live in LearnDash's pro-quiz tables rather than on the question post; only the
  question text, its type and its points are returned.
- **Progress is lessons and topics.** LearnDash's own course-progress total excludes
  quizzes, so its percentage is described as what it is rather than as course
  completion.
- **A read tool must not mutate, and one nearly did.** LearnDash's
  `ld_course_access_expired()` is named like a predicate and is not one: when access has
  lapsed it writes an expired marker, **deletes the enrolment row**, fires an action and,
  on a course configured to do so, deletes the user's course progress. The first version
  of this group called it from `access_state()`, which every read tool uses, so
  `ld_get_user_progress` — reachable with a readonly key — could delete the very progress
  it was asked to report, announcing nothing and leaving nothing to undo. Expiry is now
  recomputed from the same stored meta without writing, and the smoke suite builds an
  EXPIRED enrolment before reading, because on a live one the function returns early and
  the defect would not show.
- **A group's date window is reported, not collapsed into the guard.** A group whose
  start date has not arrived, or whose end date has passed, still lists its members but
  no longer grants them access under LearnDash's own check. The group guard reads the
  membership list the listing uses, so the two cannot disagree; the live-window flag is
  reported separately.

## Elementor Pro

The smallest group in the plugin, and most of the work was deciding what not to ship.
Elementor Pro adds popups, loop templates, mega menus, display conditions, custom code,
forms and a role manager, and almost none of it becomes a tool here.

**What is here.** `elementor_pro_status` answers "is Pro really loaded, and which modules
can be called", per module by class **and** method rather than from a version string,
because Pro is licensable, can be present with a module switched off, and registers an
autoloader before it decides whether to boot. `elementor_forms_briefing` counts form
submissions.

**Why popups and loop templates are not a second tool.** They are `elementor_library`
posts with theme-builder conditions, so `elementor_list_templates` and
`elementor_get_conditions` already report them, together with the cache agreement that is
the point of those tools. A parallel implementation would be free to disagree with the one
already doing the reporting, and the disagreement would be invisible.

**Why submissions are counted and not returned.** Elementor Pro creates its submissions
tables on demand, on the first submission. Until then its own query layer returns an empty
page with a total of 0 — measured on 4.1.2 — so "no submissions" and "the feature has never
run" are indistinguishable from the numbers alone. A row reader would therefore report a
confident zero on every site that has never received one. On top of that, a submission is a
visitor's personal data, and a reply containing one is copied into the audit log's `detail`
column, which outlives the reply the model saw. The briefing checks whether the tables
exist before it counts, and says which of the two answers it is giving.

**Why there is no Custom Code tool, read or write.** A snippet's body is stored in post
meta and echoed **unescaped** into the page on every request for every visitor
(`Document::print_content()`). A write tool is therefore arbitrary persistent script
injection across the whole site, with no restore tool and no journal coverage behind it,
and a read tool for the same field is the reconnaissance step for it. The status tool
inventories snippets — title, status, location, priority, size and a SHA-256 of the body —
which is what an operator needs to find the one misbehaving, and never the code. Whether a
snippet renders at all also depends on the theme: Pro prints a location by calling
`elementor_theme_do_location()`, which a block theme such as Twenty Twenty-Five does not
do, so a snippet can be stored, enabled and bound and still not appear.

**Its own switch.** Pro tools are not part of the Elementor group. That group is switched
on sites running free Elementor, so Pro entries there would be entries that fail the moment
a model tries one. Registration is not gated on Pro being loaded, for the reason the
Elementor group's own carve-out documents: the status tool exists to answer "why is Pro not
working", and a gate inside a class that is never constructed cannot run.

## WooCommerce Subscriptions

A subscription is a `WC_Order` subclass, and that single fact is what this slice is
arranged around. It means the shop's own order tools resolve one — which is why the first
commit in this slice is a fix to shipped code rather than a new tool — and it means a
subscription's parent order and its renewal orders are ordinary `shop_order` rows that
WooCommerce Subscriptions also reacts to.

**The writes that were already reachable.** `wc_get_order()` returned a `WC_Subscription`
for a subscription id, and `WC_Abstract_Order::set_status()` silently rewrites any status
that is not valid for a subscription to `pending`, so `wc_update_order_status(sub,
"completed")` stored `pending` and replied "moved from active to completed". Measured:
before `wc-active`, reply claimed completed, after `wc-pending`. Cancelling a *parent order*
moved its subscription from active to pending-cancel. Both were reachable with a `write`
level key, on shops that never switch this group on. The guards are
`GMCP_Tools_Woo::order_or_null()` (type), `update_order_status()`'s subscription-link check,
and `GMCP_Core::commerce_post_guard()`, which the generic post and meta writers ask — the
same "a policy belongs to the object" rule as `option_guard()`.

**What is deliberately absent, and why each one.**

- **No tool charges a customer.** A renewal charge moves money through a gateway, and
  nothing this plugin has can put it back. Same category as the excluded refunds.
- **No tool changes the next payment date.** This is the one that looks harmless.
  WooCommerce Subscriptions validates a date change for ORDERING only — after the start and
  trial end, before the end — and never for whether it is in the future. A past date is
  accepted, the payment job is rescheduled to it, and the next queue run charges. An empty
  value deletes the date instead, which stops the subscription renewing ever again. One
  argument, two ways to hurt a shop.
- **No immediate cancellation.** `cancelled` is terminal: `can_be_updated_to('active')` has
  no path out of it, so the customer would have to check out again, and on a gateway that
  manages its own billing the agreement ends there irreversibly. `pending-cancel` is the
  state for "the customer asked to stop", and `on-hold` is for "stop charging now".
- **No creation or resubscription.** Both create a billing agreement the customer did not
  enter.

**The one write, and its guards.** `wcs_set_subscription_status` offers exactly
`active ↔ on-hold` and `active ↔ pending-cancel`. The allow-list is narrower than
`can_be_updated_to()` on purpose: that function runs filters, so a third-party plugin can
widen it, and it accepts aliases — `completed` maps onto the active branch, which would run
the reactivation code and then store `pending`. Two of the four transitions are also refused
for a particular subscription: reactivating one with an unpaid **renewal** order, because it
restores service and cancels the retry without collecting the debt; and reactivating a
pending-cancel one whose original end date was not recorded, because a fixed-term
subscription would come back with no end. The unpaid-renewal test reads renewals rather than
`needs_payment()`, which is also true for a subscription whose parent order is merely
pending — the normal state after a fixture is created, and not a debt.

Whether going `pending-cancel` can be undone from this plugin is **computed**, not assumed:
on a gateway that schedules its own payments the agreement can end there, and WooCommerce
Subscriptions will then refuse to reactivate. The confirmation token is bound to the
outcome (`id:from>to` plus the values a reactivation recalculates), not only to the
subscription, so a token minted for one of the four changes cannot complete another.

Verification is against stored state read fresh, never the return value:
`update_status()` returns true or throws, a hook failure inside the transition is caught by
WooCommerce Subscriptions and written as a note while the call still returns true, and the
reply reports those notes and every address emailed.

**PII.** The reads return counts and state only. A subscription carries the customer's
billing email and address, line-item names an admin can edit, notes with decline text, and
payment-method meta with gateway customer ids; all of it lives on the same object and the
WooCommerce order tool already returns it at admin level. read-level tools do not, so a
shop's recurring revenue can be read without moving personal data through the model.

**What the suite cannot test.** The fixture renews manually, because that is how a
subscription is built without a payment gateway, and a manual subscription reports every
gateway feature as supported. So the gateway-dependent branches are unreachable here, and
the suite says so rather than implying coverage it does not have.

## Redirection and Polylang

**A redirect is two primitives in one row.** Pointed at a foreign host it is an open
redirect: the site's own reputation, and its own URLs in a phishing mail, in front of
someone else's page. Pointed from `/wp-login.php` or `/wp-admin/` it takes the operator's way
back in. The agent that would write one also reads comments and post bodies written by
strangers, so the policy is in the tool and is not a setting. A target must be a path on this
site or a URL on its own host; another host is accepted only over `https`, by exact name, if
the `gmcp_redirect_allowed_hosts` filter lists it (empty by default; entries that are not a
bare host name are dropped, so a filter returning `*` matches nothing). Refused outright:
`//host` and `/\host` (browsers read both as another site), tabs and newlines (browsers strip
them from inside a URL), userinfo before the host, and a non-ASCII host. In a
regular-expression redirect a `$1` that starts the target is the classic open redirect (the
request `//evil.com` is copied in), so a capture is accepted only after a fixed first path
segment.

**The target is what `wp_redirect()` will send, not what was typed.** Redirection hands the
stored target to `wp_redirect()`, whose `wp_sanitize_redirect()` DELETES every character outside
`a-z 0-9 - ~ + _ . ? # = & ; , / : % ! * [ ] ( ) @`. Deleting is an open redirect: `/^/evil.com`
begins with a single `/`, passes a check of its first two characters, is stored unchanged
(Redirection's `sanitize_url` only strips newlines and control characters), and leaves
`wp_redirect()` as `//evil.com`; the same for `'` `"` `{` `}` `|` `$` `` ` `` `<` `>` and `%0a`/`%0d`
(`/%0a/evil.com`). A target is therefore accepted only if `wp_sanitize_redirect()` leaves it
unchanged, and the `//`, host and scheme checks then run on exactly that text. The rule is in
`problems_for()`, so create, update, enable and the read-back of a stored row all ask it. A
non-ASCII path must be percent-encoded for the same reason (the sanitiser would encode it).
In a regular-expression redirect `$1` is replaced before `wp_redirect()` sees the target, so
it is stood in for with a digit when the rule is applied.

**Sources.** A literal path, compared after percent-decoding to a fixed point, lower-casing,
and rejecting `.`, `..` and empty segments; the site's subdirectory and a leading `index.php`
are peeled off before the protected list is applied, and the protected paths are taken from
WordPress (`wp_login_url()`, `admin_url()`, `rest_url()`, this plugin's endpoint) rather than
hard-coded, so a moved login page is covered. `/wp-cron.php` and `/xmlrpc.php` are protected
too: neither is a page, and a redirect on either stops scheduled posts, updates and
WooCommerce jobs, or remote publishing, with nothing on screen. A `rest_route` query is refused
on any path.
Regular-expression sources are off by default because a pattern cannot be proven not to cover
the login page; when a site allows them, the pattern is tested against the protected paths
and refused if it matches one. That test is best effort and is the reason the default is off.
What it knows about Redirection's own handling of a pattern (read in 5.10.1): the source is
`rawurldecode`d once when it is saved (`Red_Item_Sanitize::sanitize_url`) and the stored form is
`rawurldecode`d again every time a request is matched (`Red_Regex::__construct`), then wrapped as
`'@' . pattern . '@s'` with an `i` appended only when the redirect ignores case (not `'@..@i'`).
So `^/wp-login%255c.php` is stored as `^/wp-login%5c.php` and matches `/wp-login.php`, and a probe of
the text as typed, or of the stored text, never sees it. A `%` in a regular-expression source is
therefore refused, the decoded forms are probed as well (for rows made in the admin screen), both
wrapper flavours are used, and the probes include query-string variants
(`/wp-login.php?a=1`) and the cron and XML-RPC paths. A backslash is refused in any source, so a
literal dot is written `[.]`, not `\.`.

**The policy belongs to the redirect, not to the call.** It is evaluated on the stored row
after every write, and a row that fails is switched off, because Redirection sanitises what it
is given and the request was only ever a claim. Enabling an existing redirect evaluates it
too: a redirect written in the Redirection screen to a foreign host stays off through this
tool. Disabling asks nothing. Only the plain URL match and redirect action are managed,
because match types that depend on the visitor (agent, referrer, cookie, IP, login status)
are how a redirect is shown to a crawler and hidden from the owner, and an update that
rebuilt one would drop its conditions without saying.

**Facts about Redirection's API that these tools were corrected against (5.10.1).**
`Red_Item::get_filtered` clamps `per_page` to at least 5 and offsets by `page * clamped size`, so
asking for 3 returned 5 and page 2 started at row 10; the list now asks only for sizes it
honours and cuts the requested page out locally. `to_json()` reports a redirect nobody has
followed with `last_access` of `-`, which is reported as `null`. `Red_Item::create` honours
`enabled => false`, so a redirect meant to be off is inserted off and not disabled afterwards
(that left it live in between). `Red_Item_Sanitize::get_position()` answers 0 when no position
is given, so an update carries the stored position over instead of moving the redirect to the
front of its group.

**No delete.** Disabling is the reversible removal and loses nothing. A deletion also loses
the hit count and cannot be undone, and no task needs it. Adding one later means giving it
the two-step every other irreversible tool here takes.

**Not covered:** the change journal (the rows are in Redirection's own tables), a duplicate
source (Redirection decides what a second redirect on the same path means), and a redirect
whose Redirection group or module is disabled, which stays inert regardless of its own state.

**Polylang is read only, and `log_state`-style honesty is the point.** Three answers look
the same as an empty list and are different facts: Polylang loaded with no languages
(`not_configured`), a post with no language or of a post type Polylang does not manage, and a
post with a language and no siblings (`no_translations`). Linking posts or setting a language
is not offered: it changes which page a visitor is shown and which URL is the original, and
nothing here could undo it. The translation group can name drafts and private posts, so that
tool is `write`, and it returns IDs, languages, statuses and post types but no titles.

**WPML is deferred.** Its translation groups live in its own table behind filters rather than
functions, its "not configured" state has several shapes, and it could not be installed to
measure. A reader written from memory is how a confident "no translations" is produced.
## Rank Math and All in One SEO

How those plugins store their data was written from memory of their source, and has since been
checked by running `smoke-seo.sh` against Rank Math 1.0.281 and All in One SEO 5.0.5: the post
meta keys, the robots array and AIOSEO's table and robots columns are as described. Anything
still marked UNVERIFIED in `includes/tools-seo.php` was not re-read. (Two checks of the suite
simulate a missing table with `RENAME TABLE` and cannot pass on a SQLite stack.)

- **The two plugins keep the data in different places, and that decides how each is
  written.** Rank Math keeps per-post SEO in ordinary post meta (`rank_math_title`,
  `rank_math_robots` and so on) and reads it from there, so a meta write is its own write and
  there is no stale index of the kind Yoast keeps. All in One SEO keeps it in its own table,
  `{prefix}aioseo_posts`, which no generic tool reaches, and which the change journal does
  not watch.
- **So an All in One SEO write cannot be undone with `wp_undo_change`, and the reply says
  so.** It carries the previous value of every field it changed, which is the only copy.
  A Rank Math write is journalled, with one exception worth knowing: the journal withholds
  the old value of any meta key whose name looks credential-shaped, and
  `rank_math_focus_keyword` contains the word "key". Its old value is in the reply and
  nowhere else. The argument is called `focus_phrase` for the same reason, so the audit log
  does not redact it.
- **Every write is read back from storage and fails if it differs.** Rank Math rows are read
  straight from `wp_postmeta`, not through the cache `update_post_meta()` has just refreshed;
  the All in One SEO row is read from its table, not from the model that was just saved.
  What is not checked is the rendered page, a page cache or the sitemap, and the reply says
  so rather than implying it.
- **`noindex` and a canonical URL are higher impact than a title, and the writers are still
  `write` level.** The reasoning, which is the thing to attack: for Rank Math the same effect
  is already one `wp_update_post_meta` call away at `write`, because the generic meta tool
  has no policy on `rank_math_robots`, so a higher level would move the request to a tool
  with fewer guards and not remove it; and a key that can edit a post's content and publish
  it can already do more to its search presence than any field here. The honest remainder is
  All in One SEO: its table is reachable only through this group, so a `write` key gains a
  capability it did not have. That is accepted, not denied. What narrows it: `robots` is a
  three-way enum (`index`, `noindex`, `default`) with no free-text directives, `index` and
  `noindex` leave the other robots directives on the post as found (Rank Math) or switch the
  row's other flags on from a clean slate (All in One SEO, below), and a canonical must be on this site
  unless the site names another host through `gmcp_seo_canonical_hosts`. A canonical on
  another host hands the page's ranking to that host, which is the one field in the group
  with an attacker-shaped use.
- **`default` means different things, and the reply says so.** Rank Math falls back to the
  post type's setting only when the stored robots array is EMPTY (checked in its
  `Paper::get_robots()`), and its `validate_robots()` adds `index` to any non-empty array that
  lacks one, so a stray `nofollow` left behind would pin the page to `index` instead of handing
  the decision back. `robots: default` therefore removes every robots directive on the post and
  the reply names what it removed, and the reader reports `index` for a stored set with no
  `noindex`. In All in One SEO an explicit `index` or `noindex` sets `robots_default` to 0, which
  makes the plugin read the row's other robots flags (`robots_nofollow`, `robots_noarchive`,
  `robots_nosnippet` ...), so a flag that did nothing while the row followed the site-wide
  setting would start to apply; going from default to an explicit decision resets them to off and
  says which were set. A row that already made an explicit decision keeps its flags.
- **The commerce post guard and the drafts-only guard apply** (`GMCP_Core::post_write_guard()`),
  and are asked before the "is this a public post type"
  check, so on a site where a filter has made an order type viewable the policy about the
  object is still what answers. The drafts-only half is the only thing holding a drafts-only key
  on All in One SEO: it writes its own table through its own database layer, which no hook of
  the net under WordPress sees. A refusal thrown by that net is reported as the policy's sentence,
  not as "the SEO plugin threw an error". No tool here writes an option, so the option write policy is
  not bypassed because it is never reached; site-wide SEO settings are options and stay
  behind it.
- **No nofollow, noarchive, snippet-length, schema or redirect controls, and the All in One
  SEO focus keyphrase is read but not written.** Each of the first is another way to change
  how a page is shown that no fix needs. The keyphrase lives in a JSON column the model
  encodes itself; writing a string there would store a double-encoded value the plugin then
  fails to parse, and a read-back that catches it would catch it after it was stored.
- **A text field is not run through `sanitize_text_field()`.** It deletes every `%xx` pair,
  and both plugins' template variables are percent-delimited, so `%date%` would be stored as
  `%`. Tags are stripped and whitespace collapsed instead.

## Form submissions (Contact Form 7, Fluent Forms, Ninja Forms)

Read only, and the absence of a write is the design. As with Gravity Forms, a submission is
the one record on the site a member of the public wrote. Unlike Gravity Forms there is no
tool here that edits, trashes, marks as spam or deletes one, because nothing in this plugin
can verify such a write against these three plugins and there is no restore tool behind it.
The storage of Contact Form 7 + Flamingo, Fluent Forms and Ninja Forms was checked against the
real plugins (Contact Form 7 6.2.4.1, Flamingo 2.6.4, Fluent Forms 6.2.17, Ninja Forms 3.16.0)
by running `smoke-forms.sh` on them; two of its checks (the ones that simulate a missing table
with `RENAME TABLE`) cannot pass on a SQLite stack, which does not perform that statement, and
need MySQL or MariaDB. Items still marked UNVERIFIED in `includes/tools-forms.php` were not
re-read.

- **The three briefings are `read` and return counts only; every tool that returns a
  submission is `admin`.** Same split as `gf_forms_briefing` against `gf_list_entries`. A
  read-level key additionally gets a recency bucket for "newest" rather than a time.
- **Every reply carries a `log_state` block, because a zero from these plugins has four
  meanings.** None arrived; the plugin that stores them is not there (Contact Form 7 alone
  stores nothing at all, so a count from it is meaningless); a table was never created; or a
  form is set not to store. The block says which, and when there is nothing readable the
  counts are `null`, not `0`. A failed query is never read as an empty result: the query
  helper returns the database error as a string, and a caller that mistook it for a list of
  zero rows would be the false "none" this block exists to prevent.
- **What is returned of an answer is an allowlist built from the form's own definition.** A
  value comes back only if the field is in the form's current schema, its type is a
  plain-input type, and its name does not look credential-shaped by the same
  `GMCP_Core::field_looks_secret()` the journal and the audit log ask. Password and
  file-upload fields fail by not being on the list, and are named in a `withheld` list so the
  absence is visible. A field that was answered but has since been removed from the form is
  withheld, because nothing says what it was. If the definition cannot be read, no answer is
  returned. Two details of Contact Form 7 matter and were found by reading its source and
  Flamingo's. The form a message belongs to is found through the form post's own
  `_flamingo` meta (`['channel' => term id]`, written when the form first stores a message),
  not through the channel's slug: a slug is only the form's name when the term was made, and
  `get_page_by_path()` on it picks a different form once one is renamed and another takes the
  old name, whose field types would then decide what is returned. The slug is used only for a
  form with no channel link at all (an imported or hand-made message), never for one linked
  elsewhere. And Flamingo stores each answer under `sanitize_key( '_field_' . name )`, which
  lower-cases, so the allowlist is keyed the same way: a field named `Your-Name` is returned
  and one named `API-Key` is still withheld as credential-shaped. Before this, a capitalised
  name matched nothing and its answer was withheld for the wrong reason. This is the shape the WP Activity Log group arrived at after a denylist returned
  a credential, and it costs the same: a legitimate field is occasionally withheld, because
  its name contains `pass`, `key` or `auth`. That is the right way round.
- **No IP address, user agent or referring URL is returned, at any level.** They are in the
  plugins' storage and are never selected: the Fluent and Ninja queries name their columns,
  and the Flamingo reader takes only `_field_*` meta, never the `_meta` row that holds them.
  The one other place they can get in is the message's mail **subject**, and it is handled:
  Flamingo does not store what a visitor typed, it stores `wpcf7_mail_replace_tags()` of the
  form's `flamingo_subject` setting, which may be `Enquiry from [_remote_ip] ([_user_agent])` or
  contain any answer. `cf7_get_message` therefore returns the subject only when the form's
  template is exactly the default `[your-subject]` and that field is one the answer filter
  would return; otherwise `subject` is `null` and `subject_note` says it was withheld. A site
  that filters `wpcf7_flamingo_get_value` can make the stored text anything, which is not
  visible from here.
- **No search over answers.** A text search over submissions is an oracle: "does any entry
  contain `diff@`" confirms a value no tool will print, one guess at a time. Filters are by
  form, status and date only. This is the reason Gravity Forms' own search was rebuilt
  rather than passed through.
- **The generic post readers refuse the post types Flamingo and Ninja Forms store
  submissions under, and what hangs off them.** This is the part that is not in the new file, and it is there because
  without it the rest is a sentence about the tool and not about the data: Flamingo and Ninja
  Forms keep a submission as a post, `wp_get_post_meta` is a `read`-level tool that returns
  any key on any post (its own source says post meta reads are ungated), and a read-only key
  could therefore read every answer, and for Flamingo the visitor's IP address, through the
  tool beside the admin-only ones. `GMCP_Core::post_read_guard()` (the personal-data policy,
  then the commerce read policy) is asked, ahead of the dispatch switch, by `wp_get_post`,
  `wp_get_post_snapshot` (and the featured image it would return), `wp_get_post_meta`,
  `wp_read_post_meta_chunk`, `wp_get_post_terms`, `wp_add_post_terms` (its reply lists the terms
  the post now holds), `wp_alter_post` (a match count over a body is an oracle) and the
  previews of `wp_update_post` and `wp_delete_post` (which print a title and the start of the
  body), for the post type `wp_get_posts` names, and per row in a `wp_get_posts` listing (type
  `any` names none), a `wp_get_comments` listing and a `wp_get_media` listing. The
  `gmcp://post/{id}` resource, the resource listing, `wp_create_preview_link`, the preview of
  `wp_delete_comment`, the SEO tools' and Polylang's post lookups and the target of every menu
  item (`wp_get_menu_items`, `wp_menu_health`, `wp_update_menu_item`, `wp_add_menu_item`) ask it
  too, as does `wp_site_briefing` (it quotes the title of the most recently modified post, which
  for a submission is the visitor's subject line; it reports the newest post the caller may
  read). It is on whether or not the forms group is. The type list is filterable through
  `gmcp_personal_data_post_types`.
- **The activity log quotes a post's title, so it asks the same question.** WP Activity Log
  writes the title of the post an event concerns into the event's metadata and into the message
  the WSAL tools fill, and a form store titles a submission from what the visitor typed ("Enquiry
  from" and their IP address) and a contact by email address. `wsal_list_events` therefore
  returned an IP address at admin level. This surfaced as an intermittent failure of the suite's
  "no row carries a client IP" check: it failed only when a form suite had written such an event
  within the newest hundred rows, so a re-run passed. `GMCP_Tools_Wsal::concerns_guarded_post()`
  asks `personal_data_post_guard()` of the type the log recorded (and the `PostType` in its
  metadata, all that remains once the post is deleted) and of the post chain behind the row's
  `post_id`, the event's `PostID` and `AttachmentID` and the post a `CommentID` is on. A held
  event keeps its id, time, actor and alert, loses its message, and `wsal_get_event` withholds
  `PostTitle`, `OldTitle`, `NewTitle` and `PostUrl` by name. Not covered: an upload event records
  no post and no type, so once the submission it was attached to is deleted nothing links it
  and its `FileName` (a name the visitor chose) is returned like any other.
- **Below admin, a submission is answered exactly as a post that does not exist.** The refusal
  used to say what the post was ("it is a revision under a flamingo_inbound"), and a key that can
  only read could walk ids and map which are submissions and which are their revisions and
  uploads. `post_read_guard()` now answers a caller below admin with `GMCP_Core::post_not_found_text()`,
  the sentence a missing id gets, and every tool that has its own wording for a missing post
  (`wp_get_post`, `wp_copy_post_meta`'s source, the menu tools, the REST tools, which dispatch the
  id as one that names nothing) answers both the same way; `smoke-forms.sh` compares the two
  replies for each of them after replacing the id. An admin key is told why (it uses the form
  tools anyway), and the audit log records the call and the reply it got. A listing that NAMES a
  submission type is refused with the reason, because it names a type and not a post.
  Not made uniform, and said so: the refusal of a **write** to a submission or its tree explains
  itself (a write-level key learns that an id is a submission, or hangs from one, by trying to
  write to it; it never learns what it holds), and the commerce refusal below admin still names
  the order type. Counts are an existence oracle below, kept on purpose.
- **The policy follows the post's parents.** A revision of a submission holds its body, a file
  attached to one is what the visitor uploaded, and a child post is part of it, yet each is an
  ordinary row of another type (`revision`, `attachment`, `page`) that passes every check made
  on its own type. `personal_data_post_guard()` and `commerce_post_read_guard()` therefore walk
  `post_parent` (`GMCP_Core::post_chain_types()`, bounded at eight and loop-safe) and judge a
  post as the nearest guarded post above it. A comment is judged as the post it is on. This was
  measured, not inferred: before it, a read-only key read a seeded submission's revision and
  upload through `wp_get_post`, `wp_get_post_snapshot`, `wp_get_posts` (type `revision` or
  `attachment`), `wp_get_media`, and the comments on it through `wp_get_comments`.
  `wp_duplicate_post` of such a row was allowed and copied it into a draft that
  `wp_get_post` then returned.
- **No generic tool writes to a submission, or to anything in its tree, or moves anything into
  or out of it.** The guard above is only as true as the tree is stable, and every generic writer could
  change the tree. Measured with a read-write key doing the write and a read-only key the read:
  `wp_update_post` with `post_parent` 0 on a revision of a submission, and the revision was an
  ordinary post whose title and body `wp_get_post` returned; the same for a visitor's upload
  given an ordinary parent (then `wp_get_media` and `wp_get_post_meta` returned it) and for a
  comment given another `comment_post_ID` (`wp_get_comments`). The same gap let a key hide one of
  its own pages from every generic reader by parenting it under a submission. So the policy is
  symmetric with the commerce one, which has always refused every generic write to an order:
  `GMCP_Core::personal_data_write_guard()` refuses every generic write to a post whose chain
  holds a submission type, at every access level, and is asked from `commerce_post_guard()`
  (which `wp_update_post`, `wp_update_post_meta`, `wp_delete_post_meta`, `wp_copy_post_meta`'s
  target, `wp_write_post_meta_chunk`, `wp_duplicate_post`, `wp_delete_post`, `wp_alter_post`,
  `wp_set_featured_image`, `wp_add_post_terms`, the SEO, ACF, Yoast and Elementor writers and the
  journal's undo already ask) and, where no commerce question was ever asked, by `wp_update_media`,
  `wp_delete_media` and `wp_create_comment`, `wp_update_comment` and `wp_delete_comment`
  (`comment_write_guard()`, which for these three tools also closes an order's tree and the
  `order_note` type, see the WooCommerce section). `tree_move_guard()` refuses any write that changes `post_parent` of an
  existing post when its current chain or the new parent's chain holds a submission type or a
  commerce type, and `comment_write_guard()` the same for `comment_post_ID`, so nothing is moved
  out of a guarded tree (it would become readable) or into one (it would be hidden). A new post
  under a submission is refused, by the net at every level. A new child of an order is refused
  when the CALLER names the parent, and not under the net: the REST tools `create_pages` (`parent`)
  and `create_media` (`post`) do take one, and a page created under an order is unreadable below
  admin, so a write key could hide its own new page (measured with a stand-in order, before this).
  `GMCP_Core::rest_write_refusal()` asks `tree_move_guard()` with the caller-named flag before the
  controller is called. The net leaves a creation under an order alone because WooCommerce
  makes its own children of one (a refund hangs from its order) from code a tool call can reach, so
  a plugin's own creation of a child of an order while a tool call is in flight is allowed and is
  a residual below. The same function pre-checks the other arguments of the REST create and update
  tools that name another post: `featured_media` (`pointer_meta_refusal()`) and the registered
  `meta` keys. It exists because the controller inserts the row and then applies the featured
  image and the meta, and the net throws from that tail: `create_posts` with a submission's upload
  as `featured_media` answered "refused" and left the draft it had inserted in the table
  (measured: a tool answering failure over a write that happened). Pre-checked, nothing has been
  written when it says no; and for what no pre-check names, `GMCP_Tools_Rest::handle_call()`
  records the rows a create inserts and, if the net or the drafts-only net refuses from inside
  the controller, deletes those rows again and says so in the reply ("removed again"). That
  removal is of a row the same call made, and it does not make a delete tool of the create tool:
  nothing the caller did not just create can be named by it. A refusal WordPress itself returns
  after the insert (an invalid term, an unknown template) is not the net's and keeps WordPress's
  behaviour of leaving the row. Nobody
  legitimately edits a visitor's submission through an administration agent and the form tools
  only read, so this costs nothing. Two values that POINT at a post are held to the same rule,
  because each prints what it points at: a featured image (`_thumbnail_id`: set through
  `wp_set_featured_image`, the meta tools or any writer) and a menu item's target
  (`_menu_item_object_id`, `_menu_item_object`, `_menu_item_type`, judged in both orders so a
  taxonomy item switched back to a post item does not carry the id through), refused when the
  target is, or hangs from, a submission or an order. **The key is judged as the database
  compares it, not as it was typed** (`GMCP_Meta_Key`). `wp_postmeta.meta_key` is compared by its
  column's collation, `utf8mb4_unicode_520_ci` on a stock WordPress, which ignores accents and
  case, ignores combining marks, soft hyphens, zero-width and control characters, expands `ß`
  to `ss` and ignores trailing spaces, so `_thumbnaíl_id` updates the `_thumbnail_id` row. A
  rule on the typed spelling refused the right one and stored the other. Measured on MariaDB
  10.11 with a read-write key: `wp_update_post_meta` on `_thumbnail_id` pointing at a submission's
  upload was refused, on `_thumbnaíl_id` it was stored, and the public page then printed the
  file; the same fold, with the accent inside the prefix (`_wp_trásh_meta_time`), rewrote a
  trashed post's purge stamp to 1 and the next daily purge deleted a post a person had only
  trashed, which is the "deletion by another name" refusal below reopened, and it repointed a
  menu item. Resolving the key through the database is not enough, because with no existing row
  the write files a new one under the accented spelling and every later query for
  `_thumbnail_id` matches it through the same collation, so the policy asks the **folded**
  spelling whether or not a row exists, in `pointer_meta_refusal()`, in
  `protected_meta_refusal()` (and so in `trash_meta_net()`, the personal-data net and every meta
  writer, `meta_input` included) and, because the journal's undo writes through the WordPress meta
  functions, in the undo too. The fold's table was **measured, not written from the Unicode
  tables**: `.dev/build-meta-key-fold.php` loads every code point into a scratch table and asks the
  server which compare equal to a letter, digit, underscore or hyphen, to nothing, or to a space,
  for `utf8mb4_unicode_520_ci`, `utf8mb4_unicode_ci`, `utf8mb4_general_ci` and `uca1400_ai_ci`, and
  `includes/meta-key-fold-map.php` is its output (rebuild with that script). It is the union of
  the four, which over-folds for any one of them (never under-folds); the one disagreement, `ß`
  (`ss` in the Unicode collations, `s` in `utf8mb4_general_ci`), is asked both ways. A character
  outside the table takes a generic fold (compatibility decomposition, accent removal, lower
  case). A refusal of an odd key the database would have kept apart is the price; an admission
  of one it would not is the failure, so every step errs toward folding. SQLite and binary
  collations compare exactly and the fold changes nothing for them (an ordinary accented key such
  as `café_note` is still writable everywhere). `wp_get_menu_items`, `wp_menu_health` and
  `wp_update_menu_item` leave out an item whose target the caller may not read, because
  WordPress fills an empty item title from the object, and the snapshot omits such a
  thumbnail. **The net.** `GMCP_Core::install_personal_data_net()` asks the same questions under
  WordPress, for writers that ask none, while a tool call is in flight (so wp-admin, cron and
  wp-cli are untouched): `wp_insert_post_data` and `wp_insert_attachment_data` (a write to
  something that hangs from a submission, or a new parent in a guarded tree), post meta added,
  updated or deleted by key and by id, term assignment, trash, untrash and deletion,
  `wp_update_comment_data`, `preprocess_comment` and `delete_comment` / `trash_comment`. It
  throws, like the drafts-only net, so a writer cannot report success over a write that did not
  happen. It refuses what hangs FROM a submission and does not refuse a write to the submission's
  own row, on purpose: a form plugin keeps those rows itself from hooks a tool call can fire
  (Flamingo saves a contact, a post of a guarded type with meta and terms, whenever a user
  profile is saved), and a net that refused it broke `wp_update_user` and `wp_create_user` on a
  site that has Flamingo (measured on the first full run). The generic tools refuse that write
  themselves, through `commerce_post_guard()`. Deleting a submission through
  `wp_delete_post` would also have orphaned its uploads as ordinary readable media (WordPress
  hands a deleted post's children to its parent); it is refused. The drafts-only guard and the
  commerce guard are unchanged and asked in the same places.
- **Terms: decided on evidence.** The terms ON a post are asked about through the post
  (`wp_get_post_terms`, the snapshot and the resource header all refuse a guarded post).
  Whether a term is itself personal data was read from the plugins: Flamingo assigns a channel
  to a message only when the term already exists (`term_exists()` guards the call) and takes a
  contact's tags from existing terms, so neither holds text a visitor typed, and Ninja Forms
  registers no taxonomy for its submission type. The term LISTING names no post, so it cannot
  ask the post guard; `wp_get_terms` refuses (at every level) a taxonomy whose every object type
  is a guarded type, because a vocabulary that exists only to classify submissions is the one
  place a plugin that minted terms from submitted values would put them, and refusing costs a
  caller nothing the form tools do not give. A taxonomy shared with an ordinary type is listed:
  its terms are ordinary content.
- **The generated REST tools.** Their routes are fixed to `posts`, `pages` and `media`, and the
  REST controller answers a post of the wrong type with a 404, so a submission cannot be
  reached by its own id (checked live: `get_posts` of a submission id is `rest_post_invalid_id`).
  What did reach it was a media row attached to one: `get_media`, `list_media` and `update_media`
  returned and, for `update_media`, retitled a file attached to a seeded submission. Now every
  tool that takes an `id` asks `post_read_guard()` first (below admin the id is dispatched as
  one that names nothing, so the answer is the controller's own answer for a missing id); a list
  call runs with `posts_join` and `posts_where` filters that leave out the guarded types and posts
  hanging from them to the same depth the guards walk (the row and seven parents,
  `GMCP_Core::unreadable_post_clauses()`, joins because seven nested subqueries overflow the
  SQLite driver's parser), so rows are removed where the query is built and not afterwards,
  because `_fields` can leave the id out of the rows and a page filtered afterwards is short; a
  write that moves a row into or out of a tree (`update_media` with `post`, `update_pages` with
  `parent`) is refused by the net under `wp_insert_post_data`; and a route whose post type is a
  guarded type (a plugin registering one with `show_in_rest` and a `rest_base` of `posts`,
  `pages` or `media`) is not turned into tools at all. The tool cache key moved to `v6` so a
  cached list is rebuilt. The Elementor reference scans (`elementor_template_references` and the
  scan for where an Elementor global is used) use the same SQL, so they too stop at the guarded tree and not at the second
  level.
- **The change records do not quote a submission.** A read-level key can call `wp_list_changes`,
  and each entry names the post by the first 60 characters of its title. Writes to a submission
  are refused now, so a record about one can only come from a writer that asks no guard, and the
  records are built after the write: a post moved out of a submission's tree (by such a writer)
  is an ordinary row when the record is made, which quoted an upload's title and named the
  submission as the parent it came from, in the journal, its snapshot table and the audit `changes`
  column (measured). `GMCP_Changes::record()`, the one place every record passes, judges the post
  as it is NOW and as it was BEFORE (the observers pass the state they held), and when either is
  in a submission's tree replaces the label with the id and every before/after value with
  `[withheld]`, and drops the previous values; the journal does not record a withheld entry at
  all, so no undo can write the markers over a post, `undoable_post()` refuses such a post, and an
  old entry is not put back either. An entry written before this change is rewritten on the way
  out of `wp_list_changes`. Nothing in these paths ever returned a previous value to the caller
  (`wp_list_changes` lists no values and `wp_undo_change`'s reply names none). `smoke-forms.sh`
  proves it with a test-only tool that writes through WordPress with the net taken out.
- **A tool that copies from a post is a reader.** `wp_copy_post_meta` used to ask the write
  guards about the post it wrote TO and nothing about the post it read FROM, so a `readwrite`
  key copied every meta key of a Flamingo message (its `_meta` holds the IP address and user
  agent) or of a `shop_order` (HPOS off) onto a draft of its own and read the draft with
  `wp_get_post_meta`. `GMCP_Core::post_read_guard()` (the personal-data guard, then the
  commerce read guard, which only lets an admin key read an order's rows) is now asked about the
  source by `wp_copy_post_meta` and `wp_duplicate_post`, and again inside the one function that
  moves the rows. `wp_update_post` and `wp_create_post(s)` refuse to retype a post out of, or
  into, a form-submission type (`GMCP_Core::personal_data_retype_guard()`): the read guards ask
  what type a post is, so a message retyped to `post` was an ordinary post whose body
  `wp_get_post` returns, and Flamingo builds that body from the fields, consent and meta. A
  commerce post is not retypable either, because `commerce_post_guard()` refuses every generic
  write to one.
- **What the guards do not cover.** They are a list of readers and writers, not a property of the
  data, and the same weakness the commerce guard has: a reader or writer added later has to be
  added to it. Exactly, today:
  - Counts. `wp_count_posts`, `wp_count_media`, `wp_count_terms`, the per-type counts and the
    per-term `count` in `wp_site_briefing` and `wp_get_terms`, and the type names in
    `wp_get_post_types` and `wp_get_taxonomies` say how many rows exist and never return one.
    An existence oracle for the number of submissions, which the read-level form briefings
    return on purpose. Kept, not an oversight. Id gaps and the ids a write refusal names are the
    same kind of oracle and the same size.
  - The answer is uniform for READS below admin, not for writes: a refusal of a write to a
    submission or anything in its tree explains itself, so a key that can write learns that an
    id is one, or hangs from one, never what it holds. The commerce refusal below admin names
    the order type. A listing that names a submission type is refused with the reason.
  - The audit log keeps the arguments an agent sent, including a value it sent to a tool that was
    then refused, because that is the record of what it did. It never holds what the post held
    before, and it is an admin-level read.
  - A journal entry about a submission that was **deleted** before this change still carries
    the title it was written with: once the post is gone its type cannot be looked up.
  - Posts hanging from a guarded post are found through `post_parent`, to eight levels (the post
    and seven parents) in the guards and in every SQL filter. A row related to a submission by a
    meta value holding its id is not, except the two pointers that print what they name, a
    featured image and a menu item's target, which are refused when written and left out when
    read, and which are recognised by key on case- and accent-insensitive collations only
    because of the fold above (measured on the four collations listed there, plus a generic
    decomposition for the rest). A collation that equates a character neither the measured table
    nor Unicode decomposition equates (a locale-tailored one, or `utf8mb4_0900_ai_ci` on a
    character its Unicode version equates and decomposition does not) would not be recognised;
    asking the live column collation instead is not done, because LIKE and prefix comparison
    under expansions are not reliable and SQLite cannot answer. An id inside any other value (an ACF relationship field, an Elementor widget naming a
    post, a plugin's own table) is neither refused when written nor filtered when read.
    **That includes ids written into `post_content`, which is not covered, and was measured:**
    on a published page, `[gallery ids="<id of a submission's upload>"]` printed the upload's
    `<img>` to an anonymous visitor, and the same shortcode on a draft printed it through a
    preview link; a Query Loop block with `"parents":[<submission id>]` and `"postType":"page"`
    printed the title of a published page that hangs from the submission. (`[gallery id="<submission id>"]`
    printed nothing on the same site, so the `id` form is not the route there, which is a fact
    about that WordPress version's gallery query and not a guard.) A write-time scan of post
    content for `[gallery ids|include|exclude|id]` pointing into a guarded tree was considered and
    not added: it would cover one shortcode of the many that take ids (`[audio]`, `[video]`,
    `[playlist]`, plugin shortcodes), none of the block attributes or page-builder widgets
    that do, and every writer of content, and a guard that names one route reads as covering
    the others. So a write key that guesses an id (ids are small integers, and a write refusal
    names an id as a submission) can publish that upload by naming the id in content it writes;
    the read guards keep the submission's data from the readers, not from that.
  - A writer that is not one of the generic tools can still write a submission's own row (its
    title, body, meta and terms) while a tool call is in flight, because the net covers what
    hangs from a submission and not the row itself. That cannot move data out of the tree, which
    the net does protect; it can alter what the form tools later report.
  - Writers the net cannot see: a plugin that writes `wp_posts`, `wp_postmeta` or `wp_comments`
    with `$wpdb`, `wp_set_comment_status()` (no hook in front of it) and `wp_insert_comment()`
    (none either; the comment tool asks the guard itself, and `wp_new_comment()` is covered). And
    anything outside a tool call: a person deleting a submission in wp-admin hands its uploads
    to the deleted post's parent, as WordPress does, and they are ordinary media from then on.
  - `wp_get_posts`, `wp_get_media` and `wp_get_comments` leave what the caller may not read out
    **in SQL** (`with_readable_posts_sql()` through `posts_join` and `posts_where`, and
    `with_readable_comments_sql()` through `comments_clauses`; the same clauses as the REST
    listings), and the offset and `paged` are the database's offset over those rows. Before, they
    fetched a window, dropped the rows the guard refused in PHP and counted the offset in PHP, so
    an offset loaded every row below it as an object and walked its parents (measured, 50,000
    posts, read-only key: `limit 5` 0.6 s, `limit 5, offset 40000` 13.2 s and 210 MB, offset
    10,000,000 over a minute; the same calls now take 0.1 to 0.5 s and no more memory than the
    page). The guard is still asked of every row that comes back, as the backstop, and what it
    refuses is skipped while the page is filled; that PHP pass looks at no more than 5000 rows in
    all, and the offset is not part of that count (it used to extend it). So the work is bounded by
    the database's own cost of an offset (an index walk, not objects) plus at most 5000 rows judged,
    and a short page is possible only if more than 5000 consecutive rows the SQL let through are
    ones the guard refuses, which the two should never disagree about. The listings run with
    `suppress_filters` off, as the REST listings do, so other plugins' query filters (a language
    filter, for instance) now apply to them. The briefing's latest-post probe and the resource
    still use the PHP pass alone with the 5000 bound, and for a window of more than 5000 unreadable
    rows at the head of a listing they can still answer short. Rows with identical dates may come
    back in another order than before, because the join changes the plan and `post_date` is the
    only sort key.
  - A taxonomy shared between a submission type and an ordinary type is listed by
    `wp_get_terms`, and the same applies to its terms on an ordinary post.
  - Every route to the database that is not a post reader, and the optional groups' own stores
    (Gravity Forms entries, WooCommerce Subscriptions, LearnDash, the audit tables of an activity
    log), which are gated by their own tools and levels and not by this guard.

  WooCommerce orders stored as posts, and (by id, see the WooCommerce section) orders in
  `wc_orders`, ARE covered by `commerce_post_read_guard()` in
  `wp_get_posts`, `wp_get_post`, `wp_get_post_snapshot`, `wp_get_post_meta`,
  `wp_read_post_meta_chunk`, `wp_get_comments`, `wp_get_media`, the copy tools, the resource,
  the REST tools and the template-reference scans, and in the menu readers for an item whose
  target is one. That guard applies below admin level only, whereas the form-submission guard
  applies at every level (an admin key uses the form tools).
- **Fluent Forms has a setting that keeps no entries, and `fluentform_briefing` reports it.**
  `formSettings.delete_entry_on_submission` (a form's settings JSON in `fluentform_form_meta`,
  default `no`) deletes each entry as it is stored, and `delete_after_x_days` with
  `auto_delete_days` purges old ones. A zero for such a form means nothing, so `log_state` lists
  the forms with the first setting and the forms with the second (and a `null` means the settings
  could not be read, not that none is set). Ninja Forms' equivalent is the "has an active action
  that records submissions" check below.
- **Fluent Forms and Ninja Forms tables are checked for existence before a count.**
  Fluent stores answers as one JSON document per submission and the schema as JSON on the
  form; a schema that does not parse means no answers. Ninja Forms is told whether each form
  has an active action that records submissions: a form without one cannot have any, so its
  zero is "not kept", and `null` is reported where that could not be determined.

## WPForms, Wordfence and Stream

All three are read only, and the absence of a write is the design: a form's recipients, a
firewall mode, an issue list and a log's retention are each something an agent could change to
make what is watching the site quieter, or to send what visitors submit somewhere it chose,
and there is no restore tool behind any of them. WPForms is folded into the form group above
(its switch, its levels, its `log_state` block); Wordfence and Stream have a switch each.

- **WPForms has no entry tool.** Measured on Lite 2.0.4: `wpforms()->obj('entry')` is null and
  none of the three entry tables exists, so the plugin keeps a counter in form meta and emails
  the rest. The briefing names which of those two it is reading, because "none arrived" and
  "nothing is storing them" are the same number. On Pro the tables have not been measured from
  here, so nothing reads them. `wpforms_get_form` returns a form's fields and the labels of
  its choices, and counts its notifications and confirmations without returning them.
- **Another plugin's configuration is a post type the generic post tools refuse.**
  `GMCP_Core::plugin_config_post_types()` lists `wpforms`, `wpforms-template` and
  `wp_stream_alerts`. The first two hold one JSON document each (fields, notification
  recipients, confirmation redirects; a template is read through the same decoder and new forms
  are made from it); a Stream alert rule is a post whose status decides whether it fires. Writes
  are refused at every level and include creating or retyping into the type
  (`commerce_post_guard()`); reads are refused below admin, for the post and for what hangs from
  it, a revision included (`commerce_post_read_guard()`); the SQL readers leave the types out
  below admin (`read_excluded_post_types()`); and a REST route one of them takes over is not
  turned into tools. What it does not cover: a menu item or a featured image that points at one
  of these posts (it would print a title, not the document), a plugin's own tables, and any type
  a site has not added through `gmcp_plugin_config_post_types`.
- **The plugins that watch cannot be switched off through the plugin tools.**
  `GMCP_Core::watcher_plugin_files()` (Wordfence, Stream, WP Activity Log; extendable through
  `gmcp_watcher_plugin_files`) is refused for deactivation, deletion and update. The option rule
  alone left the door open: measured, each was deactivatable in one call through the admin
  group. It does not stop a site owner, a bulk action in wp-admin, or a plugin that deactivates
  another.
- **Stream's settings are refused to every option writer** (`wp_stream` is in
  `audit_log_option_prefixes()`, and covers `wp_stream_db` and `wp_stream_network`).
  Wordfence's are not, deliberately and measured: 9.0.2 keeps its configuration in its own
  `wp_wfconfig` table and its handful of option rows are version and activation bookkeeping, so
  a prefix there would guard nothing and read as protection that is not there.
- **What the readers return was chosen against what a person typed.** Stream returns no IP
  address at any level, filters a record's metadata through an allowlist (the new account's
  email address of a created-user record sits under a repeated key called `user_meta`), and has
  no free-text search, since a search over a rendered summary confirms a guessed value one
  attempt at a time. Wordfence returns an address only for the block types that name one, at
  admin; a login lockout's recorded reason ends in what the visitor typed (`... was:
  'username'`, the password-reset form, an invalid username), and that text is replaced with
  `[withheld: typed text]` for the three templates Wordfence writes. A reason of another shape is
  returned as stored, because there is nothing to recognise it by.

## Beaver Builder and the one-time download

Both arrived together and both sit next to guards that were tightened first, so what each one
asks is written down here.

- **A page builder's layout is a meta key the generic tools refuse, by name.**
  `GMCP_Core::layout_meta_keys()` (`_fl_builder_data`, `_draft`, `_data_settings`,
  `_draft_settings`, `_enabled`; extendable through `gmcp_layout_meta_keys`) is refused by every
  generic meta writer (`generic_writer_meta_refusal()`: the meta tools, copy, `meta_input` and the
  REST `meta` argument) and by the keyed reader. It is deliberately NOT in the net under the
  WordPress meta functions: Beaver Builder copies the layout onto a revision from its own
  `save_post` hook, so a refusal there made `wp_update_post` on any builder page write the title
  and then answer "failed" (measured), and another plugin's writer of these keys is therefore
  not stopped. The readers that list EVERY key
  (`wp_get_post_meta` with no key, `wp_get_post_snapshot`) used to return the whole serialized
  tree, an `html` module's script included, to a read-only key: measured. They now list the key
  with `[withheld: page builder layout]` through `readable_meta()`. The key is judged as the
  database compares it (`GMCP_Meta_Key`): on MariaDB `_fl_builder_dáta` was the layout row, and a
  write through it replaced the stored layout. Not covered: another plugin's own reader, a
  layout kept under a key a site has not added to the filter, and a builder other than the one
  listed.
- **`beaver_layout` is a reader of the post it names.** It returns a title, a type and a status,
  so it asks `post_read_guard()`: below admin a form submission, anything hanging from one, an
  order or another plugin's configuration answers exactly as a missing id does; admin is told why.
  `beaver_templates` lists only the builder's own template type and asks nothing more.
- **`wp_download_request` asks the same read policy before it mints a token.** The parent test
  (a published, unprotected post, or admin) was not enough: a form submission is `publish` to
  WordPress, so a read key got a URL for a visitor's upload (measured). `post_read_guard()` now
  runs first, with the missing-attachment answer below admin, and a refused call mints nothing.
  Admin is refused for a submission's upload as well, as every generic reader is; the form tools
  are the way to it.
- **The file an attachment serves cannot be repointed by a generic writer.** The rules above are
  about the attachment row, and `_wp_attached_file` is what the endpoint streams, so a readwrite
  key that set it on an ordinary attachment to the path of a submission's upload downloaded that
  upload through the innocent row (measured). `GMCP_Core::attachment_file_meta_refusal()` is asked
  by the meta tools, copy, `meta_input` and the REST `meta` argument, on the key as the database
  compares it. It is deliberately not in the net: core writes the key itself during
  `wp_upload_media`. Not covered: a plugin that writes it through core while a tool is in flight,
  and the usual limit that the endpoint serves anything under uploads that an attachment row names.
- **Expiry is enforced by the endpoint.** The endpoint reads the transient row directly, and
  WordPress leaves a lapsed transient in place until something asks for it, so the five minutes
  were stated by the reply and not enforced: a token whose timeout was ten minutes in the past
  streamed the file (measured). The timeout row is read and a lapsed or missing one is refused.
  The token is also consumed by a conditional delete, so two requests cannot both win; on a site
  with a persistent object cache no token is minted at all.
- **The token is scrubbed like a preview link's.** The reply is JSON, which writes `/` as `\/`, and
  the first scrub matched only the bare `/download/`, so the token sat in the audit log's detail
  column: measured. `GMCP_Diag_Util::scrub_preview_tokens()` now matches the route with any number
  of backslashes or `%2F` in front of each slash, for `download` and for the `mcp/v1/upload` route
  (the upload URL is a bearer credential of the same shape), so the audit log, the approval view and
  the debug log share it. A bare token with no route in front of it is not recognised, and the debug
  log was measured not to print a tool's reply.

## Theme mods

Theme mods are the customizer's storage, and four generic tools cover them:
`wp_get_theme_mod`, `wp_set_theme_mod`, `wp_list_theme_mods` and
`wp_remove_theme_mod`. They are in the core tool group because
`theme_mods_<stylesheet>` is ordinary WordPress storage, and any customizer
framework — Kirki, Redux, OptionTree, or the core customizer itself — uses it.
The critical one is `wp_set_theme_mod`: it uses `set_theme_mod`, which merges one
key, where `wp_update_option` on the same row does a full-array replace and a
mistake wipes every other mod. The row passes through the same `option_guard`
and `option_write_policy` as any other option, and the change journal recognises
`theme_mods_*` rows, so `wp_undo_change` can put a write back. It does so one
setting at a time: the row is a single array holding every Customizer value, and
restoring the whole of it would throw away any other setting changed since. See
[Undo](#undo).

## Batch create

`wp_create_posts` takes up to twenty and creates them in order, stopping at the
first failure. The reply names what was created with its new ids, what failed
and why, and what was never attempted, so a caller can retry the remainder
without re-reading the whole list. It is a separate tool rather than an argument
on `wp_create_post` for a reason that is not aesthetic: access is declared per
tool and named keys are scoped to tool names, so an argument would have handed
every key already scoped to the single create the power to write twenty posts a
call, without anyone deciding that. The cap comes from the journal rather than
from taste, since it holds forty entries for the whole site and an oversized
batch would evict everyone else's undo history rather than merely crowding its
own. Every guard the single create applies is applied per item; a batch is not a
way to write content a single call would have filtered. It cannot be undone as a
unit, because the creation of a post is not journalled (the options WordPress writes as a side effect of it are, as ordinary option changes), and the reply says so and points at
the ids it returned.

## Preview

The six tools whose effect is not visible from the call accept `preview`, which
describes what would happen and changes nothing: `wp_delete_post`,
`wp_update_post`, `wp_alter_post`, `wp_delete_term`, `wp_delete_media` and
`wp_delete_comment`. It matters most for `wp_alter_post`, where a search and
replace reports success whether it matched everything or nothing.

Two things make that preview trustworthy rather than decorative. It compiles the
pattern through the same function the write uses, so it cannot describe a
different pattern. And it works out each replacement by expanding the reference
forms against the groups the match actually captured in context, rather than
re-running the pattern over the matched fragment on its own. The second one
matters more than it sounds: a pattern like `(?<=foo)bar` matches in the real
body but not in the fragment `bar` taken alone, so the naive version reported
that the text would be left unchanged and the write then changed it. A preview
that reports safety wrongly is worse than no preview.

It also counts matches without collecting them. A pattern of `.` against a 400
KB post is 380,000 matches, and building an entry for each in order to display
ten exhausted the memory limit, which made the cautious call more dangerous than
the write it was protecting.

## Undo

`wp_list_changes` and `wp_undo_change` put back something an agent *modified*:
an option or setting, a post, a custom field, a term's name, slug, description or
parent, which terms a post carries, and a single theme setting. The change capture
layer listens to WordPress rather than to the tools, so it also covers widgets,
which live in options, and menu items, which are posts with some custom fields.
Only writes made during a tool call are recorded, never a person's own edits.

That layer is shared with the audit log, which wants the same facts from the
other side: the journal keeps the previous value so it can put it back, the
audit log keeps a description of the difference so a reader can see what moved.
Two copies of the same diff would drift, and the day somebody added a field to
one list the other would quietly stop mentioning it.

Undo records modifications and not the creation or deletion of a post, term,
menu, menu item or user, and that is a real limit rather than a nicety. The options
WordPress writes itself while it does one (for example
`wp_calendar_block_has_published_posts` under `wp_create_post`) are ordinary option
writes and are journalled under the tool that ran; the thing created or deleted is not. The
capture layer reports them and the journal declines them: putting back a
creation means deleting something, and putting back a deletion means recreating
it with its old id, its children, its relationships and its meta, and neither is
the same write in reverse. Adding or deleting a menu item is a creation or a
deletion in this sense; editing one is not. Users, comments, plugins, themes and
media are reported too and journalled none of them, for the same reason. The audit
log has all of it, which is the difference between the record and undo. Deleting a post without
`force` puts it in the trash, where WordPress can restore it, which covers the
most common case by accident rather than by design.

Post meta is journalled, and adding or removing a field is journalled with it,
unlike a post. Those two really are the same write in reverse: the opposite of
adding a key is removing it. It is journalled because on a page-builder site the
meta *is* the work, and an undo log that covered everything except
`_elementor_data` missed the changes that mattered most on exactly the sites
this gets used to build. The noise that used to be the argument against it is
handled by a short filterable list, `gmcp_meta_noise_keys`, holding the keys that
say who is editing rather than what the post holds and the ones Elementor
derives from the document and regenerates on demand.

Three more kinds are journalled, each because it is an edit that has an exact
reverse. A term edit keeps the previous name, slug, description and parent, and
puts back only the fields the edit changed; a menu is a term, so renaming one is
covered. A change to which terms a post carries keeps the earlier set of term
ids and sets it again, which is the reverse of both an append and a replace
because the entry records the set rather than the argument. The terms a post is
given while it is being created are not recorded, because they are part of a
creation, and neither are the `nav_menu` assignments that attach an item to its
menu. A theme setting keeps the previous value of one key of the
`theme_mods_<stylesheet>` row and writes that key back into whatever the row
holds now, so undoing a menu-location assignment cannot discard a colour changed
afterwards. A change that moves more than ten keys of the row at once is kept as
one whole-row entry, as it used to be, so a single option write cannot fill the
log by itself. Credential-shaped keys and values are judged by the same tests as
a meta value (field names, and the text test described below) and are recorded as
changed without a copy.

Reverting a post, a custom field or a set of terms also asks
`commerce_post_guard()`, because an order and a subscription are `wp_posts` rows
and the policy about them belongs to the post type rather than to the tool that
is writing it. Revert now enforces that for itself instead of relying on the
original write having been refused. The guard judges the post as it is now, not a
value the revert would write, so a post revert also restores only the fields the
recorder watches (title, content, excerpt, status, slug, parent, menu order) and
never `post_type`. No route to a post_type restore was found: the recorder does not
record that field, and the journal's own row cannot be edited through any tool. The
filter is a backstop for an entry this plugin did not write.

An undo is recorded as a change of its own. The entry keeps what the undo
overwrote, names the entry it reversed, and carries the tool of the original, so
reverting it needs the same permissions as the original did. Reverting it puts
the edit back, and that can be done again in turn. The first undo of an ordinary
entry has never refused because something changed since: it restores the fields
the entry recorded and leaves everything else alone, which is what lets a title
be put back on a post that has since been rewritten. The undo of an undo has no
such excuse, because it restores a whole recorded value taken before whatever
happened next, so it is refused when the target is no longer in the state the undo
left it in. That state is stored as a hash measured from the stored value after the
write, never as a second copy of it. The hash is an HMAC under the site's salt: it used to
be an unsalted md5, which is a fingerprint of the live value that anyone able to read the
journal could test guesses against offline. An entry recorded before the change still holds
the md5 and is compared in that form until the journal rolls past it (at most forty
entries); nothing writes that form any more. For a custom field it covers every row of the
key, not the first, and an undo that would write one recorded value is refused when
the key now holds several rows, because that write rewrites every row. Where the
value the undo is about to overwrite holds a credential, the undo is recorded as
not reversible instead of being copied, for the reason the original is not; that
test runs for options, custom fields, theme settings, terms and a post's own
fields. See "Free text" below for what that test is.

Free text. The test above judges field names, and it opens a value only if the
value begins as JSON or serialized data. A post body is prose, so `{"api_key":
"..."}` pasted into the middle of one, a `define( 'DB_PASSWORD', '...' )` line or
an `Authorization: Bearer ...` header named no field and was kept: in the journal as
the previous value, in the redo entry as the value an undo overwrote, and in the
audit log as the call's arguments and as the one-line change summary. A second test,
`GMCP_Credential_Scan`, reads the text itself. It looks for what people actually
paste: a quoted or bare `name = value` where the name ends in a secret word
(`password`, `secret`, `token`, `api_key`, `access_token`, `DB_PASSWORD`) and the
value has the shape of one, a `define()` of such a constant, a bearer or
Authorization header, a private key block, user information in a URL, a JWT, and the
published prefixes of the common key formats. It is a heuristic and not a promise: a
secret that is a bare word with nothing around it is not recognisable as one.

Exactly what it reads, because each of these was once a miss and is now asserted
(`.dev/journal-pure.php`, offsets included): the name may be of any length (a bound of 63
characters let a secret hide behind a long name) and may be a PHP array index
(`$cfg['db_password'] = '...'`); `=>`, `=` and `:` separate; inside a quoted value a
backslash and the character after it count as one, so `\'` and `\"`, Gutenberg's `\u0026`
and a Windows path (`C:\\vault\\...`) do not end the value, and a `define()` value with an
escaped quote is taken whole; text that was escaped for embedding is read as well, as
`&quot;`, `&#039;`, `\"`, `\\"` (escaped twice), `\u0022` and the HTML-escaped arrow `=&gt;` of a
code block, and the spans are mapped back to the original text. `Basic` is a credential when the
token is the base64 of `user:password` (decoded, printable, with a colon), `Bearer` when it is eight
characters or more with a digit (or twenty or more letters) and not words joined by hyphens or
slashes, and an `Authorization:` header is read whole. What it does not find: a value split across
several arguments or lines, a credential that is base64 or encrypted and has no name or prefix
around it, a bare token, a `Basic` credential that is not standard base64 of `user:password`, a
secret under a name that does not end in a secret word, and an unquoted value under `token`, `secret` or `key`
that is nothing but short words and numbers joined by hyphens or slashes.

It is built to avoid the opposite failure as carefully, because a false positive
costs an undo and the journal exists so that an ordinary edit can be put back. The
last word of the name decides, so `passwordLabel` and `placeholder_text` are labels,
and a name that merely contains a pattern (`author`, `keywords`) counts only when its
value looks like a token. Prose that uses the word "password" is kept whole and
undone byte for byte, and a block attribute such as `{"authorId":3}` is not touched;
both are asserted. On a half-megabyte body it takes about 15 to 60 milliseconds,
measured, and a failure of the regular-expression engine itself answers "yes, a
credential", never "no".

Measured false positives. Ordinary prose that was flagged and is not now: `Course 1: Basic
HTML/CSS for beginners`, `Basic 2024/2025 membership`, `Season Pass: 2024-2025`, `Day pass:
10am-6pm daily`, `Token: 1st-come-first-served`, `The ring Bearer 4-year-old nephew`, `Hall
pass=2nd-floor`, a reCAPTCHA `data-sitekey`, and a Stripe publishable key (`pk_live_`, `pk_test_`;
the secret `sk_` keys are still flagged). The causes were a Basic or Bearer rule that wanted one
digit or slash in an eight-character word, and `pass`, `token` and `secret` standing alone as names
in the unquoted `name: value` rule. A bare `pass` is no longer a name there (`db_pass`, `smtp_pass`
and `DB_PASS` still are), a `::` is not a separator, and for the unquoted `token`/`secret`/`key`
names a value that reads as words and numbers (a version, a range, hyphen- or slash-joined words and
short ordinals) is not one. A password name has no such exemption: a passphrase is often just words
joined by hyphens. Run over 1,423 documentation and readme files from this machine (30 MB, 730,252
lines: the WordPress readme and licence, the bundled plugin's readme, and the system's package
docs) the scanner flagged 6 lines before and none after; the six were C++ scope names such as
`strlen_pass::count_nonzero_bytes_addr`. That corpus is technical text and says little about
marketing prose, so the seven lines above, not the corpus, are what the tests assert. What is still
flagged and cannot be removed without losing real secrets: a Maps `?key=AIza...` URL (a Maps key
is restricted by referrer, but the same prefix is a server secret on other Google services), a
placeholder that is not on the list (`$Db_pass9`, `${db_pass}`), and any prose that really looks like
`name=value` with a secret-ended name and a token-shaped value (`password=abc123`, said in a sentence).

What a post entry does about it is per field. A field that holds one is left out of
`previous` and named in `redacted_fields`; the other fields of the same edit stay
restorable and the listing says the restore is partial. Nothing is stored in its
place, because a placeholder in `previous` would be written over live content by the
undo, which is the failure the option path's refill exists to prevent: a field that
is not recorded is simply not restored. An entry whose every field was withheld is
not reversible and says why. The same text test now covers the other kinds' string
values and the arguments the audit log stores (every string leaf, for the first 64,000
bytes, which is all that can be stored, with only the credential replaced). The title
shown in an entry's "what" line, the audit log's change summary (short values and the post
label), its target column and the first thousand characters of a reply it keeps are held to a
wider test, `GMCP_Core::looks_credentialed()`: the scanner, or anything that reads like an
assignment under a secret-named word, because a label that says `smtp_pwd=hunter` or
`password: hunter2` is read by a read-level tool and over-redacting a label is cheap. Whether
to keep a value for undo stays the narrow scanner. The debug trace has its own, wider rule.
Not covered: an entry recorded before these checks existed keeps the `previous` values it
recorded until the journal rolls past it (its one-line description is re-checked each time
entries are listed), and the `gmcp_change` action, which a site's own code may hook, still
carries the raw before and after values, because it is an in-process hook and not a store.

Undoing an edit never writes a status WordPress deletes on. A recorded earlier
status of `trash` or `auto-draft` is not restored, by the same refusal the write
tools use (`GMCP_Core::status_write_refusal()`). Measured, not assumed. For
`auto-draft` the route was real: promoting an old auto-draft with `wp_update_post` and
then undoing that put the status back to `auto-draft` with nothing refusing, and
`wp_delete_auto_drafts()`, the routine WordPress's daily event runs, then deleted the
post. For `trash` it is defence in depth: a post moved out of the trash by a plain
`wp_update_post` keeps its `_wp_trash_meta_time` stamp (measured), so writing `trash`
back would re-arm the permanent delete, but the tool's own update path clears the
stamp first, so no way to reach that through a tool was found. The rest of the entry
is still restored, and the reply says the status was left alone.

Previous meta values live in a table of their own rather than in the journal's
option row, because `_elementor_data` runs past 100KB and the option's per-value
ceiling is 64KB, so sharing the budget would have recorded every Elementor edit
as too large to keep. The table is pruned by age and by total size on the same
daily event the audit log uses, and a snapshot that has expired makes its entry
report the copy as gone rather than silently putting back something stale. A
value past a megabyte, or one whose key name or contents look like a credential
(a secret-named field anywhere inside it, or a string with a pasted secret in it),
is recorded as changed with no copy kept and says which.

That contents check walks much deeper here than it does for an option. Both
ask the same function, and it refuses anything below its limit on the grounds
that a limit answering "no" down there would be a way to hide a secret by
burying it. Six levels is right where the answer feeds something a person
reads. It was wrong here: a page-builder design nests far past that, so every
design was refused for being deep rather than for holding anything, which
declined exactly the values meta undo exists to restore. The deeper limit is
stricter rather than looser, because a secret buried below six levels is now
found and named instead of being refused indistinguishably from a design with
nothing in it.

Reverting is gated twice: on the tool that made the change, and on the
operation the revert will perform, derived from the entry's own kind. Both are
needed, because the recorded tool is whatever was in flight rather than what
wrote the row. A plugin hooked on `save_post` that writes an option produces an
option entry attributed to `wp_update_post`, and gating on that name alone let a
write-level caller replay an admin-level option write.

Credential-shaped leaves are not stored, judged by the field names inside a
value through `gmcp_credential_field_patterns` as well as by the option's own
name. The rest of the value is: the shape is kept and only those leaves are
blanked, so the change stays reversible and the entry says the restore will be
partial. Undo then puts back everything that was recorded and leaves each
blanked leaf exactly as it is, because writing the placeholder over a live
credential would destroy the secret the blanking exists to protect. That
matters more than it sounds: the patterns match as substrings, so `key` also
matches `keywords` and `monkey` and `auth` also matches `author`, and dropping
the whole value cost undo to any option merely containing a field so named.
Elementor's icon registry, which stores an icon name under `key`, was the case
that surfaced it.

Some values still cannot be snapshotted and keep the older all-or-nothing
answer: an object anywhere inside one, because restoring an array copy would
put back a different type than was there, and anything nested past the depth
limit. Those are recorded as changed and refuse to revert, saying so.

Two limits worth knowing. The check is structural, so a secret held as a bare
string under an innocuous option name is still stored; a string that parses as
JSON or as a serialized array is unpacked and judged, and blanked whole if it
holds one. And it is not retroactive: adding an option to
`gmcp_protected_options` refuses future reverts but does not scrub what is
already recorded, so clear the journal after protecting something that was
previously being written.

## Backups

`wp_backup_status` reports what is known, `wp_list_backups` says which backups
exist, and `wp_start_backup` asks the site's backup plugin to start one. Three
things shape this more than the integration does.

There is no common interface. Sixteen backup plugins with no dominant one, and
the largest work in unrelated ways: UpdraftPlus fires a WordPress action,
Backuply writes a job record and leaves a cron hook to pick it up, BackWPup
wants a secret URL the site owner must first enable with a filter, and several
keep scriptable export behind a paid tier. So there are working adapters for
UpdraftPlus and Backuply, detection for BackWPup, All-in-One and Duplicator that
names them and says why it cannot drive them, and `gmcp_backup_providers` for
anything else. When more than one drivable plugin is active the first listed
wins, and UpdraftPlus is listed first, so adding a provider never moves an
existing site onto it.

The route a plugin's own screen uses is often not a route. Each adapter is
written against what a token-authenticated REST request actually has, which is
not what the button calls. Backuply is the clearest case: the handler behind its
Create Backup button lives in a file the plugin includes only when
`wp_doing_ajax()`, and that handler then calls the site back over HTTP
forwarding the administrator's browser cookies to a second handler that checks
`current_user_can`. Neither half survives the trip, and no nonce fixes that.
What is registered on every request is the cron hook Backuply runs its own
unattended backups from, so the adapter writes the same job record the button
writes and queues that, then kicks cron rather than waiting for the next
visitor. It has to be a different request: everything under Backuply's runner
ends in `die()`, so calling it inline would take the tool's own reply with it.

Backuply also records nothing in the database about how a run ended.
`backuply_last_backup` moves only on success, so a failure an hour ago and no
attempt at all leave the same trace. The adapter reads the log Backuply copies
aside when a job stops, which is what lets `wp_backup_status` say a backup exists
*and* that the last attempt failed.

A backup is not finished when the call returns. Backups take minutes to hours
and a tool call lives inside one request, so `wp_start_backup` starts one and
says in as many words that it has not finished. Nothing here ever reports that a
backup completed because of something it did.

The dangerous failure is a false yes. Every other guard in this plugin fails
closed, where a refusal costs an agent a sentence. This one would fail open,
because a tool claiming a backup exists when it does not makes an agent *more*
willing to do the irreversible thing. So "cannot tell" is a first-class answer,
returned rather than flattened into a no, and the two-step confirmation
*reports* the backup situation instead of gating on it. A gate would have to
pass whenever it could not read a provider, and a control that silently passes
is worse than an absent one because it gets counted.

A listing must not hand out the keys. `wp_list_backups` returns when each
backup finished, what it contains, how big it is and where it went. It never
returns the archive's filename or path, and that omission is the design rather
than an oversight. UpdraftPlus writes
`backup_<date>_<site>_<nonce>-db.gz` into `wp-content/updraft`, where a 48-bit
job nonce makes the URL unguessable. Backuply inverts the arrangement, with a
filename derivable from the timestamp inside a directory whose 36-bit suffix is
the secret, one suffix shared by every archive on the site.

Neither is the only protection, and the wording here used to say it was. Both
plugins drop an `.htaccess` saying `deny from all` in the directory, and Backuply
also writes archives mode 0600. Apache honours the first and nginx does not read
it at all, so on an nginx site the unguessable name is the last thing standing
rather than the only one. Either way the on-disk name is a capability rather than
a label, and a database archive holds every user row and password hash on the
site. So a backup is identified by when it finished, which answers every
question an agent has a reason to ask, cannot be turned into a URL, and reduces
neither secret.

That rule is enforced rather than asserted. `gmcp_backup_providers` is a public
filter, so a sentence promising no filenames would only describe the two
shipped adapters; every entry is reduced to the fields above whoever produced
it, unknown keys are dropped, and the two free-text fields are withheld if they
contain a path separator or an archive extension. This matters beyond the reply
itself, because the audit log stores it.

The same "cannot tell" rule applies. A provider this plugin cannot enumerate
gets said so, and the `backups` key is absent rather than empty, because an
empty list reads as "there are none". `total` is how many exist and `count` how
many came back, so a capped listing can say which it is rather than guessing
from the size of its own result.

Existing is not the same as usable. UpdraftPlus prunes archives to its
retention limit but keeps the history entry, so a set can be listed with most or
all of its contents gone. Each entry therefore reports what it actually still
holds, and the reply counts separately how many contain a database, because a
backup without one cannot put the site back.

A backup plugin's own option rows are not a way round any of this. Withholding
archive filenames from `wp_list_backups` was worth nothing while `wp_get_option`
would hand over the same plugin's configuration, and on a site with offsite
storage configured that meant the FTP password, the S3 access and secret keys,
and the archive encryption passphrase, which is the single thing making a stored
archive safe at rest. None of those row names contains `password`, `secret` or
`key`, so the credential heuristic matched none of them, and because the audit
log records a tool's response they were written to the database as well as
returned. Rows belonging to UpdraftPlus, Backuply, BackWPup, All-in-One WP
Migration and Duplicator are therefore refused by namespace, for reads and
writes alike, with a refusal that names the two tools that answer the same
questions safely. By prefix rather than by row, because those names change
between plugin versions. Narrow it with `gmcp_backup_option_prefixes` if a site
genuinely needs one of them.

Refusing the writes matters on its own, separately from the reading: an agent
that can rewrite `updraft_backup_history` can erase a site's record of its own
backups, which the test for this demonstrates by doing exactly that against the
unfixed code.

A backup can be narrowed. `wp_start_backup` takes a scope of full, database or
files, honoured by the adapters that can express it and refused with a reason
by those that cannot, because an adapter that silently ignored an unrecognised
scope and ran a full backup would tell a caller it had a database-only backup
when it did not. `files` deliberately means the file half of whatever this
site's plugin already backs up, rather than a narrower selection that would mean
uploads on one site and the whole install on another. Destination is not
offered, and that is a refusal rather than an omission: choosing where a
database dump is sent is not a decision to hand to something that reads
instructions out of a comment queue. The reply says the scope was asked for
rather than confirming what ran, because neither adapter reports back.

## Drafts-only keys, and OAuth that can only read

A named key can be marked drafts only. The point is a credential for an agent that
writes copy: it may fill the drafts folder, and it must not be the thing that puts a
sentence in front of the public, because the agent reads comments and pages that
strangers wrote.

**The policy belongs to the post, not to the tool.** Every tool that writes a post, its
meta, its terms or its featured image asks `GMCP_Core::drafts_only_guard()` (the
generic ones through `post_write_guard()`, which asks the commerce policy first), for
the same reason `commerce_post_guard()` exists: one tool enforcing it and the next
not is the defect the settings tool once had with options. Live means publish, future
or private now, or a status that would make it so; plugin-registered public and
private statuses count, and the comparison ignores case and trailing spaces, because
the posts table does and a stricter PHP check would call "Publish " a draft that every
query finds. Unpublishing a live post is refused as well as publishing: taking a page
down is a change to what the public sees. An attachment takes its parent's visibility,
so media attached to a live page is protected, and media attached to a draft can be made
and edited. Media attached to NOTHING can be uploaded but neither edited (`wp_update_media`,
or `wp_update_post` on an attachment) nor deleted: such a file can be a live page's featured
image or an embed, and nothing here can find the pages that use it, so the guard refuses the
case it cannot judge. An earlier version allowed the edit and said so in this paragraph;
retitling or re-captioning a file changes what every live page showing it displays.

**A net sits under the tools.** The tool-level checks give a clear sentence and stop
before anything is half written, but a list of tools is not a proof that none was
missed. When a request is authenticated by a drafts-only key, `restrict_caller_to_drafts()`
hooks WordPress itself: the final row about to be stored (posts and attachments, which
have separate filters; the attachment one was found missing by a test, not by reading),
post meta added, changed or deleted including by meta id, trash, restore, permanent
deletion, and term relationships. Each throws before anything is written, so Elementor,
Yoast, ACF, WooCommerce, the generated REST tools, menu items and the journal's undo
are held without knowing about it. It throws rather than short-circuiting with false
because a pre-filter that returns false makes the caller believe it wrote.

**What it does not do.** A plugin that updates the posts table with SQL, or calls
`wp_publish_post()` (which does), is not seen by any hook, and none of this plugin's
tools does either for content. That is why a drafts-only key is capped at read and
write: the groups that reach third-party code (options, menus, the page builders, SEO
and form plugins) are admin-level and are not offered to it. The
cap is applied when the key is made and again whenever the key is presented.

**What it covers, and what it does not.** Covered: posts and what is stored as posts
(pages, products, menu items), their custom fields, term assignments, featured image, and
attachments attached to a post. Not covered, so a drafts-only key can still do these:
create, approve and edit comments (`wp_create_comment`, `wp_update_comment`); create and
rename taxonomy terms (`wp_create_term`, `wp_update_term`: renaming a category changes every
live post in it, and deleting one needs `admin`); change users and options (`admin`, capped
away); act on orders and subscriptions (the generic post tools refuse those for every
key, the shop tools are `admin`); and share a draft by preview link, which shows it to
whoever holds the URL. Edits to media attached to no post are refused (see above), and a
draft can still embed media or shortcodes that render live data. An earlier version of the
refusal text and the key's label said the key cannot touch "anything that is live", which
was wider than any of this and has been narrowed to what is true. Pair the flag with a
tool list when the key should do less than that.

A refusal is an ordinary tool failure, so the audit log records it as a refusal under
the key's label. Keys made before the flag exist have no `drafts_only` field, which
reads as false.

**OAuth is no longer always full.** The consent screen offers Full access or Read-only,
with Full preselected because a changed default is a decision and not a side effect. The
level is stored in the grant's scope as a `mcp:readonly` marker. That reuses a column
that already travels with the grant, is copied by every refresh, and needs no schema
change; a grant made before has no marker and stays full. The level comes only from the
person's choice, never from the scope string the client asked for, and an unrecognised
value on the form fails narrow. The server used to exempt OAuth callers from the level
filter by their authentication method; that exemption is gone, so a read-only grant
is listed only read tools and is refused a write tool named anyway, in the same places a
read-only key is. The "still an administrator" check on every request is unchanged.

## No restore, no kit import

There is no restore tool at any access level. Restoring discards everything
since the backup, which is a larger irreversible act than anything else here,
and no confirmation token makes that safe to hand to something reading
instructions out of a comment queue.

There is no kit import either, and it is refused for the same reason rather than
because it would be hard. Elementor can import a kit from an archive, and doing
so rewrites a site's design system wholesale: global colours and fonts, theme
styles, site settings, and whatever content the archive carries. It is one call
that changes every page, the archive was built somewhere else, and there is no
restore tool standing behind it. Switching between the kits a site already has
is a different thing and `elementor_set_active_kit` does it, because those were
made here and the previous one is still there to switch back to. A site that
genuinely wants an import has the Elementor screen for it, where a person sees
what is about to happen.

## Considered and not built

Two things get proposed often enough to write down why they are not here.

**A bridge to the WordPress Abilities API.** Other plugins register abilities there, each
with its own permission callback, and a tool could expose them all to an agent. Every
guard in this plugin belongs to the thing it protects: `option_write_policy()` to the
option, `commerce_post_guard()` and `personal_data_post_guard()` to the post type,
`status_write_refusal()` to the status. An ability that writes an option or a post
reaches the same rows through a door none of those are asked at, and its permission callback
answers "may this user do this" for a person, not "should an agent be handed this". The
approval queue cannot classify it either, because it derives what a call does from the
tool's registration and a generic bridge registers one tool for all of them. If it is ever
built it has to be off by default, `admin` level, restricted to abilities that declare
themselves read-only, or else every call through it held for approval.

**Connections for roles below administrator.** `gmcp_oauth_user_can_authorize` already
lets a site widen who may approve an OAuth connection, but everything downstream assumes the
caller is an administrator: the tool set, the key's level cap, the approval screen, and the
rule that a key stops working when its account stops holding `manage_options`. An editor
connection needs its own, smaller tool set, drafts-only by default, and a check on every
tool for what that user may edit, not for what the key may call. That is a product decision
about who this plugin is for, not a missing feature.

Not built for the same reason as the group above: translation plugins other than Polylang.
See the WPML note under Redirection and Polylang.

## Deletion by another name

The `readwrite` level is defined as "create and update, no destructive tools", and the
approval queue holds a call by what its tool is. Both promises were true of the tool names
and false of the data. WordPress deletes on a schedule it reads from ordinary rows: every
day `wp_scheduled_delete()` permanently deletes each post that carries a
`_wp_trash_meta_time` older than `EMPTY_TRASH_DAYS`, and `wp_delete_auto_drafts()`
force-deletes each auto-draft whose `post_date` is more than seven days old. So a
`readwrite` key could set `post_status` to `trash` through `wp_update_post`, stamp the post
with a timestamp from 1970 through any meta writer, and wait for the daily job (or call
`wp_run_cron_event`, which was not held) to destroy it: a permanent delete with no
approval and no second call, from the level that has no delete tool. Confirmed by running
it, not by reading it.

- **A status that is a deletion is refused.** `wp_update_post` and `wp_update_comment`
  refuse a status of `trash`, `wp_update_post` refuses `auto-draft`, and `wp_update_comment`
  refuses `spam`, with a sentence pointing at `wp_delete_post` / `wp_delete_comment`. Spam
  is the comment's trash: Akismet, which ships with WordPress, permanently deletes every
  comment marked spam once it is fifteen days old, from a daily event it registers whether
  or not an Akismet key is set. Measured with Akismet active: an old comment moved to spam
  was gone after the next run, while the same comment left approved and a recent one moved to
  spam both survived. The cost is real and is accepted: an agent cannot mark spam at any
  level, so a comment-triage run recommends and a person marks it in wp-admin. Other
  anti-spam plugins that purge their queue behave the same way, and this refuses all of
  them without naming them. Creating a comment with a spam status is not refused, because
  the new comment is the agent's own and carries today's date. Refused rather than rerouted or held
  by argument, because the honest tool already exists, is held by its name, and a second
  door that merely behaves like it would have to be found and held again by every tool
  that ever takes a status. `wp_alter_post` and `wp_update_media` write no status, and the
  generated REST tools reject both values in the route's own enum (checked). Setting a
  trashed post back to `draft` still works.
- **The schedule itself is not writable.** Every meta key beginning `_wp_trash_meta_` is
  refused by every tool that writes post meta (update, delete, copy, chunk, `meta_input`
  on create and update). Matching is on the key as the database compares it, so case, trailing
  spaces, accents, fullwidth forms, `ß` and invisible or combining characters inside the prefix
  are all the same key (`GMCP_Meta_Key`, see the pointer rules above for how the fold was built
  and measured: `_wp_trásh_meta_time` is the schedule row on MariaDB). Reading one is allowed. Because other plugins write meta through the
  same three WordPress functions, the same refusal also sits under them while a tool call
  is in flight (`GMCP_Core::trash_meta_net()`), letting through only core's own
  `wp_trash_post`, `wp_untrash_post` and `wp_delete_post`, which write and clear the key
  themselves. Not protected, and why: `_edit_lock`, `_wp_desired_post_slug` and
  `_wp_old_slug` only name an editor, a slug to restore and a redirect from a slug the
  writer could have set directly; none removes anything.
- **The approval gate asks the arguments too.** `GMCP_Approvals::classify_call()` widens a
  call whose arguments carry a deletion status (`post_status`, `status` or
  `comment_approved`, top level or inside `fields`) to `destructive`, whatever the tool is
  called. It only widens, and a read tool that is merely listing trashed things keeps its
  class.
- **Running a scheduled event is destructive.** `wp_run_cron_event` fires every callback
  on a hook, and its own description says they can delete files. `run` is now one of the
  destructive verbs. `wp_unschedule_cron_event` already was.

## Human approval

The two-step confirmation on irreversible tools is minted by the server and handed to
whoever called, so an agent that was talked into a deletion by a comment it read makes
the second call itself. It raises the bar from one call to two. The approval queue is the
other kind of gate, and it exists because of that gap: the person, not the agent, supplies
the second half.

- **Where it sits.** In `execute_tool()`, after the key, level and required-argument
  checks and before dispatch. That function is the only place a `tools/call` reaches a
  tool: `handle_direct_jsonrpc()` is its one caller, and the resource layer only borrows
  read-level tools directly. A gate in a tool class would be one gate per class, which is
  how the option policy once protected one writer and not the others. A call refused
  earlier never becomes a request, and a held call has done nothing.
- **Nothing to steal.** No approval token exists. The agent is told a request id, which is
  a reference and grants nothing. The retry is matched on what it is (key, tool, keyed
  hash of canonical arguments) against a row that only `GMCP_Approvals::decide()` can move
  to approved, and `decide()` refuses unless the request is a wp-admin page load with a
  logged-in cookie belonging to the current user (a bearer key or OAuth grant sets the
  current user without a cookie), `manage_options`, a nonce bound to the request and the
  verdict, no tool call or REST request in flight, and an account that no tool call has
  created or re-credentialed in the last 24 hours (see "An account the agent made" below).
  A cookie proves a session, not a person: an agent that can make an administrator can
  sign in as one. There is no tool that reads,
  lists, approves, declines or edits the queue, and the setting and the table are not
  reachable through the option tools (`gmcp_` rows are refused by name; the queue is not an
  option at all).
- **What it binds.** Key, tool and arguments. The arguments are canonical JSON with object
  keys sorted, hashed with HMAC under the site's auth salt: arguments can hold a password,
  and a plain digest stored beside the row would let a database read confirm a guess. The
  one exception is `confirm`, dropped from the hash only when the tool declares it as its
  two-step token, so the retry that carries the token is the same call finishing and one
  approval covers both legs. `finish()` gives an approval back when the call only issued a
  token, with its original deadline, and never after a leg that ran.
- **Single use and lapse.** A pending request lapses after an hour, an approval after 15
  minutes. The claim is one `UPDATE ... WHERE status = 'approved' AND approved_until >= now`,
  so two retries racing each other cannot both run. A declined call is refused for 15
  minutes so an agent does not refill the queue with the same question. A queue is capped
  per credential and overall; hitting the cap is a refusal, not a pass.
- **What counts.** Derived from each tool's own registration, not from a list of names.
  `unknown` (no definition, or no recognised level) and `destructive` (a destructive hint
  or verb, which outranks the level) are held in both modes. In the wider mode so is
  `site`: any other admin-level write. A fourth class, `account`, waits in both modes (see
  below). `read` and `write` levels never wait. That relies on
  the README's definition of the `write` level as having no destructive tools, which
  `smoke-admin.sh` checks against the registered tools. Honest limits: a destructive admin
  tool with no hint and a verb outside the list is a site change, so it waits only in the
  wider mode; the `wp_upload_request` endpoint is not a tool call; a preview waits like the call it
  previews, because the setting cannot tell the two apart.
- **A tool can require a person in every mode.** `requiresApproval` on a tool's registration
  — `[ 'argument' => 'mode', 'values' => [ 'pull' ] ]`, or `true` for every call — makes
  `gate_inner()` queue the call whatever `mcp_approval_mode` says, `never` included. It sits
  on the registration rather than in a list of names kept beside the queue, and it can only
  add a wait: a definition without one behaves exactly as before, and a rule naming no
  argument or no value holds nothing. `wp_export_request` uses it for its `pull` leg, because
  an archive of `uploads` leaves the server and the site's approval mode is a setting somebody
  may have left at `never` for reasons that had nothing to do with files. The declaration
  covers the value it names and no other: the same tool's `manifest` leg is judged like any
  other admin tool — held in `deletes` and `site`, not held in `never` — which is why the
  registration is read, not the tool's name.
- **An undo is the write it repeats.** `wp_undo_change` is a write-level tool, so on its name
  alone it never waits, but it replays earlier values: in the wider mode a key held for
  `wp_update_option` could put an option back, unheld. `classify_call()` looks the recorded
  entry up (by `id`, or every entry of a `call`) and holds the undo as the strictest tool that
  made the change, using the same list the undo itself asks "can this caller call it" about
  (`GMCP_Journal::undo_tools()`). An entry it cannot find, a call naming both `id` and `call`,
  or a recorded tool with no registration here is `unknown`, held in every mode. So an undo of
  a post or meta edit waits where writing it would (never), and an undo of an option waits
  where writing the option does (the wider mode).
- **An account the agent made cannot answer for the agent.** In mode `deletes` an admin key
  could create an administrator with a password it chose (`wp_create_user` and
  `wp_update_user` were `site` changes, which wait only in the wider mode), sign in at
  `wp-login.php`, and approve its own held calls. Reproduced end to end. Two locks. First,
  `classify_call()` gives a user tool the `account` class, held in every mode but `never`:
  every creation, any update that touches a password, an address, a role (a demotion too:
  "the lowest role" is the site's own `default_role`) or a field outside the display fields,
  any update to an account holding a privileged capability, and any argument outside the
  tool's schema. Only a display-name-style edit of an ordinary account is left to the mode.
  Second, `watch_accounts()` records, in user meta, any account that a tool call created or
  whose password, address or role it changed, and `session_problem()` refuses that account
  the approval screen for 24 hours, with its own message on the screen and in `decide()`.
  This covers the call that got through the first lock (mode `never` at the time, a plugin's
  own tool). The cost is real: an administrator whose own password an agent changed, or
  who asked an agent to make them an account, approves from another administrator's account
  or waits a day. There is no button to clear it, because a button is the way round;
  `wp user meta delete <id> gmcp_changed_by_mcp` does. Mode `never` still holds nothing, and
  the plugin cannot make an agent that was told to create an administrator in that mode
  stop; it can only keep that administrator from approving anything. Not covered: a tool
  that changes an account in some way the user tools' names do not say.
- **What a person sees.** Exactly what the hash covers, or the call is not queued. The
  screen once printed the arguments in the order the client sent them, cut at 4000
  characters, with credential-shaped values hidden: three ways to show a different call
  from the one approved. `GMCP_Approvals::build_view()` now makes the one view, at queue
  time, and the screen prints it unchanged. Arguments are sorted (the tool's declared ones
  first, objects sorted all the way down). A call is refused, with a normal error the agent
  can read, if it carries an argument the tool's schema does not declare, if it is a list of
  unnamed values, if it nests more than 12 levels, or if its arguments written out exceed
  16000 bytes: an approval covers every byte, so it is not asked for what cannot be read.
  A credential-shaped field, and a JSON or serialized string with one inside, show as
  `[redacted]` whole. In any other string only the stretch the scanner matched, or a
  preview-link token, is replaced by `[redacted]`: the rest of the string stays readable,
  `[hidden values present: N]` is appended to it, and the screen names its path, so a person
  knows a value they cannot see is part of what they approve and can still read what
  surrounds it. This screen used to replace the whole string when the scanner matched
  anywhere in it, so a decoy that merely looked like a credential in a post body made the
  script tag beside it unreadable to the person deciding, and an agent that knew that could
  make any argument unreadable. Each string is judged on its own: a secret split across two
  arguments is not recognised by the view. The approval stays bound to the real arguments:
  building the view does not touch them and the keyed hash is computed from them (asserted).
  Bidirectional and
  zero-width characters are shown as escapes. Rows stored before this existed are labelled
  as possibly incomplete.
- **A held call is not a success.** The reply is a normal result, not `isError`, so a
  client does not send the agent looking for a workaround; the audit log records the call
  as refused, because nothing ran. Requests, approvals, declines, lapses, uses and refused
  replays are rows in the same hash chain (`approval:requested`, `approval:approved`, ...).
  If the audit log is switched off they are not written, and the queue says so.
- **No bulk and no delete.** Same reasoning as the audit log: an approval is a decision
  about one call, and a button that answers fifty is pressed without reading. Settled rows
  are pruned daily after 30 days; the audit log keeps the history.

## Prompts and resources

The server offers six ready-made upkeep jobs through MCP prompts, and publishes
recent posts, the comment queue and the site briefing as MCP resources a client
can attach to a conversation. Every resource is backed by a tool and gated by
it, so a resource is never a softer route to data than the tool it mirrors.

Optionally the plugin can also generate tools from the site's own REST API
routes. That is off by default because it is a large, generic surface next to
the curated tools.

They are generated once and cached for a day, and that cache is thrown away on
the first request after the plugin's version changes. It has to be: the cache
does not only fill the tool listing, it gates dispatch, so before this a tool
added by an upgrade was not merely missing from the list for twenty-four hours,
it could not be called, and the failure read as "unknown tool" with nothing to
point at the cause. A client holding its own stale copy of the tool list is a
separate problem and not one a plugin can reach from here.

Those generated tools return whole REST records, which is more than a model
usually wants: rendered content it did not ask for, and a block of `_links` per
row it has no way to follow. They take `_fields` for that, naming the fields to
return and nothing else, and the difference is not marginal. Three empty pages
come back as 4556 bytes whole and 186 with four fields named, and the gap widens
with real content rather than closing. The parameter is WordPress's own and
always worked; it was simply not declared on the generated schemas, so nothing
reading a tool list could discover it. `wp_get_posts` remains the lighter tool
when a plain list of posts will do.
