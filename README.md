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

**Bearer token**, for clients that cannot do OAuth, such as a CLI agent. Generate one on the settings screen and give it to the client:

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
| `admin` | 43 | 69 | 81 | Everything, including deletes, users and options |
| `readwrite` | 33 | 41 | 50 | Create and update, no destructive tools |
| `readonly` | 17 | 25 | 29 | Reads only |

A **named key** narrows this further. It carries its own level, an optional expiry date,
and an optional list of the only tools it may call, so a key handed to a deploy script
can be limited to reading posts and nothing else. Keys are stored hashed and shown once.
The shared bearer token above is unchanged and still displayed, because people rely on
reading it back.

## Tools

**Content and site data**, on by default: posts and pages, block content, taxonomies and terms, comments, media (including upload by URL or by a one-time upload link), users, post meta, site options, post types, block patterns.

**Site administration**, off by default: installing, activating, updating and deleting plugins and themes; navigation menus and their items; widgets and widget areas; the General, Reading and Discussion settings; the permalink structure; and a Site Health report. These install code and change how the site renders, so they are opt-in and carry their own guards:

- Installs come from the wordpress.org repository by slug. An arbitrary ZIP URL is refused unless the site opts in through the `gmcp_allow_remote_install` filter, and the download host is checked so a plugin cannot rewrite the repository's answer. If you do open that filter, note that the URL you approve is the one before redirects: `download_url()` follows up to five, and WordPress only blocks non-HTTP schemes, odd ports and IPv4 private ranges along the way. Allowlist hosts you control, and be aware an open redirect on one of them defeats the check.
- Deleting a plugin, a theme or a menu takes two calls, as does changing the administration email. The first changes nothing and returns a token bound to that exact target; only the second proceeds, so a single instruction cannot complete one, which matters because this agent reads comments and post content that other people wrote. Content deletions are not on that list: they go to the trash and can be restored, and the irreversible form, `force: true`, is one call. Use `preview` on it to see what would go, including the comments and attachments that go with it.
- The plugin refuses to deactivate or delete itself, to delete the active theme or its parent, and to activate a theme this server cannot run.
- Post and widget content is always filtered, regardless of the caller's capabilities. WordPress normally lets an administrator store raw HTML, but the caller being an administrator says nothing about who wrote the markup, and these tools sit at the `write` access level, so a deliberately limited token could otherwise plant a script on a public page. Blocks, shortcodes, inline styles and `data-` attributes all survive; `<iframe>`, inline `<svg>`, `<style>` blocks and Outlook conditional comments do not, unless you open `gmcp_allow_unfiltered_post_html`.
- Block markup is filtered structurally rather than with `wp_kses_post` alone. Gutenberg escapes quotes and angle brackets inside block attributes as HTML entities, and kses does not recognise such a delimiter comment: it escapes the opener and destroys the block. Only the rendered HTML inside each block is filtered, and the attributes round-trip as JSON.
- The default role for public registration is checked by construction: a role granting anything beyond a subscriber is refused, rather than checking a list of capabilities that would never stay complete.
- Changing the administration email takes a confirmation step and is rate limited, because it mails an arbitrary address from your domain with body text drawn from the site title.
- Menu items refuse draft, private and password-protected targets, since a menu item stores its own copy of the title and WordPress renders it regardless of the target's status.
- Settings are an allowlist, not a blocklist. `siteurl` and `home` are refused outright, since a wrong value makes the site and this endpoint unreachable with no way back. A default role that can edit content is refused, because open registration plus an editing default role is a way in.
- None of them are reachable over the URL-token endpoint, since that endpoint puts the secret somewhere servers log it. Every `admin`-level tool is refused there, plus two whose declared level understates their reach: `wp_get_site_health`, which makes a loopback request and a wordpress.org call and returns a full account of your configuration, and `wp_upload_request`, which writes nothing itself but hands out an upload URL on a route that authenticates nobody. It cannot *change* anything on the list above, and it cannot read your settings or obtain an upload URL. It is not otherwise restricted, and that is worth stating rather than implying: a token recovered from an access log can create and edit posts, run a search and replace, post comments, and list your menus, widget areas, themes and permalink structure. Those last four are already in `wp_site_briefing`, which is read level and deliberately reachable there, so blocking them one at a time would draw a line where nothing changes. The settings tool is the exception because it returns the administration email, which is a person's address rather than a fact about the site.

None of this plugin's own rows are readable or writable through the option tools, so the bearer token cannot be read back out or overwritten through the API. Any option name containing `gmcp_` is refused, anywhere in the name rather than only at the start, which is deliberate: the one-time plaintext of a newly minted key lives at `_transient_gmcp_new_key_<user>`, and a rule anchored to the start of the name would miss the row it most needs to catch. It replaced a list of exact names, which had been wrong twice: the change journal was readable until somebody named it, and that transient was never on it.

**WooCommerce**, off by default, and the switch only appears when the shop is installed: products, stock levels, orders, order notes, customers, a sales summary and a store briefing. Separate from site administration because the risk is a different shape. The administration tools can break a site; these read customer names, email addresses and delivery addresses and hand them to a model, which is a decision a shop owner should make deliberately rather than inherit.

- Refunds are not included. `wc_create_refund()` moves money through the payment gateway, and nothing here can put that back.
- Anything that emails a customer reports who was actually written to. The reply names the address if it was the customer's, measured by watching `wp_mail` during the change rather than predicting it from the status. Predicting it does not work: whether a move to `refunded` mails anyone depends on the transition rather than the target status, `on-hold` mails the customer and is what shops use for bank transfers, and `cancelled` mails only the shop. A hardcoded list was wrong in both directions and would go stale anyway as shops add statuses. Setting the status to refunded marks the order and moves no money.
- WooCommerce tools carrying personal data are `admin`, not `read`. `wp_get_users` is admin and returns no email address at all, so orders and customers, which carry names, email addresses and postal addresses, cannot sit below it. Products, stock and sales figures stay at `read`. This is not a claim about every tool everywhere: `wp_get_comments` is `read` and returns the commenter's name, because a comment is published text and its author is on the page already.
- Everything goes through the WooCommerce CRUD classes rather than posts and meta, because High-Performance Order Storage moves orders out of `wp_posts` entirely. A tool built on post queries works on a fresh install and returns nothing on most real shops.
- A new product is created as a draft unless you ask otherwise, so a product with no price is never briefly for sale.

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

Undo records modifications and not creations or deletions, and that is a real limit rather
than a nicety. The capture layer reports both and the journal declines them: putting back
a creation means deleting something, and putting back a deletion means recreating it, and
neither is the same write in reverse. Users, comments, terms, plugins, themes and media
are reported too and journalled none of them, for the same reason. Post meta is watched by
nothing, because every post save writes `_edit_lock` and the noise would bury the signal.
The audit log has all of it, which is the difference between the record and undo. Deleting
a post without `force` puts it in the trash, where WordPress can restore it, which covers
the most common case by accident rather than by design.

Reverting is gated twice: on the tool that made the change, and on the operation the revert will perform, derived from the entry's own kind. Both are needed, because the recorded tool is whatever was in flight rather than what wrote the row. A plugin hooked on `save_post` that writes an option produces an option entry attributed to `wp_update_post`, and gating on that name alone let a write-level caller replay an admin-level option write.

Two limits worth knowing. Values that look credential-shaped are not stored, judged by the field names inside them through `gmcp_credential_field_patterns` as well as by the option's own name, so those changes are recorded but cannot be reverted. That check is structural, so a secret held as a bare string under an innocuous option name is still stored. And it is not retroactive: adding an option to `gmcp_protected_options` refuses future reverts but does not scrub what is already recorded, so clear the journal after protecting something that was previously being written.

**Backups.** `wp_backup_status` reports what is known; `wp_start_backup` asks the site's
backup plugin to start one. Three things shape this more than the integration does.

There is no common interface. Sixteen backup plugins with no dominant one, and the largest
work in unrelated ways: UpdraftPlus fires a WordPress action, BackWPup wants a secret URL
the site owner must first enable with a filter, and several keep scriptable export behind
a paid tier. So there is a working adapter for UpdraftPlus, detection for BackWPup,
All-in-One and Duplicator that names them and says why it cannot drive them, and
`gmcp_backup_providers` for anything else.

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

There is no restore tool at any access level. Restoring discards everything since the
backup, which is a larger irreversible act than anything else here, and no confirmation
token makes that safe to hand to something reading instructions out of a comment queue.

**Prompts and resources.** The server offers six ready-made upkeep jobs through MCP prompts, and publishes recent posts, the comment queue and the site briefing as MCP resources a client can attach to a conversation. Every resource is backed by a tool and gated by it, so a resource is never a softer route to data than the tool it mirrors.

Optionally the plugin can also generate tools from the site's own REST API routes. That is off by default because it is a large, generic surface next to the curated tools.

## The settings screen

Everything is on one page, at **MCP Server** in the admin menu. It is top level rather
than buried under Settings, because it is the first thing anyone needs after activating,
and there is a Settings link on the plugin's row too.

**Connect a client** gives you the exact thing to paste, filled in with this site's real
endpoint and token: the address on its own for OAuth clients such as Claude Desktop, a
ready `claude mcp add` command for Claude Code, and a JSON block for anything else. It
also shows the `?rest_route=` form for sites where pretty permalinks are off or broken.

**Is this site ready** walks the same steps a client does when it configures itself and
shows which one fails: HTTPS, whether the discovery documents are served at both the site
root and the REST route, whether the address they advertise matches reality, whether
clients can register, and whether the bearer token path works. It distinguishes "this
failed" from "this could not be checked", because a host blocking loopback requests is
common and is not a fault.

**Keys** lists the named keys. Each row shows the label, its access level, the tools it is
limited to, when it expires and when it was last used, with a control to revoke it. A new
key's secret appears once, on creation, and is not recoverable afterwards.

**Recent activity** is the audit log. Every tool call, refusals included, with the
arguments it was given, what it changed, what made it, what it was aimed at, how long it
took and why it was turned down. Without it an agent works with no visible record at all: you can see
that a plugin is gone, but not that your agent removed it, when, or that it tried three
times first. Refusals are the interesting entries, which is why they are kept.

It lives in its own table, `{prefix}gmcp_audit`, indexed by time, tool and actor. That
replaced an option row, which was a read-modify-write: two calls landing together could
lose an entry, and it held a hundred rows at most.

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

*Who is not overclaimed.* Two columns, because the honest answer needs both. `called_by`
is the OAuth application, the named key's label, or the authentication method a shared
token used: it is the closest this site has to who was driving. `acted_as` is the
WordPress account the call ran as, and a static bearer token borrows the lowest-numbered
administrator, so that name is the same whoever sent the request. The reply says so in
as many words rather than leaving a reader to infer it.

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

Other hooks:

| Hook | Purpose |
|---|---|
| `gmcp_allow` | Override the auth decision |
| `gmcp_mutate` | Fires after any tool that changed content. Use it to purge a full-page cache |
| `gmcp_tool_called` | Every call, for auditing |
| `gmcp_stream_max_time` | Idle timeout for an open stream, default 180 seconds |
| `gmcp_oauth_user_can_authorize` | Who may approve an OAuth connection |
| `gmcp_tool_start` | Fires before a tool runs. Paired with `gmcp_tool_called`, it marks when a call is in flight |
| `gmcp_change` | One observed change during a tool call, with the before and after values. What the change journal and the audit log both read |
| `gmcp_prompts` | Add or replace the ready-made prompts |
| `gmcp_protected_options` | Option keys that must never be read, written or journalled |
| `gmcp_protected_option_patterns` | Substrings that mark an option as credential-shaped |
| `gmcp_credential_field_patterns` | Field names inside a value that mark it as a credential, used by both the change journal and the audit log |
| `gmcp_audit_prune` | The daily cron event. Hook it to forward or archive entries before they are pruned |
| `gmcp_backup_providers` | Register an adapter for a backup plugin this one cannot drive |
| `gmcp_can_call_tool` | Answers whether the caller could call a given tool. The change journal asks it before replaying a write, so this is the gate on undo |
| `gmcp_header_auth_only_tools` | Tools the URL-token endpoint may not reach, on top of every `admin`-level tool. Adds to and removes from the exception list; it cannot unblock an admin-level tool |
| `gmcp_allow_remote_install` | Permit installs from a URL rather than the wordpress.org repository |
| `gmcp_allow_unfiltered_post_html` | Store post HTML unfiltered |
| `gmcp_allow_unfiltered_widget_html` | Store widget HTML unfiltered |

## Security notes

- Only administrators can authorize an OAuth client, checked both at authorize time and again on every request.
- The shared bearer token is compared with `hash_equals` and stored in the options table in the clear, because the settings screen shows it back to you. Anyone who can read the database can read it, so rotate it if that changes. Named keys are different: they are stored as a SHA-256 hash and shown once, so the database holds nothing that can be replayed.
- No row belonging to this plugin, and no option whose name looks like a credential, can be read or written through the option tools. The change journal additionally inspects the value it is about to record, so a settings array holding a `secret_key` or a `pass` field is not stored. That check reads field names, not content, so it will not catch a secret held as a bare string under an innocuous option name.
- Tools do not execute arbitrary PHP or SQL. Every tool is a fixed WordPress operation with a schema.
- An open stream holds one PHP worker for up to 180 seconds. Size your pool accordingly if several agents connect at once.
- The URL-token endpoint can no longer perform any administrative *write*, and can no longer read your settings. If your host strips the `Authorization` header and you were relying on that route for installs, settings or user changes, those now fail. The fix is the header, not the route: re-saving your permalink structure regenerates the `.htaccess` rule that forwards it, and the connection check on the settings screen tells you whether it worked. Earlier versions let `gmcp_header_auth_only_tools` empty the blocked set entirely; it can no longer do that.

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
