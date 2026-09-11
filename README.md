# Guarded MCP

A [Model Context Protocol](https://modelcontextprotocol.io) server for WordPress, so an AI agent such as Claude Code or Claude Desktop can administer your site through conversation.

It is built on one assumption: the agent will occasionally get it wrong. An agent administering your site also reads your comments, your post bodies and your plugin descriptions, all written by anonymous people, and it has no reliable way to tell an instruction from content. So this hands an agent everything an administrator can do, and puts a guard on each of the operations you would not want done on a misread instruction.

This is a fork of the MCP layer of [AI Engine](https://wordpress.org/plugins/ai-engine/) 3.7.7 by Jordy Meow, stripped of everything that is not the MCP server. GPLv2 or later, same as the original. See `CREDITS.md` for what was kept and what changed.

## What it is

One REST endpoint, `/wp-json/mcp/v1/http`, speaking the MCP Streamable HTTP transport, with WordPress tools behind it. No chatbots, no AI provider keys, no front-end assets. The plugin never calls an AI model itself: your agent does that, and this is what it reaches into.

It also speaks the parts of MCP most servers skip: prompts, so your client offers a menu of upkeep jobs, and resources, so a person can attach a post or the comment queue to a conversation directly.

## Requirements

- WordPress 6.0 or newer
- PHP 8.1 or newer

## Install

Build the zip with `.dev/build.sh`, then upload it through Plugins, Add New, Upload
Plugin. Or copy the directory into `wp-content/plugins/guarded-mcp` and activate it.

```
wp plugin install /path/to/guarded-mcp.zip --activate
```

**The directory must be named `guarded-mcp`.** Not `wp-guarded-mcp`, not
`wp-guarded-mcp-main`, not `ai-engine`.
The plugin derives its own identity from the folder through `plugin_basename()`, and the
guard that stops an agent deactivating or deleting the plugin mid-call compares against
that. Rename the folder and the self-protection silently stops matching. A GitHub
"Download ZIP" gives you `wp-guarded-mcp-main`, so rename it if you go that route. The
repository is named `wp-guarded-mcp` and the plugin `guarded-mcp`; only the second name
matters to WordPress.

Then open **MCP Server** in the admin menu.

## Connecting an agent

The settings screen shows the endpoint URL. There are two ways in.

**OAuth**, for clients that support it (Claude Desktop, the Claude web connector). Paste the endpoint URL into the client. It discovers the authorization server, sends you to a WordPress login, and shows a consent screen. Nothing to configure, and no shared secret. Only administrators can approve a connection, and the resulting token keeps working only while that account is still an administrator.

**A named key**, for clients that cannot do OAuth, such as a CLI agent. Create one on the Access tab and give it to the client. A key is shown once and stored only as a hash, so keep it wherever the client keeps its configuration:

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "http",
      "url": "https://example.com/wp-json/mcp/v1/http",
      "headers": { "Authorization": "Bearer YOUR_TOKEN" }
    }
  }
}
```

If your host strips the `Authorization` header before PHP sees it, the plugin also registers `/wp-json/mcp/v1/<token>` as an alternative path. Prefer the header when it works.

## Access levels

The token access level applies to bearer-token callers only. OAuth callers always act as the administrator who approved the connection.

| Level | Content only | + administration | + WooCommerce | What it can do |
|---|---|---|---|---|
| `admin` | 48 | 81 | 93 | Everything, including deletes, users and options |
| `readwrite` | 36 | 47 | 56 | Create and update, no destructive tools |
| `readonly` | 17 | 27 | 31 | Reads only |

A **named key** narrows this further. It carries its own level, an optional expiry date,
and an optional list of the only tools it may call, so a key handed to a deploy script
can be limited to reading posts and nothing else. Keys are stored hashed and shown once.
There is no shared token. There was one, and it was retired rather than hardened: it sat
in the options table in the clear because the screen showed it back, it carried no identity
so the log could not say who acted, it could not expire, and it could not be limited to
anything. A key answers all four, and an existing shared token is carried over into one on
upgrade so nothing stops working.

## Tools

**Content and site data**, on by default: posts and pages, block content, taxonomies and terms, comments, media (including upload by URL or by a one-time upload link), users, post meta, site options, post types, block patterns.

Four of those exist because a value can be too large to survive a tool argument. `wp_copy_post_meta` and `wp_duplicate_post` copy inside PHP, so a page design of 100KB never leaves the server. `wp_write_post_meta_chunk` is the general answer, staging a value across several calls and writing the meta row only on the last one, so a half-written value is never on the post for something else to read as finished. `wp_read_post_meta_chunk` is its mirror, so the round trip closes: it walks a value by byte offset, says how large the whole thing is and whether more remains, and returns each piece as base64. A single piece is capped at 256KB, and that number is a policy rather than a limit: nothing failed in testing until the memory limit was lowered well below a stock host's, and on a normal one the tool will hand back any meta value the database can deliver. A duplicate is a draft unless you ask otherwise, because a copy that inherits `publish` goes live on a misread instruction.

Escaping used to be a second reason to reach for those, and is no longer. `update_metadata()` unslashes whatever it is handed, so a value carrying backslashes was stored stripped: a regex stopped matching, a Windows path lost its separators, and a JSON payload stopped parsing, while the tool still answered that the meta was updated. The chunk writer had always compensated and `wp_update_post_meta` had not, so the same bytes were stored two different ways depending on which tool you asked. Both now go through one function that slashes the value and decodes a JSON string for an array, which also means a small array no longer needs the chunk API. Size is the only remaining reason to prefer it.

The base64 on the read side is not fussiness either. A chunk boundary falls wherever the byte count lands, which is routinely inside a multi-byte character, and a half-character is fine only if nothing tries to repair it. Sent as text it does not survive: `wp_json_encode` hands invalid UTF-8 to WordPress's own sanity check, which substitutes a placeholder and reports no error, so a slice ending on the first byte of an emoji comes back the same length with that byte turned into a question mark. Every cheap check passes and the reassembled document differs from the stored one. Base64 carries those bytes through untouched, and each chunk also carries a hash of the whole value, so a caller can tell that the value was rewritten under it mid-walk and that what it reassembled is what was stored.

**Site administration**, off by default: installing, activating, updating and deleting plugins and themes; navigation menus and their items; widgets and widget areas; the General, Reading and Discussion settings; the permalink structure; the site's scheduled events; and a Site Health report. These install code and change how the site renders, so they are opt-in and carry their own guards:

- Installs come from the wordpress.org repository by slug. An arbitrary ZIP URL is refused unless the site opts in through the `gmcp_allow_remote_install` filter, and the download host is checked so a plugin cannot rewrite the repository's answer. If you do open that filter, note that the URL you approve is the one before redirects: `download_url()` follows up to five, and WordPress only blocks non-HTTP schemes, odd ports and IPv4 private ranges along the way. Allowlist hosts you control, and be aware an open redirect on one of them defeats the check.
- Deleting a plugin, a theme or a menu takes two calls, as does changing the administration email. The first changes nothing and returns a token bound to that exact target; only the second proceeds, so a single instruction cannot complete one, which matters because this agent reads comments and post content that other people wrote. Content deletions are not on that list: they usually go to the trash and can be restored, and the irreversible form, `force: true`, is one call. Use `preview` on it to see what would go, including the comments and attachments that go with it. Usually, because two ordinary situations have no trash to go to: WordPress has none for attachments, and a site with `EMPTY_TRASH_DAYS` set to 0 has none for anything. A call without `force` then destroys the thing, so the reply says which of the two happened rather than reporting both as "deleted", and the audit log records the same sentence.
- The plugin refuses to deactivate or delete itself, to delete the active theme or its parent, and to activate a theme this server cannot run.
- Options can be deleted, not only set. A stale cache is sometimes clearable only by removing the row, and some code treats an empty array as computed and so never rebuilds; Elementor's theme-builder conditions are exactly that. Nine options are refused, each carrying the sentence for what breaks if it goes, and so is anything the shared write policy already refuses to change, since deleting a row is the harsher edit of the two and a key too dangerous to set cannot be safe to drop. `rewrite_rules` is deliberately not among them, because WordPress regenerates it and deleting it is an ordinary repair. A deletion is in the audit log but cannot be undone: WordPress passes only the name to `deleted_option`, so nothing keeps the value. The reply therefore carries the value that was removed, since it is the only copy anyone gets, withheld when it looks credential-shaped by the same test that keeps such values out of the journal.
- `wp_flush_cache` purges the object cache, expired transients, or one post. It follows the same rule as everything else here about caches: purge what can be named, and say what could not. The reply lists the page-cache plugins it recognised and then names, in as many words, the CDN or reverse proxy that no PHP can reach and that the caller still has to purge. A cache tool that implied the front end was now fresh would be worse than none, which is why that half of the answer is as prominent as the first.
- Scheduled events can be listed, run and removed, which is what a cron event Site Health keeps flagging needs. Only events the site itself already scheduled can be run: a tool that fires any hook you name is a tool for running arbitrary code on an instruction, and hook names arrive in the same text as everything else. This plugin's own housekeeping is refused outright. The listing says whether each hook still has a callback, because an event orphaned by a deactivated plugin can never succeed and is the usual reason one keeps failing, and it says whether cron runs on this site at all, since when it does not every event is overdue by design. Removing an event takes two calls, and the undo journal cannot put it back: WordPress passes only the name to `deleted_option`, so nothing records what the schedule held.
- Post and widget content is always filtered, regardless of the caller's capabilities. WordPress normally lets an administrator store raw HTML, but the caller being an administrator says nothing about who wrote the markup, and these tools sit at the `write` access level, so a deliberately limited token could otherwise plant a script on a public page. Blocks, shortcodes, inline styles and `data-` attributes all survive; `<iframe>`, inline `<svg>`, `<style>` blocks and Outlook conditional comments do not, unless you open `gmcp_allow_unfiltered_post_html`.
- Block markup is filtered structurally rather than with `wp_kses_post` alone. Gutenberg escapes quotes and angle brackets inside block attributes as HTML entities, and kses does not recognise such a delimiter comment: it escapes the opener and destroys the block. Only the rendered HTML inside each block is filtered, and the attributes round-trip as JSON.
- The default role for public registration is checked by construction: a role granting anything beyond a subscriber is refused, rather than checking a list of capabilities that would never stay complete.
- Changing the administration email takes a confirmation step and is rate limited, because it mails an arbitrary address from your domain with body text drawn from the site title.
- Menu items refuse draft, private and password-protected targets, since a menu item stores its own copy of the title and WordPress renders it regardless of the target's status.
- A menu item can be edited rather than rebuilt, and that is harder than it sounds. `wp_update_nav_menu_item()` blanks every field you do not name, so a rename clears the URL and the item stays in the menu and quietly stops working. The obvious repair, reading every field back and writing it all in, introduces a second fault: a position of `0` means "not specified, append", while the first item of any menu is genuinely stored at `0`, so renaming the top item moves it to the bottom. The fields are read from the post row and its meta rather than through `wp_setup_nav_menu_item()`, which would turn a title derived from the target into a stored one, and the position is pinned on its way into the write rather than corrected afterwards, because `menu_order` is a field the change journal watches and a correction would have the log describe a move that never happened.
- Re-parenting is checked before it is done. An item under itself, an item under its own descendant, or a parent in another menu each break a menu with no error anywhere, the last by removing the item from the rendered menu while leaving it in the database. The walk up the parent chain is bounded as well as checked, so a menu that already contains a loop cannot hang the request.
- A new menu can be given its slug. WordPress derives one from the name and appends a number when it is taken, and the slug is what an Elementor Nav Menu widget stores, so a menu created as `main-menu-2` leaves that widget pointing at the other menu and rendering nothing. A taken slug is refused, naming what holds it, rather than accepted with a suffix.
- `wp_menu_health` reports what is wrong with the menus and changes nothing: items whose target is missing or unpublished, items orphaned by a deleted parent, theme locations with no menu, and every Elementor template or page whose stored design names a menu slug, saying whether that slug exists. That last one turns a silent failure into a sentence, since a widget pointing at a missing slug renders an empty nav with no error on the front end or in wp-admin.
- Settings are an allowlist, not a blocklist. `siteurl` and `home` are refused outright, since a wrong value makes the site and this endpoint unreachable with no way back. A default role that can edit content is refused, because open registration plus an editing default role is a way in.
- Those refusals belong to the option, not to the tool that asks. Every one of them lived in the settings tool and nowhere else, so naming the same row through the generic `wp_update_option` went straight through: the site URL, the administration email past the confirmation that exists to protect it, and a registration default of `administrator`. Undo was a third way in, since putting a value back is still writing it. All three now ask one policy, and a tool added later gets the rule by asking rather than by remembering to reimplement it.
- There is no token-in-URL endpoint any more, so there is no reduced ceiling to describe. It existed for hosts that strip the `Authorization` header, and it put the credential in the request path, where every proxy and web server in front of the site wrote a copy into its access log, one per request. Measured on a development site: 27 copies of a working administrator credential in the access log, and none in the plugin's own debug trace. What made it removable rather than merely unwise is that the plugin recovers the header from `REDIRECT_HTTP_AUTHORIZATION` and `apache_request_headers()`, which is where Apache usually hides it.

None of this plugin's own rows are readable or writable through the option tools, so the bearer token cannot be read back out or overwritten through the API. Any option name containing `gmcp_` is refused, anywhere in the name rather than only at the start, which is deliberate: the one-time plaintext of a newly minted key lives at `_transient_gmcp_new_key_<user>`, and a rule anchored to the start of the name would miss the row it most needs to catch. It replaced a list of exact names, which had been wrong twice: the change journal was readable until somebody named it, and that transient was never on it.

**WooCommerce**, off by default, and the switch only appears when the shop is installed: products, stock levels, orders, order notes, customers, a sales summary and a store briefing. Separate from site administration because the risk is a different shape. The administration tools can break a site; these read customer names, email addresses and delivery addresses and hand them to a model, which is a decision a shop owner should make deliberately rather than inherit.

- Refunds are not included. `wc_create_refund()` moves money through the payment gateway, and nothing here can put that back.
- Anything that emails a customer reports who was actually written to. The reply names the address if it was the customer's, measured by watching `wp_mail` during the change rather than predicting it from the status. Predicting it does not work: whether a move to `refunded` mails anyone depends on the transition rather than the target status, `on-hold` mails the customer and is what shops use for bank transfers, and `cancelled` mails only the shop. A hardcoded list was wrong in both directions and would go stale anyway as shops add statuses. Setting the status to refunded marks the order and moves no money.
- WooCommerce tools carrying personal data are `admin`, not `read`. `wp_get_users` is admin and returns no email address at all, so orders and customers, which carry names, email addresses and postal addresses, cannot sit below it. Products, stock and sales figures stay at `read`. This is not a claim about every tool everywhere: `wp_get_comments` is `read` and returns the commenter's name, because a comment is published text and its author is on the page already.
- Everything goes through the WooCommerce CRUD classes rather than posts and meta, because High-Performance Order Storage moves orders out of `wp_posts` entirely. A tool built on post queries works on a fresh install and returns nothing on most real shops.
- A new product is created as a draft unless you ask otherwise, so a product with no price is never briefly for sale.

**Elementor**, off by default, and the switch only appears when Elementor is installed: theme-builder conditions, regenerating Elementor's CSS, and putting a library template on a page. It exists because doing any of it through the generic post and meta tools appears to work and does not.

- A header or footer is applied by two rows, not one. Elementor keeps a cached registry of which template applies where in an option of its own, separate from each template's conditions meta, and saving in the editor writes both. Writing only the meta leaves the cache stale, and Elementor declines to rebuild it whenever the stored value is already an array: an empty array reads as computed with nothing in it, so a header set up that way is invisible forever and no amount of reloading the front end fixes it. These tools read both halves, say whether they agree, and change them together. Where Elementor Pro's own conditions manager is reachable they ask it to rebuild, since that is the call the editor makes; otherwise they delete the cached option, because an absent value is the state Elementor heals from and an empty array is the one it cannot.
- The design itself is `_elementor_data`, a JSON string routinely over 100KB. `update_metadata()` unslashes whatever it is handed, so a value that went out through a tool argument and came back would lose every escape in it and return a broken document. Size was never the only problem, and the corruption is silent. `elementor_apply_template` moves it inside PHP so it never leaves the server. It defaults to the shortcode form, which keeps the page linked to the template so later edits to the template propagate rather than freezing a copy, and copies the design only when asked.
- That link can be asked about. `elementor_template_references` answers what still points at a template, covering both the shortcode written into a page's content and a template embedded inside another page's design, which is how Elementor's own widgets do it. The match is exact rather than textual: the shortcode is parsed and the design decoded, so template 12 is not reported as a reference to template 1. It answers even when Elementor is not active, which is when the answer is worth most, because every referencing page has just started printing its shortcode as literal text. Applying a template now also warns when it is replacing an existing link and when the template is not published, since Elementor renders nothing for a draft and the page shows an empty space.
- Conditions need the theme builder, which is Elementor Pro or PRO Elements. On a site with only the free plugin the rows are still written, nothing reads them, and the tools say so rather than reporting a success the site will never show.
- Elementor's internals are not a stable contract across versions, so every call into one of its classes is guarded and reports what was missing instead of fataling. A wrong guess fails benignly.

**Orientation.** `wp_site_briefing` answers "what am I looking at" in one call: versions, theme, active plugins, post types with counts, taxonomies, the front page arrangement, the comment queue, users by role and the permalink structure. `wc_store_briefing` does the same for a shop. Both replace the half-dozen queries an agent otherwise makes before any work starts.

**Preview.** The six tools whose effect is not visible from the call accept `preview`, which describes what would happen and changes nothing: `wp_delete_post`, `wp_update_post`, `wp_alter_post`, `wp_delete_term`, `wp_delete_media` and `wp_delete_comment`. It matters most for `wp_alter_post`, where a search and replace reports success whether it matched everything or nothing.

Two things make that preview trustworthy rather than decorative. It compiles the pattern through the same function the write uses, so it cannot describe a different pattern. And it works out each replacement by expanding the reference forms against the groups the match actually captured in context, rather than re-running the pattern over the matched fragment on its own. The second one matters more than it sounds: a pattern like `(?<=foo)bar` matches in the real body but not in the fragment `bar` taken alone, so the naive version reported that the text would be left unchanged and the write then changed it. A preview that reports safety wrongly is worse than no preview.

It also counts matches without collecting them. A pattern of `.` against a 400 KB post is 380,000 matches, and building an entry for each in order to display ten exhausted the memory limit, which made the cautious call more dangerous than the write it was protecting.

**Undo.** `wp_list_changes` and `wp_undo_change` put back a setting or post an agent
*modified*. The change capture layer listens to WordPress rather than to the tools, so it
also covers widgets, which live in options, and menu items, which are posts. Only writes
made during a tool call are recorded, never a person's own edits.

That layer is shared with the audit log, which wants the same facts from the other side:
the journal keeps the previous value so it can put it back, the audit log keeps a
description of the difference so a reader can see what moved. Two copies of the same diff
would drift, and the day somebody added a field to one list the other would quietly stop
mentioning it.

Undo records modifications and not creations or deletions of posts, and that is a real
limit rather than a nicety. The capture layer reports both and the journal declines them:
putting back a creation means deleting something, and putting back a deletion means
recreating it, and neither is the same write in reverse. Users, comments, terms, plugins,
themes and media are reported too and journalled none of them, for the same reason. The
audit log has all of it, which is the difference between the record and undo. Deleting a
post without `force` puts it in the trash, where WordPress can restore it, which covers
the most common case by accident rather than by design.

Post meta is journalled, and adding or removing a field is journalled with it, unlike a
post. Those two really are the same write in reverse: the opposite of adding a key is
removing it. It is journalled because on a page-builder site the meta *is* the work, and
an undo log that covered everything except `_elementor_data` missed the changes that
mattered most on exactly the sites this gets used to build. The noise that used to be the
argument against it is handled by a short filterable list, `gmcp_meta_noise_keys`, holding
the keys that say who is editing rather than what the post holds and the ones Elementor
derives from the document and regenerates on demand.

Previous meta values live in a table of their own rather than in the journal's option row,
because `_elementor_data` runs past 100KB and the option's per-value ceiling is 64KB, so
sharing the budget would have recorded every Elementor edit as too large to keep. The
table is pruned by age and by total size on the same daily event the audit log uses, and a
snapshot that has expired makes its entry report the copy as gone rather than silently
putting back something stale. A value past a megabyte, or one whose key name or contents
look like a credential, is recorded as changed with no copy kept and says which.

Reverting is gated twice: on the tool that made the change, and on the operation the revert will perform, derived from the entry's own kind. Both are needed, because the recorded tool is whatever was in flight rather than what wrote the row. A plugin hooked on `save_post` that writes an option produces an option entry attributed to `wp_update_post`, and gating on that name alone let a write-level caller replay an admin-level option write.

Credential-shaped leaves are not stored, judged by the field names inside a value through `gmcp_credential_field_patterns` as well as by the option's own name. The rest of the value is: the shape is kept and only those leaves are blanked, so the change stays reversible and the entry says the restore will be partial. Undo then puts back everything that was recorded and leaves each blanked leaf exactly as it is now, because writing the placeholder over a live credential would destroy the secret the blanking exists to protect. That matters more than it sounds: the patterns match as substrings, so `key` also matches `keywords` and `monkey` and `auth` also matches `author`, and dropping the whole value cost undo to any option merely containing a field so named. Elementor's icon registry, which stores an icon name under `key`, was the case that surfaced it.

Some values still cannot be snapshotted and keep the older all-or-nothing answer: an object anywhere inside one, because restoring an array copy would put back a different type than was there, and anything nested past the depth limit. Those are recorded as changed and refuse to revert, saying so.

Two limits worth knowing. The check is structural, so a secret held as a bare string under an innocuous option name is still stored; a string that parses as JSON or as a serialized array is unpacked and judged, and blanked whole if it holds one. And it is not retroactive: adding an option to `gmcp_protected_options` refuses future reverts but does not scrub what is already recorded, so clear the journal after protecting something that was previously being written.

**Backups.** `wp_backup_status` reports what is known, `wp_list_backups` says which backups
exist, and `wp_start_backup` asks the site's backup plugin to start one. Three things shape
this more than the integration does.

There is no common interface. Sixteen backup plugins with no dominant one, and the largest
work in unrelated ways: UpdraftPlus fires a WordPress action, Backuply writes a job record
and leaves a cron hook to pick it up, BackWPup wants a secret URL the site owner must first
enable with a filter, and several keep scriptable export behind a paid tier. So there are
working adapters for UpdraftPlus and Backuply, detection for BackWPup, All-in-One and
Duplicator that names them and says why it cannot drive them, and `gmcp_backup_providers`
for anything else. When more than one drivable plugin is active the first listed wins, and
UpdraftPlus is listed first, so adding a provider never moves an existing site onto it.

The route a plugin's own screen uses is often not a route. Each adapter is written against
what a token-authenticated REST request actually has, which is not what the button calls.
Backuply is the clearest case: the handler behind its Create Backup button lives in a file
the plugin includes only when `wp_doing_ajax()`, and that handler then calls the site back
over HTTP forwarding the administrator's browser cookies to a second handler that checks
`current_user_can`. Neither half survives the trip, and no nonce fixes that. What is
registered on every request is the cron hook Backuply runs its own unattended backups
from, so the adapter writes the same job record the button writes and queues that, then
kicks cron rather than waiting for the next visitor. It has to be a different request:
everything under Backuply's runner ends in `die()`, so calling it inline would take the
tool's own reply with it.

Backuply also records nothing in the database about how a run ended. `backuply_last_backup`
moves only on success, so a failure an hour ago and no attempt at all leave the same trace.
The adapter reads the log Backuply copies aside when a job stops, which is what lets
`wp_backup_status` say a backup exists *and* that the last attempt failed.

A backup is not finished when the call returns. Backups take minutes to hours and a tool
call lives inside one request, so `wp_start_backup` starts one and says in as many words
that it has not finished. Nothing here ever reports that a backup completed because of
something it did.

The dangerous failure is a false yes. Every other guard in this plugin fails closed, where
a refusal costs an agent a sentence. This one would fail open, because a tool claiming a
backup exists when it does not makes an agent *more* willing to do the irreversible thing.
So "cannot tell" is a first-class answer, returned rather than flattened into a no, and
the two-step confirmation *reports* the backup situation instead of gating on it. A gate
would have to pass whenever it could not read a provider, and a control that silently
passes is worse than an absent one because it gets counted.

A listing must not hand out the keys. `wp_list_backups` returns when each backup finished,
what it contains, how big it is and where it went. It never returns the archive's filename
or path, and that omission is the design rather than an oversight. UpdraftPlus writes
`backup_<date>_<site>_<nonce>-db.gz` into `wp-content/updraft`, where a 48-bit job nonce
makes the URL unguessable. Backuply inverts the arrangement, with a filename derivable from
the timestamp inside a directory whose 36-bit suffix is the secret, one suffix shared by
every archive on the site.

Neither is the only protection, and the wording here used to say it was. Both plugins drop
an `.htaccess` saying `deny from all` in the directory, and Backuply also writes archives
mode 0600. Apache honours the first and nginx does not read it at all, so on an nginx site
the unguessable name is the last thing standing rather than the only one. Either way the
on-disk name is a capability rather than a label, and a database archive holds every user
row and password hash on the site. So a backup is identified by when it finished, which
answers every question an agent has a reason to ask, cannot be turned into a URL, and
reduces neither secret.

That rule is enforced rather than asserted. `gmcp_backup_providers` is a public filter, so
a sentence promising no filenames would only describe the two shipped adapters; every entry
is reduced to the fields above whoever produced it, unknown keys are dropped, and the two
free-text fields are withheld if they contain a path separator or an archive extension.
This matters beyond the reply itself, because the audit log stores it.

The same "cannot tell" rule applies. A provider this plugin cannot enumerate gets said so,
and the `backups` key is absent rather than empty, because an empty list reads as "there
are none". `total` is how many exist and `count` how many came back, so a capped listing
can say which it is rather than guessing from the size of its own result.

Existing is not the same as usable. UpdraftPlus prunes archives to its retention limit but
keeps the history entry, so a set can be listed with most or all of its contents gone. Each
entry therefore reports what it actually still holds, and the reply counts separately how
many contain a database, because a backup without one cannot put the site back.

A backup plugin's own option rows are not a way round any of this. Withholding archive
filenames from `wp_list_backups` was worth nothing while `wp_get_option` would hand over
the same plugin's configuration, and on a site with offsite storage configured that meant
the FTP password, the S3 access and secret keys, and the archive encryption passphrase,
which is the single thing making a stored archive safe at rest. None of those row names
contains `password`, `secret` or `key`, so the credential heuristic matched none of them,
and because the audit log records a tool's response they were written to the database as
well as returned. Rows belonging to UpdraftPlus, Backuply, BackWPup, All-in-One WP
Migration and Duplicator are therefore refused by namespace, for reads and writes alike,
with a refusal that names the two tools that answer the same questions safely. By prefix
rather than by row, because those names change between plugin versions. Narrow it with
`gmcp_backup_option_prefixes` if a site genuinely needs one of them.

Refusing the writes matters on its own, separately from the reading: an agent that can
rewrite `updraft_backup_history` can erase a site's record of its own backups, which the
test for this demonstrates by doing exactly that against the unfixed code.

There is no restore tool at any access level. Restoring discards everything since the
backup, which is a larger irreversible act than anything else here, and no confirmation
token makes that safe to hand to something reading instructions out of a comment queue.

**Prompts and resources.** The server offers six ready-made upkeep jobs through MCP prompts, and publishes recent posts, the comment queue and the site briefing as MCP resources a client can attach to a conversation. Every resource is backed by a tool and gated by it, so a resource is never a softer route to data than the tool it mirrors.

Optionally the plugin can also generate tools from the site's own REST API routes. That is off by default because it is a large, generic surface next to the curated tools.

They are generated once and cached for a day, and that cache is thrown away on the first request after the plugin's version changes. It has to be: the cache does not only fill the tool listing, it gates dispatch, so before this a tool added by an upgrade was not merely missing from the list for twenty-four hours, it could not be called, and the failure read as "unknown tool" with nothing to point at the cause. A client holding its own stale copy of the tool list is a separate problem and not one a plugin can reach from here.

Those generated tools return whole REST records, which is more than a model usually wants: rendered content it did not ask for, and a block of `_links` per row it has no way to follow. They take `_fields` for that, naming the fields to return and nothing else, and the difference is not marginal. Three empty pages come back as 4556 bytes whole and 186 with four fields named, and the gap widens with real content rather than closing. The parameter is WordPress's own and always worked; it was simply not declared on the generated schemas, so nothing reading a tool list could discover it. `wp_get_posts` remains the lighter tool when a plain list of posts will do.

## The settings screen

At **MCP Server** in the admin menu, in four tabs. It is top level rather than buried
under Settings, because it is the first thing anyone needs after activating, and there is
a Settings link on the plugin's row too.

The tabs are query arguments and each one is an ordinary link, so the screen works with
JavaScript switched off and a tab can be bookmarked or sent to somebody else. Every form
returns to the tab it was submitted from.

### Connect

The endpoint, and the exact thing to paste for each kind of client, filled in with this
site's real address and token: the address on its own for OAuth clients such as Claude
Desktop, a ready `claude mcp add` command for Claude Code, and a JSON block for anything
else. It also shows the `?rest_route=` form for sites where pretty permalinks are off or
broken.

**Is this site ready** walks the same steps a client does when it configures itself and
shows which one fails: HTTPS, whether the discovery documents are served at both the site
root and the REST route, whether the address they advertise matches reality, whether
clients can register, and whether the bearer token path works. It distinguishes "this
failed" from "this could not be checked", because a host blocking loopback requests is
common and is not a fault.

### Access

Who may connect, and how far each of them reaches: named keys, then the OAuth apps that
have connected.

**Keys** lists the named keys. Each row shows the label, its access level, the tools it is
limited to, when it expires and when it was last used, with a control to revoke it. A new
key's secret appears once, on creation, and is not recoverable afterwards.

### Tools

Which groups of tools an agent is offered, and nothing else. A group that is off is not
merely hidden: its tools are refused if asked for by name.

### Logs

What the plugin records, and then the record. The audit log's switch and its retention
window sit directly above the log they fill, which is the arrangement the old single page
did not have: the two were four hundred lines apart, under a heading about tools. The
change journal and debug logging are here too, because all three answer the same question.

The audit log itself: every tool call, refusals included, with the arguments it was
given, what it changed, what made it, what it was aimed at, how long it took and why it
was turned down. Without it an agent works with no visible record at all: you can see
that a plugin is gone, but not that your agent removed it, when, or that it tried three
times first. Refusals are the interesting entries, which is why they are kept.

It lives in its own table, `{prefix}gmcp_audit`, indexed by time, tool and actor. That
replaced an option row, which was a read-modify-write: two calls landing together could
lose an entry, and it held a hundred rows at most.

It is read through a list, one row per call, and a page for any single entry. The list is
a WordPress list table, so it pages, sorts by column, remembers how many rows you want
under Screen Options, and behaves like the rest of wp-admin. It filters by tool, by
account, by how recently, and by whether the call was refused, that last one as a link
rather than a menu because refusals are what the table is kept for.

Each row is one line. That is a constraint rather than a simplification: the previous
screen printed every changed field inline, so a single call that touched eight objects
pushed the next call off the screen, and a list you scroll past to reach the next entry
has stopped being a list. The row says what the call changed in a phrase, and the entry
page has room for the rest.

The entry page holds everything the log recorded, which the list deliberately does not:
the full refusal message rather than the first 160 characters, every changed field with
its before and after, the arguments as they were stored, and the entry's own hash and the
one it follows. It also says whether that entry still matches its own hash, and says in
the same breath that this is a statement about one entry and not about the chain.

There is no checkbox column and no bulk actions, which is a decision. Every bulk action a
log could offer is a deletion, and rows here hash the row before them: removing one from
the middle is exactly what the chain exists to make visible, so putting a convenient
button on it would be building the attack into the product.

Five things about it are decisions rather than defaults.

*Arguments are recorded, redacted.* An entry that does not say what was asked for is half
an entry, but `wp_create_user` takes a password and `wp_update_option` takes whatever a
settings array holds. Everything goes through `GMCP_Core::redact()`, which keeps the
shape and blanks the leaves, and `user_pass` is dropped whatever the detector thinks. A
field reading `[redacted]` is itself information: it says a secret was passed. The option
name in `wp_update_option` is deliberately kept, because the credential patterns are
written for field names inside a value, where `key` signals a secret, and at the top level
of a call it is the name of the thing being changed.

*What was called is not what changed.* An entry saying `wp_update_post` ran on post 12
with certain arguments does not say the post went from private to publish, and that is
usually the question. Each entry therefore carries a summary of what actually moved:
which object, of what kind, and for each field the value before and the value after.
Widgets, menus and menu items are named as what they are rather than as the option or
post they are stored in.

It is a summary rather than a copy, for two reasons. Field-level copies of post bodies
would eat the retention bounds, so a value longer than a line is recorded as its size:
"the title changed, and the body went from 1.4 KB to 1.6 KB" is the useful sentence, and
a field that did not change is simply absent. And some of those values are passwords.
Anything credential-shaped is recorded as `[redacted]` on both sides, judged by the same
`gmcp_credential_field_patterns` the change journal uses, so the log says a password was
changed without saying to what.

*Each row hashes the one before it.* Nothing here stops somebody with database access
editing a row, and pretending otherwise would be worse than not trying. What the chain
does is make it visible: the screen recomputes it and names the first row that no longer
matches, and says whether the row was edited or one before it removed. That is the
difference between a history and an audit. Rows carried over from the option-based
version have no hash and are reported as uncovered rather than as tampering.

The verdict names an entry, and the screen links to it, so "the chain breaks at entry
412" leads to entry 412 rather than handing you a number and no way to use it.

*And it says how much it checked.* Recomputing the whole table means reading every
recorded argument back out of the database, which on a full one is tens of megabytes, so
the screen checks the most recent thousand rows and offers a button for the rest. The
count it reports is the count it read, and a partial check does not merely say so, it looks
different: a complete pass is a sentence, while a partial one is set apart on the page under
"only part of the chain was checked" and gives all three numbers, so "1,000 of 8,300 entries
were checked, the most recent first, and those are intact. The other 7,300 were not looked
at." Reassurance and partial reassurance reading alike at a glance is the whole failure this
guards against, since glancing is what a person does with it. The complete pass is scoped the same
way for the same reason: it used to say "intact across all N entries" using the count of rows
it verified, which excludes any carried over from before the log was chained, so on an
upgraded site "all" named a smaller number than the table held. It now says how many were
signed and how many were not, and on a site where nothing is signed it says there is no chain
to check rather than reporting one intact across zero entries.
An earlier version read the oldest rows instead and said only "intact across 1,000
entries", which was true, read as a verdict on the whole log, and never examined the
period anyone would most want to check.

Two things the chain cannot do, stated here rather than left to be assumed. It cannot
notice that the log has been shortened from the front, because the first row the walk
sees is the only thing that says what preceded it, and a chain with no external anchor has
nothing to check that claim against. A truncated table verifies clean, and reports itself
complete, because the total is counted from what survives. And it cannot tell you who
edited a row, only that somebody did.

The changes column arrived after rows had already been written, which the chain has to
survive, and the first attempt at that was wrong in an instructive way. Hashing joined the
columns with a separator, which is safe while the list of columns is fixed: moving content
from one column into the next leaves a separator behind and the recomputation differs. It
stops being safe as soon as the list can vary in length, which a nullable column makes it
do. A row's recorded changes could be appended to the end of its neighbour and the column
blanked, and the shorter recomputation would rebuild the longer string exactly, so the
chain would call the row intact while the changes it covered had been erased from it. The
hash therefore commits to the shape of a row as well as to its contents: each field
contributes its name and the byte length of its value, prefixed by the field count.

That cannot be applied backwards, because re-signing old rows under a new construction is
the same as not signing them. The upgrade records the id of the last row written under the
old one, and every row is checked the way it was written: the old join at or below that
mark, the canonical encoding above it. Old rows keep verifying, new rows are unambiguous,
and nothing was rewritten to make either true.

*Pruning is bounded three ways.* Age alone lets a runaway agent fill a disk in a day; a
row cap alone lets one enormous entry do it; a byte cap alone throws away last week
because of last year. So retention in days (90 by default, configurable), a hard cap of
50,000 entries and one of 50 MB of recorded arguments and changes, whichever is hit first, pruned by
a daily WP-Cron event. There are Prune now and Clear everything buttons on the screen.

*One page can be purged rather than all of them.* `wp_purge_url` takes URLs on this site and asks each page cache it can name to drop just those, which matters because dropping everything on a busy site is a thundering herd. It refuses anything that is not this site, and the checks are the ones that catch a careless implementation: a host is compared for equality, so `example.com.evil.test` fails, and a protocol-relative `//evil.test/x` is read as a foreign host rather than as a path. A path containing `..` is refused outright, because every per-URL purge turns the URL into a filesystem path and globs it. One bad entry refuses the whole call, since purging nineteen and mentioning the twentieth in passing is the kind of thing a reader skims. The object cache is deliberately untouched: its entries are keyed by post and option, never by URL, so the only lever is the site-wide flush this tool exists to avoid.

*The log can leave the screen,* as CSV or JSON, and both export the rows the current filters
select rather than the whole table, so what you get is what you were looking at. A match too
large to send is cut at its oldest end and the file name says so, because a file outlives the
screen that would otherwise have carried that caveat. An export cannot give up a secret: the
redaction happened on the way in, so there is no unredacted copy to export. Cells that open
with `=`, `+`, `-`, `@`, a tab or a carriage return are written with a leading apostrophe, which
is visible in the file and deliberate. A spreadsheet treats such a cell as a formula and runs
it, and this log carries post titles, refusal messages and comment text that an anonymous
person wrote, which is the same reason the rest of the plugin is careful.

*Meta keys are matched exactly.* They used to be lowercased on the way in, which is fine
until it is not: a key spelled `myPlugin_Data` addressed a different row, or none, and a
write created the wrong one while reporting success. Every meta tool now takes the key as
given, and the empty string and `"0"` are refused rather than passed on, because WordPress
tests a meta key for truth before using it and treats both as no key at all, so a read of
`"0"` answers with every key on the post.

One half of this cannot be fixed from here and is reported instead. The database compares
`meta_key` case-insensitively, so `myPlugin_Data` and `myplugin_data` are one row to every
write WordPress performs; reads come from a cache keyed by the spelling actually stored, and
those compare exactly. Writing a key that differs only in case from one already on the post
therefore updates that row, leaves its original spelling, and the value is then invisible to
a read of the key you just wrote, with nothing erroring anywhere. The writers say which
spelling a value landed under whenever it is not the one you asked for.

*Who is not overclaimed.* Two columns, because the honest answer needs both. `called_by`
is the OAuth application, the named key's label, or the authentication method a shared
token used: it is the closest this site has to who was driving. The screen names the way in
alongside it rather than instead of it, because the three are not equivalent: OAuth is a
consent that stops working when the account stops being an administrator, a bearer token is
a shared secret. `acted_as` is the
WordPress account the call ran as, and a key carries its own owner rather than borrowing the lowest-numbered
administrator, so that name is the same whoever sent the request. The reply says so in
as many words rather than leaving a reader to infer it.

*The screen is built for the way agents actually behave.* Agent traffic is repetitive: a
reader who asked for the log after twenty-eight polling calls used to get a page of
twenty-eight identical rows and found the refusals three pages later. Consecutive identical
calls now fold into one row carrying a count, expandable in place, with a link that unfolds
the whole page. The item total always reports the true number of entries, because a log that
rounds down what it holds is not a log. Anything that recorded a change never folds, however
alike two such calls look: five writes of the same option are five different before-and-after
pairs, and merging them would hide exactly what the log exists to show.

*The page says what is behind it.* Folding is within a page, which is what keeps the item total
honest, so a long run still occupies its own page and the interesting rows sit further back. A
folded page therefore also says how many refusals match the current filters but fall on other
pages, linked. It stays quiet when nothing folded, when the reader has unfolded the page, when
an outcome filter is already in force, and when every matching refusal is on the page already:
a line printed on every page is how a reader learns to skip the line that will one day matter.

*A row opens in place.* Comparing three refusals used to be three page loads and three journeys
back. The row already holds its reason and its changes, so an expander shows both without
another query. Arguments and the chain hashes stay on the entry page, because arguments are
capped at 64,000 bytes each and putting twenty-five of those in one page trades one problem for
a worse one. A row that changed nothing grows no expander, judged by the same test the Changed
column uses, so the two can never disagree. On a folded row the expander shows the reason and
never the changes: the reason is part of the fold key so it is true of every entry in the run,
while anything that changed the site never folds in the first place.

*A refusal says why on the row.* Refusals are the interesting entries, and they used to all
render as the single word "Refused" with the reason a page load away. The reason now sits
under the row, cut to a readable length with the whole of it on the entry page. It is a
full-width line rather than another column because the Result column is about a hundred
pixels wide and a two-hundred-character refusal wrapped to eight lines in it, which made the
rows that mattered the hardest ones to read.

*Searching is narrow by default.* The log holds up to 50 MB of recorded arguments, and a
substring search across all of it is a full scan of the table. The search box reads the
target and the result, both small, and a checkbox puts the arguments and the changes back
when you need to ask what touched post 12. The `outcome` column is indexed, so the Refusals
view reads the page it returns rather than scanning to find it.

*A partial check looks partial.* Verifying the chain walks the newest thousand entries, and the
table may hold fifty thousand, so a reassuring sentence could cover two percent of the log and
look exactly like one that covered all of it. A complete pass is still a green sentence. A
partial one is its own box, says how many entries were checked and how many were not, and
offers a button that walks the whole chain; that walk takes about six tenths of a second on a
full table of fifty thousand rows, at flat memory, because it verifies in chunks.

The result of a full walk is remembered with the date it ran, since it is the only thing that
can say anything about the rows the window never reaches. It is never phrased as current state.
A remembered pass is dropped the moment the quick walk disagrees with it, and that rule is
load-bearing rather than tidy: the record is invalidated by the row count and the highest id,
neither of which moves when a row is edited in place, which is exactly the tampering the chain
exists to catch. Without it the screen printed a break and, directly underneath, that all fifty
thousand entries were intact and nothing had changed since. A remembered break is kept either
way, because it covers rows the window cannot reach and the window finding nothing does not
answer it.

An agent can read the log through `wp_get_audit_log` at `admin` level, filtered by tool,
outcome, date or free text, and the reply carries the tamper verdict so a caller is told
immediately if what it is reading has been altered. Nothing exposed through MCP can prune
or clear it: an agent that can edit its own audit trail is not being audited.

## Extending

Add your own tools with two filters:

```php
add_filter( 'gmcp_tools', function ( $tools ) {
  $tools[] = [
    'name' => 'my_tool',
    'description' => 'What it does.',
    'inputSchema' => [ 'type' => 'object', 'properties' => [], 'required' => [] ],
    'accessLevel' => 'read', // read | write | admin
  ];
  return $tools;
} );

add_filter( 'gmcp_callback', function ( $result, $tool, $args, $id ) {
  if ( $tool !== 'my_tool' ) {
    return $result;
  }
  return [
    'jsonrpc' => '2.0',
    'id' => $id,
    'result' => [ 'content' => [ [ 'type' => 'text', 'text' => 'done' ] ] ],
  ];
}, 10, 4 );
```

A tool that fails returns a result rather than a JSON-RPC error: `content` as usual, with
`isError` set. A protocol error carries no result at all, so a client reading
`result.content` finds nothing where an array should be, and may discard the whole
response, including on a call that had already done its work. That is how a created post
came back with no readable ID. Only a genuinely protocol-level condition, an unknown
method, is a real error. Follow the same rule in your own tools.

A filter may also return plain data instead of a full response, and the server wraps it:
the JSON becomes the reply text and the value is attached as `data`. It decides a value is
already an envelope by looking at the shape of `content`, a non-empty list of blocks each
carrying a `type`, not merely by finding a key of that name. The distinction matters if
your tool returns a record of its own that happens to have a `content` field. WordPress
posts do, holding `{raw, rendered, protected}`, and for a while that was enough to have a
created page handed back as though it were a finished MCP reply, which no client could
parse. Return a full response when you want to control the envelope, and plain data when
you do not.

Other hooks:

| Hook | Purpose |
|---|---|
| `gmcp_allow` | Override the auth decision |
| `gmcp_mutate` | Fires after any tool that changed content. Use it to purge a full-page cache |
| `gmcp_url_purged` | Fires once per URL that `wp_purge_url` handled. Wire a CDN purge here to drop one page rather than the whole edge |
| `gmcp_cache_flushed` | Fires when `wp_flush_cache` runs. Wire a CDN or reverse-proxy purge here, since nothing in PHP can reach one |
| `gmcp_tool_called` | Every call, for auditing |
| `gmcp_stream_max_time` | Idle timeout for an open stream, default 180 seconds |
| `gmcp_oauth_user_can_authorize` | Who may approve an OAuth connection |
| `gmcp_tool_start` | Fires before a tool runs. Paired with `gmcp_tool_called`, it marks when a call is in flight |
| `gmcp_change` | One observed change during a tool call, with the before and after values. What the change journal and the audit log both read |
| `gmcp_prompts` | Add or replace the ready-made prompts |
| `gmcp_upgraded` | Fires once on the first request after the plugin's version changes, with the new version. Anything of yours cached against the old build can be cleared here |
| `gmcp_protected_options` | Option keys that must never be read, written or journalled |
| `gmcp_protected_option_patterns` | Substrings that mark an option as credential-shaped |
| `gmcp_backup_option_prefixes` | Namespaces whose option rows a backup plugin owns, refused for reads and writes |
| `gmcp_credential_field_patterns` | Field names inside a value that mark it as a credential, used by both the change journal and the audit log |
| `gmcp_audit_prune` | The daily cron event. Hook it to forward or archive entries before they are pruned |
| `gmcp_backup_providers` | Register an adapter for a backup plugin this one cannot drive |
| `gmcp_can_call_tool` | Answers whether the caller could call a given tool. The change journal asks it before replaying a write, so this is the gate on undo |
| `gmcp_allow_remote_install` | Permit installs from a URL rather than the wordpress.org repository |
| `gmcp_allow_unfiltered_post_html` | Store post HTML unfiltered |
| `gmcp_allow_unfiltered_widget_html` | Store widget HTML unfiltered |

## Security notes

- Only administrators can authorize an OAuth client, checked both at authorize time and again on every request.
- Keys are stored as a SHA-256 hash and shown once, so the database holds nothing that can be replayed. A key also records the administrator who created it and acts as that account, which is what lets the log name a person; it stops working the moment that account stops holding `manage_options`, so revoking somebody's access revokes their agent with it.
- No row belonging to this plugin, and no option whose name looks like a credential, can be read or written through the option tools. The change journal additionally inspects the value it is about to record, so a `secret_key` or `pass` field inside a settings array is blanked before the rest of that array is kept, and an undo leaves the live value in place rather than writing the placeholder over it. Strings that parse as JSON or as a serialized array are unpacked and judged too, and blanked whole when they hold one. The check reads field names, not content, so it will not catch a secret held as a bare string under an innocuous option name.
- Tools do not execute arbitrary PHP or SQL. Every tool is a fixed WordPress operation with a schema.
- An open stream holds one PHP worker for up to 180 seconds. Size your pool accordingly if several agents connect at once.
- **The token-in-URL endpoint is gone, and so is the shared bearer token.** If you were connecting over either, connect with a named key in the `Authorization` header instead. An existing shared token is carried over into a key on upgrade, so it keeps working through the header without you doing anything; it is no longer readable back from the screen or the database. If your host strips the header, the fix is the header: re-saving your permalink structure regenerates the `.htaccess` rule that forwards it, and the connection check on the settings screen tells you whether it worked.

## Development

Lint without a local PHP install:

```
docker run --rm -v "$PWD":/app -w /app php:8.1-cli sh -c 'for f in includes/*.php *.php; do php -l "$f"; done'
```

A throwaway WordPress for testing lives in `.dev/` (see that directory's README). It carries
three suites: `smoke.sh` for the transport, auth, content tools, prompts, resources and
previews; `smoke-admin.sh` for the administration tools, the change journal, named keys and
every guard around them, which rebuilds `.htaccess` partway through and is the destructive
one; and `smoke-woo.sh`, which needs WooCommerce installed.

Never run `wp plugin install --force` against that stack. Its plugin directory is a bind
mount of this repository, and WordPress deletes the old plugin directory before unpacking
the new one. The delete goes straight through the mount and takes the source tree, `.git`
included.

## Licence

GPLv2 or later. Parts of this plugin derive from prior GPL work; see `CREDITS.md` for the
attribution and the statement of changes that licence requires.
