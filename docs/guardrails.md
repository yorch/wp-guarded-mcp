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
  The path is looked at without following a link (`lstat`) before it is opened, so a
  link, a pipe or a device is refused untouched (a FIFO would otherwise block the
  worker on open), and the handle that was opened is compared with what was looked
  at by device and inode and must be a regular file, which catches a file swapped in
  between. PHP cannot open with `O_NOFOLLOW`, so the window between the two calls is
  narrowed and not removed, and a site where an attacker can write to the log
  directory is outside what this protects.
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
  SQL query past its first 120 characters. Paths are shown as `[content]/` and
  `[abspath]/`. This recognises shapes. A secret written into an ordinary
  sentence survives it, and so does personal data in a message PHP assembled from
  a form, which is why the tool is `admin` and not `read`.
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
  was checked, so the answer cannot change between the check and the connect. If
  the pin cannot be applied (the HTTP transport is not cURL, or another plugin
  short-circuits the HTTP API) the response is discarded. Link-local, reserved
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
  scrub to the reply it keeps. It does not scrub what a tool returns to its caller:
  a stored theme mod or field that happens to contain the text is the site's own
  data, and the readers pass `scrub_preview = false` so it comes back as stored. The
  debug trace prints a call's arguments through `redact_call_args()`, the same answer
  the audit log gives, so a value under a credential-named option or meta key is not
  printed there either. Without it the tool that hands the link out would
  have written it into the audit log in the clear in the same call, which the
  first test of it showed. The fetch tool refuses a URL carrying the parameter
  for the same reason: it would be recorded as an argument, and a preview
  credential has no business being fetched by a tool that cannot show a draft
  anyway.
- Not closed, and said so: viewing a link is not logged (only issuing and
  revoking are); anyone who is given the link can forward it; a link pasted into a
  post body or a message is outside what the audit log's scrub can see; the
  window between the address check and the connect is closed by pinning only
  where the HTTP transport is cURL. A proxy is never used for the fetch: libcurl
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
  submissions under.** This is the part that is not in the new file, and it is there because
  without it the rest is a sentence about the tool and not about the data: Flamingo and Ninja
  Forms keep a submission as a post, `wp_get_post_meta` is a `read`-level tool that returns
  any key on any post (its own source says post meta reads are ungated), and a read-only key
  could therefore read every answer, and for Flamingo the visitor's IP address, through the
  tool beside the admin-only ones. `GMCP_Core::personal_data_post_guard()` is asked, ahead of
  the dispatch switch, by `wp_get_post`, `wp_get_post_snapshot`, `wp_get_post_meta`,
  `wp_read_post_meta_chunk`, `wp_alter_post` (a match count over a body is an oracle) and the
  previews of `wp_update_post` and `wp_delete_post` (which print a title and the start of the
  body), for the post type `wp_get_posts` names, and per row in a `wp_get_posts` listing (type
  `any` names none). The `gmcp://post/{id}` resource and `wp_create_preview_link` ask it too.
  It is on whether or not the forms group is. The type list is filterable through
  `gmcp_personal_data_post_types`.
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
- **What the guards do not cover.** They are a list of readers, not a property of the data,
  and the same weakness the commerce guard has: a reader added later has to be added to it.
  Not covered today: `wp_get_post_terms` and the terms of a post in the resource header
  (channel terms only), the REST-route tools when that group is on and a plugin exposes the
  type through REST, the change journal (it records a changed meta key and withholds values
  that look credential-shaped, and only for posts a tool changed), and every route to the
  database that is not a post reader. WooCommerce orders stored as posts ARE covered by
  `commerce_post_read_guard()` in `wp_get_posts`, `wp_get_post`, `wp_get_post_snapshot`,
  `wp_get_post_meta`, `wp_read_post_meta_chunk`, `wp_get_comments` and, now, the copy tools
  and the resource; that guard applies below admin level only, whereas the form-submission
  guard applies at every level (an admin key uses the form tools).
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
unit, because creations are not journalled, and the reply says so and points at
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

Undo records modifications and not creations or deletions, of posts, terms,
menus, menu items or users, and that is a real limit rather than a nicety. The
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
log by itself. Credential-shaped keys and values are judged by the same two
tests as a meta value and are recorded as changed without a copy.

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
write, never as a second copy of it. For a custom field it covers every row of the
key, not the first, and an undo that would write one recorded value is refused when
the key now holds several rows, because that write rewrites every row. Where the
value the undo is about to overwrite holds a credential, the undo is recorded as
not reversible instead of being copied, for the reason the original is not; that
test runs for options, custom fields, theme settings and terms. A post's own fields
(title, content, excerpt) are not tested for credential shapes, here or when the
original edit is recorded. That is a gap, not a decision this document defends.

Previous meta values live in a table of their own rather than in the journal's
option row, because `_elementor_data` runs past 100KB and the option's per-value
ceiling is 64KB, so sharing the budget would have recorded every Elementor edit
as too large to keep. The table is pruned by age and by total size on the same
daily event the audit log uses, and a snapshot that has expired makes its entry
report the copy as gone rather than silently putting back something stale. A
value past a megabyte, or one whose key name or contents look like a credential,
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
  on create and update). Matching ignores case and trailing spaces because the column
  compares that way. Reading one is allowed. Because other plugins write meta through the
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
  A credential-shaped field, a string that holds one, and a preview-link token show as
  `[redacted]`, and the screen says how many values are hidden and names their paths, so a
  person knows a value they cannot see is part of what they approve. Bidirectional and
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
