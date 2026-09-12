# Guarded MCP

A [Model Context Protocol](https://modelcontextprotocol.io) server for WordPress, so an AI agent such as Claude Code or Claude Desktop can administer your site through conversation.

It is built on one assumption: the agent will occasionally get it wrong. An agent administering your site also reads your comments, your post bodies and your plugin descriptions, all written by anonymous people, and it has no reliable way to tell an instruction from content. So this hands an agent everything an administrator can do, and puts a guard on each of the operations you would not want done on a misread instruction.

There is a [project page](https://yorch.github.io/wp-guarded-mcp/) if you would rather read the short version.

This is a fork of the MCP layer of [AI Engine](https://wordpress.org/plugins/ai-engine/) 3.7.7 by Jordy Meow, stripped of everything that is not the MCP server. GPLv2 or later, same as the original.

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

**A named key**, for clients that cannot do OAuth, such as a CLI agent. Create one on the Access page and give it to the client. A key is shown once and stored only as a hash, so keep it wherever the client keeps its configuration:

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

An access level belongs to a named key. OAuth callers always act as the administrator who approved the connection, at full access.

| Level | Content only | + administration | + WooCommerce | What it can do |
|---|---|---|---|---|
| `admin` | 55 | 90 | 102 | Everything, including deletes, users and options |
| `readwrite` | 38 | 49 | 58 | Create and update, no destructive tools |
| `readonly` | 18 | 28 | 32 | Reads only |

These nine numbers are checked by `smoke-admin.sh` against a running site, because all
nine had drifted behind the code before anything checked them. Elementor, Kirki, Yoast SEO
and ACF are not counted: their tools are optional groups that come and go with a plugin, so
folding them in would make the table depend on what happens to be installed.

A **named key** narrows this further. It carries its own level, an optional expiry date,
and an optional list of the only tools it may call, so a key handed to a deploy script
can be limited to reading posts and nothing else. Keys are stored hashed and shown once.
There is no shared token; an existing one is carried over into a key on upgrade so
nothing stops working.

## Tools

Each group is a switch on the Tools page. A group that is off is not merely hidden: its
tools are refused if asked for by name.

**Content and site data**, on by default: posts and pages, block content, taxonomies and
terms, comments, media (including upload by URL or by a one-time upload link), users,
post meta, site options, post types, block patterns, and theme mods. `wp_copy_post_meta`
and `wp_duplicate_post` copy inside PHP so a large design never leaves the server;
`wp_write_post_meta_chunk` and `wp_read_post_meta_chunk` stage a value across several
calls. `wp_create_posts` creates up to twenty in one call. `wp_site_briefing` answers
"what am I looking at" in one call.

**Site administration**, off by default: installing, activating, updating and deleting
plugins and themes; navigation menus and their items; widgets and widget areas; the
General, Reading and Discussion settings; the permalink structure; the site's scheduled
events; and a Site Health report. `wp_flush_cache` purges the object cache, expired
transients, or one post. `wp_purge_url` drops one page from each page cache it can name.
Options can be deleted, not only set.

**WooCommerce**, off by default, and the switch only appears when the shop is installed:
products, stock levels, orders, order notes, customers, a sales summary and a store
briefing. Tools carrying personal data (orders, customers) are `admin`, not `read`;
products, stock and sales figures stay at `read`. Refunds are not included.

**Elementor**, off by default, and the switch only appears when Elementor is installed:
theme-builder conditions, regenerating Elementor's CSS, putting a library template on a
page, asking what references a template, reading the active kit's global settings, and
switching the active kit.

**Kirki**, off by default, and the switch only appears when Kirki is installed: customizer
field discovery, value get/set that resolves the field's storage model, Google Fonts cache
clearing, and config export.

**Yoast SEO**, off by default, and the switch only appears when Yoast SEO is installed:
per-post SEO metadata read/write through the Surfaces API, and indexable rebuild.

**Advanced Custom Fields**, off by default, and the switch only appears when ACF is
installed: custom field discovery, value get/set through `update_field`/`get_field` with
field key references.

**REST API tools**, off by default: tools generated from the site's own REST API routes.
Generated once and cached for a day; the cache is thrown away on the first request after
the plugin's version changes.

**Preview.** Six tools whose effect is not visible from the call accept `preview`, which
describes what would happen and changes nothing: `wp_delete_post`, `wp_update_post`,
`wp_alter_post`, `wp_delete_term`, `wp_delete_media` and `wp_delete_comment`.

**Undo.** `wp_list_changes` and `wp_undo_change` put back a setting or post an agent
*modified*. Only writes made during a tool call are recorded, never a person's own edits.
Creations and deletions are not covered; post meta is.

**Backups.** `wp_backup_status` reports what is known, `wp_list_backups` says which backups
exist, and `wp_start_backup` asks the site's backup plugin to start one. Listings never
include archive filenames. There is no restore tool.

**Prompts and resources.** Six ready-made upkeep jobs through MCP prompts, and recent
posts, the comment queue and the site briefing as MCP resources. Every resource is backed
by a tool and gated by it.

For the reasoning behind each guard — why two-call deletes, why no token-in-URL, why
option guards live on the option, why backups never return filenames, and the rest — see
[`docs/guardrails.md`](docs/guardrails.md).

## The settings screen

At **MCP Server** in the admin menu, in five pages. It is top level rather than buried
under Settings, because it is the first thing anyone needs after activating, and there
is a Settings link on the plugin's row too.

Each page is an ordinary link, so every page works with JavaScript switched off and a page
can be bookmarked or sent to somebody else. Every form returns to the page it was
submitted from.

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

### Logging

What the plugin records. The audit log's switch and its retention window, the change
journal and debug logging, because all three answer the same question. They live apart
from the log itself: the switches are set once and the table is visited daily, and the
table buried below a settings form served neither. When the log is switched off, the
Audit Log page says so and links back here.

### Audit Log

The record itself: every tool call, refusals included, with the arguments it was given,
what it changed, what made it, what it was aimed at, how long it took and why it was
turned down. Without it an agent works with no visible record at all.

It lives in its own table, filters by tool, account, recency and outcome, and each row
hashes the one before it so a row edited or deleted later shows up as a break. Entries
are kept for 90 days by default and pruned automatically. The log can be exported as CSV or
JSON, and an agent can read it through `wp_get_audit_log` at `admin` level. Nothing
exposed through MCP can prune or clear it.

For the detail — how the chain is built and verified, how folding works, how pruning is
bounded, and the rest — see [`docs/audit-log.md`](docs/audit-log.md).

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
seven suites: `smoke.sh` for the transport, auth, content tools, prompts, resources and
previews; `smoke-admin.sh` for the administration tools, the change journal, named keys and
every guard around them, which rebuilds `.htaccess` partway through and is the destructive
one; `smoke-woo.sh` for the shop tools; `smoke-elementor.sh` for the Elementor tools;
`smoke-kirki.sh` for the Kirki tools; `smoke-yoast.sh` for the Yoast SEO tools; and
`smoke-acf.sh` for the ACF tools. The last five need their plugin installed.

Never run `wp plugin install --force` against that stack. Its plugin directory is a bind
mount of this repository, and WordPress deletes the old plugin directory before unpacking
the new one. The delete goes straight through the mount and takes the source tree, `.git`
included.

### Plugin Check

[WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/) is the static
analysis tool the WordPress.org review team uses. A GitHub Actions workflow
(`.github/workflows/plugin-check.yml`) runs it on every push and pull request, so a
change that would fail directory review is caught before it gets there. Errors fail
the build; warnings are printed but do not, matching what the review team enforces.

To run the same check locally, see the [Plugin Check section in `.dev/README.md`](.dev/README.md#plugin-check).

Plugin Check passing is not the same as WordPress.org approval. The directory review
covers more than this tool can check, and the tool's warnings include patterns that are
intentional in this plugin (direct database access for the audit log, third-party hook
names, `error_log()` for the connector's own diagnostics).

## Licence

GPLv2 or later. Parts of this plugin derive from prior GPL work.
