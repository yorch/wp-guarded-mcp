# Reeve

A safe [Model Context Protocol](https://modelcontextprotocol.io) server for WordPress, so a local AI agent such as Claude Code or Claude Desktop can administer your site through conversation.

A reeve was the officer who administered an estate on the owner's behalf: full authority over the day-to-day, exercised for someone else, within bounds. That is the shape of this plugin. It hands an agent everything an administrator can do, and puts a guard on each of the operations you would not want done on a misread instruction.

This is a fork of the MCP layer of [AI Engine](https://wordpress.org/plugins/ai-engine/) 3.7.7 by Jordy Meow, stripped of everything that is not the MCP server. GPLv2 or later, same as the original. See `CREDITS.md` for what was kept and what changed.

## What it is

One REST endpoint, `/wp-json/mcp/v1/http`, speaking the MCP Streamable HTTP transport, with WordPress tools behind it. No chatbots, no AI provider keys, no front-end assets. The plugin never calls an AI model itself: your agent does that, and this is what it reaches into.

It also speaks the parts of MCP most servers skip: prompts, so your client offers a menu of upkeep jobs, and resources, so a person can attach a post or the comment queue to a conversation directly.

## Requirements

- WordPress 6.0 or newer
- PHP 8.1 or newer

## Install

Upload the zip through Plugins, Add New, Upload Plugin, or copy the directory into
`wp-content/plugins/reeve` and activate it.

```
wp plugin install /path/to/reeve.zip --activate
```

**The directory must be named `reeve`.** Not `reeve-main`, not `ai-engine`. The plugin
derives its own identity from the folder through `plugin_basename()`, and the guard that
stops an agent deactivating or deleting the plugin mid-call compares against that. Rename
the folder and the self-protection silently stops matching. A GitHub "Download ZIP" gives
you `reeve-main`, so rename it if you go that route.

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
| `readwrite` | 33 | 41 | 53 | Create and update, no destructive tools |
| `readonly` | 17 | 25 | 32 | Reads only |

A **named key** narrows this further. It carries its own level, an optional expiry date,
and an optional list of the only tools it may call, so a key handed to a deploy script
can be limited to reading posts and nothing else. Keys are stored hashed and shown once.
The shared bearer token above is unchanged and still displayed, because people rely on
reading it back.

## Tools

**Content and site data**, on by default: posts and pages, block content, taxonomies and terms, comments, media (including upload by URL or by a one-time upload link), users, post meta, site options, post types, block patterns.

**Site administration**, off by default: installing, activating, updating and deleting plugins and themes; navigation menus and their items; widgets and widget areas; the General, Reading and Discussion settings; the permalink structure; and a Site Health report. These install code and change how the site renders, so they are opt-in and carry their own guards:

- Installs come from the wordpress.org repository by slug. An arbitrary ZIP URL is refused unless the site opts in through the `reeve_allow_remote_install` filter, and the download host is checked so a plugin cannot rewrite the repository's answer. If you do open that filter, note that the URL you approve is the one before redirects: `download_url()` follows up to five, and WordPress only blocks non-HTTP schemes, odd ports and IPv4 private ranges along the way. Allowlist hosts you control, and be aware an open redirect on one of them defeats the check.
- Deleting takes two calls. The first changes nothing and returns a token bound to that exact target; only the second proceeds. A single instruction cannot complete a deletion, which matters because this agent reads comments and post content that other people wrote.
- The plugin refuses to deactivate or delete itself, to delete the active theme or its parent, and to activate a theme this server cannot run.
- Post and widget content is always filtered, regardless of the caller's capabilities. WordPress normally lets an administrator store raw HTML, but the caller being an administrator says nothing about who wrote the markup, and these tools sit at the `write` access level, so a deliberately limited token could otherwise plant a script on a public page. Blocks, shortcodes, inline styles and `data-` attributes all survive; `<iframe>`, inline `<svg>`, `<style>` blocks and Outlook conditional comments do not, unless you open `reeve_allow_unfiltered_post_html`.
- Block markup is filtered structurally rather than with `wp_kses_post` alone. Gutenberg escapes quotes and angle brackets inside block attributes as HTML entities, and kses does not recognise such a delimiter comment: it escapes the opener and destroys the block. Only the rendered HTML inside each block is filtered, and the attributes round-trip as JSON.
- The default role for public registration is checked by construction: a role granting anything beyond a subscriber is refused, rather than checking a list of capabilities that would never stay complete.
- Changing the administration email takes a confirmation step and is rate limited, because it mails an arbitrary address from your domain with body text drawn from the site title.
- Menu items refuse draft, private and password-protected targets, since a menu item stores its own copy of the title and WordPress renders it regardless of the target's status.
- Settings are an allowlist, not a blocklist. `siteurl` and `home` are refused outright, since a wrong value makes the site and this endpoint unreachable with no way back. A default role that can edit content is refused, because open registration plus an editing default role is a way in.
- None of them are reachable over the URL-token endpoint, since that endpoint puts the secret somewhere servers log it.

The plugin's own options row is not readable or writable through the option tools, so the bearer token cannot be read back out or overwritten through the API.

**WooCommerce**, off by default, and the switch only appears when the shop is installed: products, stock levels, orders, order notes, customers, a sales summary and a store briefing. Separate from site administration because the risk is a different shape. The administration tools can break a site; these read customer names, email addresses and delivery addresses and hand them to a model, which is a decision a shop owner should make deliberately rather than inherit.

- Refunds are not included. `wc_create_refund()` moves money through the payment gateway, and nothing here can put that back.
- Anything that emails a customer reports who was actually written to. The reply names the address if it was the customer's, measured by watching `wp_mail` during the change rather than predicting it from the status. Predicting it does not work: whether a move to `refunded` mails anyone depends on the transition rather than the target status, `on-hold` mails the customer and is what shops use for bank transfers, and `cancelled` mails only the shop. A hardcoded list was wrong in both directions and would go stale anyway as shops add statuses. Setting the status to refunded marks the order and moves no money.
- Tools carrying personal data are `admin`, not `read`. `wp_get_users` is admin and returns no email address at all, so orders and customers, which carry names, email addresses and postal addresses, cannot sit below it. Products, stock and sales figures stay at `read`.
- Everything goes through the WooCommerce CRUD classes rather than posts and meta, because High-Performance Order Storage moves orders out of `wp_posts` entirely. A tool built on post queries works on a fresh install and returns nothing on most real shops.
- A new product is created as a draft unless you ask otherwise, so a product with no price is never briefly for sale.

**Orientation.** `wp_site_briefing` answers "what am I looking at" in one call: versions, theme, active plugins, post types with counts, taxonomies, the front page arrangement, the comment queue, users by role and the permalink structure. `wc_store_briefing` does the same for a shop. Both replace the half-dozen queries an agent otherwise makes before any work starts.

**Preview.** The six tools whose effect is not visible from the call accept `preview`, which describes what would happen and changes nothing: `wp_delete_post`, `wp_update_post`, `wp_alter_post`, `wp_delete_term`, `wp_delete_media` and `wp_delete_comment`. It matters most for `wp_alter_post`, where a search and replace reports success whether it matched everything or nothing.

Two things make that preview trustworthy rather than decorative. It compiles the pattern through the same function the write uses, so it cannot describe a different pattern. And it works out each replacement by expanding the reference forms against the groups the match actually captured in context, rather than re-running the pattern over the matched fragment on its own. The second one matters more than it sounds: a pattern like `(?<=foo)bar` matches in the real body but not in the fragment `bar` taken alone, so the naive version reported that the text would be left unchanged and the write then changed it. A preview that reports safety wrongly is worse than no preview.

It also counts matches without collecting them. A pattern of `.` against a 400 KB post is 380,000 matches, and building an entry for each in order to display ten exhausted the memory limit, which made the cautious call more dangerous than the write it was protecting.

**Undo.** `wp_list_changes` and `wp_undo_change` put back a setting or post an agent changed. The journal listens to WordPress rather than to the tools, so it also covers widgets, which live in options, and menu items, which are posts. Only writes made during a tool call are recorded, never a person's own edits, and anything `REEVE_Core::option_guard()` refuses is never stored, so the journal cannot become a second copy of a secret.

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

**Recent activity** records the last hundred tool calls, refusals included. Without it an
agent works with no visible record at all: you can see that a plugin is gone, but not
that your agent removed it, when, or that it tried three times first. Refusals are the
interesting entries, which is why they are kept rather than discarded.

It stores the tool, a short target such as the plugin file or post title, the outcome and
how long it took. It deliberately does not store the full arguments, which can carry a
whole post body. It is a record for a person to read, not a security log: it lives in an
option, so a burst of simultaneous calls can lose an entry and anyone who can write
options can rewrite it. Hook `reeve_tool_called` if you need a real audit trail.

## Extending

Add your own tools with two filters:

```php
add_filter( 'reeve_tools', function ( $tools ) {
  $tools[] = [
    'name' => 'my_tool',
    'description' => 'What it does.',
    'inputSchema' => [ 'type' => 'object', 'properties' => [], 'required' => [] ],
    'accessLevel' => 'read', // read | write | admin
  ];
  return $tools;
} );

add_filter( 'reeve_callback', function ( $result, $tool, $args, $id ) {
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
| `reeve_allow` | Override the auth decision |
| `reeve_mutate` | Fires after any tool that changed content. Use it to purge a full-page cache |
| `reeve_tool_called` | Every call, for auditing |
| `reeve_stream_max_time` | Idle timeout for an open stream, default 180 seconds |
| `reeve_oauth_user_can_authorize` | Who may approve an OAuth connection |
| `reeve_tool_start` | Fires before a tool runs. Paired with `reeve_tool_called`, it marks when a call is in flight |
| `reeve_prompts` | Add or replace the ready-made prompts |
| `reeve_protected_options` | Option keys that must never be read, written or journalled |
| `reeve_protected_option_patterns` | Substrings that mark an option as credential-shaped |
| `reeve_header_auth_only_tools` | Tools the URL-token endpoint may not reach |
| `reeve_allow_remote_install` | Permit installs from a URL rather than the wordpress.org repository |
| `reeve_allow_unfiltered_post_html` | Store post HTML unfiltered |
| `reeve_allow_unfiltered_widget_html` | Store widget HTML unfiltered |

## Security notes

- Only administrators can authorize an OAuth client, checked both at authorize time and again on every request.
- The shared bearer token is compared with `hash_equals` and stored in the options table in the clear, because the settings screen shows it back to you. Anyone who can read the database can read it, so rotate it if that changes. Named keys are different: they are stored as a SHA-256 hash and shown once, so the database holds nothing that can be replayed.
- The plugin's own options row, and any option whose name looks like a credential, cannot be read or written through the option tools, and are never recorded in the change journal.
- Tools do not execute arbitrary PHP or SQL. Every tool is a fixed WordPress operation with a schema.
- An open stream holds one PHP worker for up to 180 seconds. Size your pool accordingly if several agents connect at once.

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
