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

Download `guarded-mcp.zip` from the [latest release](https://github.com/yorch/wp-guarded-mcp/releases/latest),
then upload it through Plugins, Add New, Upload Plugin. Or, from the command line:

```
wp plugin install https://github.com/yorch/wp-guarded-mcp/releases/latest/download/guarded-mcp.zip --activate
```

Each release also carries a `.sha256` checksum and a build provenance attestation. The
checksum catches a damaged download: it is produced in the same job as the zip, so it proves
nothing about who built it. The attestation does. Check it with

```
gh attestation verify guarded-mcp.zip --repo yorch/wp-guarded-mcp \
  --signer-workflow yorch/wp-guarded-mcp/.github/workflows/release.yml
```

which confirms the zip was built by this repository's release workflow, not that anyone
has reviewed what it contains.

This plugin does not update itself, and GitHub releases are not an update channel. Watch the
repository's releases and install each new one by hand: upload it again through Plugins, Add
New, Upload Plugin and choose "Replace current with uploaded".

To build the zip yourself instead, run `.dev/build.sh` from a committed checkout (see
[Building an installable zip](.dev/README.md#building-an-installable-zip)). Or copy the
directory into `wp-content/plugins/guarded-mcp` and activate it.

**The directory must be named `guarded-mcp`.** Not `wp-guarded-mcp`, not
`wp-guarded-mcp-main`, not `ai-engine`.
The plugin derives its own identity from the folder through `plugin_basename()`, and the
guard that stops an agent deactivating or deleting the plugin mid-call compares against
that. Rename the folder and the self-protection silently stops matching. Release zips
already unpack to `guarded-mcp/`. A GitHub "Download ZIP" of the repository gives you
`wp-guarded-mcp-main`, so rename it if you go that route. The repository is named
`wp-guarded-mcp` and the plugin `guarded-mcp`; only the second name matters to WordPress.

Then open **MCP Server** in the admin menu.

## Connecting an agent

The settings screen shows the endpoint URL. There are two ways in.

**OAuth**, for clients that support it (Claude Desktop, the Claude web connector). Paste the endpoint URL into the client. It discovers the authorization server, sends you to a WordPress login, and shows a consent screen. Nothing to configure, and no shared secret. The consent screen asks whether the app gets **Full access** or **Read-only**; Full is preselected, as it always was. Only administrators can approve a connection, and the resulting token keeps working only while that account is still an administrator.

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

An access level belongs to a named key, or to an OAuth grant. A key carries the level it was created with. An OAuth grant is **full** or **read-only**, as chosen on the consent screen, and acts as the administrator who approved it. A read-only grant is held to the `readonly` row below: only read-level tools are listed, and a write or admin tool named anyway is refused. The choice is stored with the grant and copied by every refresh, so nothing the client sends can raise it; a grant made before the choice existed stays full. To change one, revoke it under Connected apps and approve again.

| Level | Content only | + administration | + WooCommerce | What it can do |
|---|---|---|---|---|
| `admin` | 55 | 90 | 102 | Everything, including deletes, users and options |
| `readwrite` | 38 | 49 | 58 | Create and update, no destructive tools |
| `readonly` | 18 | 28 | 32 | Reads only |

These nine numbers are checked by `smoke-admin.sh` against a running site, because all
nine had drifted behind the code before anything checked them. Elementor, Kirki, Yoast SEO,
ACF, Gravity Forms, LearnDash, Elementor Pro, WP Activity Log, Redirection, Polylang, Rank Math and All in One SEO,
and the form-submission tools are not counted: their tools
are optional groups that come and go with a plugin, so folding them in would make the table
depend on what
happens to be installed.

A **named key** narrows this further. It carries its own level, an optional expiry date,
and an optional list of the only tools it may call, so a key handed to a deploy script
can be limited to reading posts and nothing else. Keys are stored hashed and shown once.
There is no shared token; an existing one is carried over into a key on upgrade so
nothing stops working.

### Drafts-only keys

A key can also be **drafts only**. It may create and edit drafts and pending posts, and upload media for them, and it is refused every write that would publish, schedule or make a post private, and every edit, trash, deletion or unpublishing of a post that is already live (publish, future or private, or any status a plugin registers as public). That covers post fields, custom fields, categories and tags, the featured image, block content, and revert through the change journal, whichever tool is asked, including the generated REST tools. Copying a live page into a draft is allowed, because the live page is only read.

The flag caps the key at read and write: a drafts-only key cannot have full administration, because those tools reach code that writes posts in ways this cannot see. It protects posts and the things stored as posts (pages, products, menu items, media on a live page). It does not cover comments, orders, taxonomy terms themselves, users or options, so pair it with a tool list when the key should do less than that. A refused call is recorded in the audit log like any other refusal. Keys made before this existed carry no flag and behave as they always did.

## Tools

Each group is a switch on the Tools page. A group that is off is not merely hidden: its
tools are refused if asked for by name.

**Content and site data**, on by default: posts and pages, block content, taxonomies and
terms, comments, media (including upload by URL or by a one-time upload link), users,
post meta, site options, post types, block patterns, and theme mods. `wp_copy_post_meta`
and `wp_duplicate_post` copy inside PHP so a large design never leaves the server;
`wp_write_post_meta_chunk` and `wp_read_post_meta_chunk` stage a value across several
calls. `wp_create_posts` creates up to twenty in one call. `wp_site_briefing` answers
"what am I looking at" in one call. `wp_write_blocks` takes either a list of block specs or
a Markdown string (headings, paragraphs, lists, blockquotes, fenced code, emphasis, links
and images); Markdown HTML is shown as text, not run.

**Site administration**, off by default: installing, activating, updating and deleting
plugins and themes; navigation menus and their items; widgets and widget areas; the
General, Reading and Discussion settings; the permalink structure; the site's scheduled
events; and a Site Health report. `wp_flush_cache` purges the object cache, expired
transients, or one post. `wp_purge_url` drops one page from each page cache it can name.
Options can be deleted, not only set. Three diagnostics ride on the same switch:
`wp_get_php_error_log` reads the end of the file PHP is configured to log to (it takes no
path, scrubs every line, and says whether logging is off, the file is absent, or it is
empty); `wp_fetch_page` requests one page of this site the way an anonymous visitor does
and reports status, redirects, robots signals and a bounded excerpt of the text (this
site's own host only, `admin` level); and `wp_create_preview_link` /
`wp_revoke_preview_links` make and end an expiring, non-indexed link to one unpublished
post, 24 hours by default and 7 days at most. Viewing a preview link is not logged.

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

**Rank Math and All in One SEO**, off by default, on one switch that appears when either is
installed. Per-post SEO title, meta description, canonical URL, `index` / `noindex` / default,
and the social (OpenGraph) title and description, read at `read` and written at `write`. Each
plugin's tools are offered only while that plugin is loaded. Rank Math keeps this in post
meta and its writes are journalled; All in One SEO keeps it in its own table, which the
change journal does not watch, so **a change there cannot be undone with `wp_undo_change`**
and the reply carries the previous values instead. Every write is read back from storage and
fails if it differs; the rendered page, page caches and sitemaps are not checked. `noindex`
and a canonical URL change how a page appears in search, so a canonical must be on this site
(another host needs the `gmcp_seo_canonical_hosts` filter), `robots` accepts only the three
values, and orders are refused. The write level is a judgement, argued in
[docs/guardrails.md](docs/guardrails.md#rank-math-and-all-in-one-seo), and it is weaker for
All in One SEO because no generic tool reaches its table. The All in One SEO focus keyphrase
is readable and not writable. How each plugin stores its data was written without an installed
copy to check and is flagged in the source.

**Form submissions**, off by default, on one switch that appears when Contact Form 7 or
Flamingo, Fluent Forms or Ninja Forms is installed. **Read only: nothing can be edited,
trashed or deleted.** A counts-only briefing per plugin at `read`; listing and reading
individual submissions at `admin`, because a submission is personal data a member of the
public wrote. Only fields present in the form's current definition and of a plain-input type
are returned (an allowlist), password and file-upload answers are named but never returned,
and IP addresses, user agents and referring URLs are never returned at any level. There is
no search over answers, because a text search over submissions is an oracle for the values
withheld. Every reply carries a `log_state` block that separates "none arrived" from "the
plugin that stores them is not installed", "its table is missing" and, for Ninja Forms, "this
form records nothing" — Contact Form 7 alone stores nothing, so it is Flamingo that is read.
Because Flamingo and Ninja Forms keep a submission as a post, the generic post readers
(`wp_get_post`, `wp_get_post_snapshot`, `wp_get_post_meta`, `wp_read_post_meta_chunk`,
`wp_get_posts`) now refuse those post types, whether or not this group is on. That guard is a
list of readers, and the guardrails page says what it does not cover.

**Advanced Custom Fields**, off by default, and the switch only appears when ACF is
installed: custom field discovery, value get/set through `update_field`/`get_field` with
field key references.

**Elementor Pro**, off by default, and the switch only appears when Elementor Pro is
installed — a deliberately small group, because popups, loop templates and mega menus are
library templates with theme-builder conditions that the Elementor tools above already
report. It answers what those cannot: whether Pro is really loaded and which of its modules
can be called, and how many form submissions exist. It counts submissions and never returns
one, and it checks that Pro's submission tables exist before counting, because Pro creates
them on the first submission and its own query layer answers "none" while they are missing.
It also inventories Custom Code snippets (title, location, priority, size and a hash) and
never returns or writes their code: Pro echoes a snippet unescaped into every page, so
writing one is an arbitrary persistent script injection with no restore behind it.

**WooCommerce Subscriptions**, off by default, and the switch only appears when it is
installed: the subscription list and one subscription's state, a recurring-revenue
briefing, and moving a subscription between the states a shop owner manages. It is
deliberately missing the three tools that look most useful. There is no tool that charges
a customer, no tool that changes the next payment date — WooCommerce Subscriptions accepts a
date in the past, which charges on the next queue run, and an empty value stops the
subscription renewing for good — and no immediate cancellation, because cancelled is
terminal and ends the customer's agreement at the gateway on gateways that manage billing.
The status tool offers only active ↔ on-hold and active ↔ pending-cancel, refuses
reactivating a subscription that has an unpaid renewal (it would restore service and cancel
the retry without collecting the debt), and reports every address it emailed. The reads
never return a customer's email, address, line-item names or payment method.

**Gravity Forms**, off by default, and the switch only appears when Gravity Forms is
installed: the form list and one form's field schema, a briefing of entry counts, and — at
`admin` because a submission is personal data a member of the public wrote — searching and
reading submissions, changing an entry's status or read/starred flags, overwriting one
answer, deleting an entry, and adding or listing entry notes. A submission edit and a
deletion each take the same server-minted two-step, because overwriting an answer destroys
the only copy and there is no restore tool. A password-type field is never returned or
writable, and a field whose type is not a plain answer (an upload, an opt-in, a repeater,
anything a plugin added) is refused rather than overwritten. Deleting an entry removes the
submission, its answers and its notes; it does not reach files the entry uploaded or data an
add-on stored elsewhere.

**LearnDash**, off by default, and the switch only appears when LearnDash is installed:
the course list and one course's structure (lessons, each with its topics and quizzes),
a counts-only briefing, and — at `admin` because they carry student data — who holds
access to a course and how, one user's reachable courses, one user's progress in a
course, and one quiz's questions. Enrolling and unenrolling act on the **direct**
enrolment only, and both report separately whether the user can also reach the course
for another reason (an open course, a LearnDash Group, or an administrator's automatic
access), because those reasons decide whether removing an enrolment actually does
anything. Unenrolling takes the same server-minted two-step as a deletion and refuses
when the access comes from a Group. Quiz **answer keys** are never returned; a question's
text and points are. Progress is lessons and topics only, as LearnDash's own percentage
is.

**WP Activity Log**, off by default, and the switch only appears when that plugin is
installed. This group **reads** a third-party security log; there is no write tool here and
there will not be one, because a writer is a way to reduce what the log holds, and this
plugin already ships an option writer that could reach the log's own settings — which is why
the option policy refuses that family first (see the commit that added
`audit_log_option_prefixes`). Three tools: a **counts-only briefing** at `read`, which
reports events by severity, object, event type and security family over at most 90 days; and
`wsal_list_events` and `wsal_get_event` at `admin`, matching this plugin's own log reader,
because an event row names a person.

What the tools refuse to return matters more than what they do. The metadata an event carries
is filtered by an **allowlist** — only names that describe the site — because a denylist
version turned out to be a credential-disclosure defect rather than an incomplete list: a
user-meta write stores the field's name in one key and both its values in others, with
nothing in any name to give it away, so the denylist returned a user's API token in
plaintext. The names of what was withheld are listed so an absence is visible. A value stored
as a serialised object is never read, only marked, since casting one still exposes every
property it holds. Filesystem paths are returned relative to the content directory. The
failure-login alerts do not return their message at all, because it is built from what someone
typed at the login form and that field regularly holds a mistyped password.

Each reply carries a `log_state` block, which is what separates "nothing happened" from
"nothing is being recorded" — the same false-zero trap Gravity Forms and Elementor Pro have.
It reports whether the plugin is loaded, whether the tables exist, whether storage is
external, how many events the log holds and how recent the newest is, and how many alert
types are switched off. A read-level key gets a recency bucket rather than an exact time.

**No search, no IP list, no user ranking, and no alert catalogue.** Searching would have to
LIKE across the metadata table, which is an oracle for exactly the values the tools withhold;
an IP list is a behavioural record of an identifiable person and this group takes no action a
list could feed; a per-user activity ranking is profiling, and also wrong, because REST
actions are recorded against whichever user the bearer key borrows. Each is a deliberate
absence, not an omission to be helpfully filled in.

**Redirection**, off by default, and the switch only appears when the Redirection plugin is
installed. Five tools: list and get at `write`, and create, update and switch on/off at
`admin`. A redirect is the one change that can both hand your visitors to someone else's site
and lock you out of your own, so the tools keep a policy of their own. The **target** must be
a path on this site or a URL on this site's own host; an `https` URL on any other host is
refused unless the site lists that host through the `gmcp_redirect_allowed_hosts` filter,
which is empty by default (exact host names, default port, no userinfo, no wildcard). The
**source** must be a literal path; regular-expression sources are refused unless the site opts
in through `gmcp_redirect_allow_regex`, and are then still refused if the pattern matches a
protected path. A source on `wp-login.php`, `wp-admin`, the REST API, this plugin's endpoint or
the OAuth discovery documents is refused however it is spelled (case, percent-encoding,
`..` segments, `index.php/`, a `rest_route` query, a site in a subdirectory). Only the plain
URL match with the plain redirect action is managed: the conditional match types (agent,
referrer, cookie, IP, login status) decide what to show based on who is asking, which is how
a redirect gets hidden from the owner. The policy runs on the **stored** row as well as the
request. Every write is read back and a row that fails is switched off, and **enabling** an
existing redirect re-checks it first, so a redirect made in the Redirection screen cannot be
switched on through here without passing. There is **no delete tool**: switching a redirect
off is the reversible removal, while a deletion also discards its hit count and cannot be put
back. The change journal does not cover these rows, because they live in Redirection's own
tables; the audit log records each call. List size is capped at 50. Replies carry a
`log_state` block (loaded, tables present, count) so "no redirects" is not confused with
"Redirection was never set up".

**Polylang**, off by default, and the switch only appears when Polylang is installed. Read
only: `pll_list_languages` at `read` and `pll_get_post_translations` at `write` (a translation
group can include drafts and private posts; it returns IDs, languages, statuses and post
types, never titles or content). Both answer through Polylang's own `pll_*` functions and
keep three empty answers apart: Polylang with no languages defined (`not_configured`), a post
with no language or of a post type Polylang does not manage, and a post with a language and no
siblings (`no_translations`). There is no tool that links posts or assigns a language. WPML is
not covered and is deferred: its data model and its "not configured" states are different,
and a reader written without it installed is how a confident "no translations" is produced.

**REST API tools**, off by default: tools generated from the site's own REST API routes.
Generated once and cached for a day; the cache is thrown away on the first request after
the plugin's version changes.

**Preview.** Six tools whose effect is not visible from the call accept `preview`, which
describes what would happen and changes nothing: `wp_delete_post`, `wp_update_post`,
`wp_alter_post`, `wp_delete_term`, `wp_delete_media` and `wp_delete_comment`.

**Undo.** `wp_list_changes` and `wp_undo_change` put back something an agent *modified*:
a setting, a post, a custom field, a term's name, slug, description or parent (which
includes renaming a menu), which terms a post carries, and an individual theme setting
(a menu-location assignment is one). A menu item's edit is covered because a menu item is a
post and some custom fields. Only writes made during a tool call are recorded, never a
person's own edits. **Creations, deletions and users are not covered**, and adding or
deleting a menu item is a creation or a deletion. An undo is itself recorded, so
reverting the undo puts the edit back; that second step is refused if the thing has
changed since, so it cannot overwrite newer work with an older copy.

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

At **MCP Server** in the admin menu, in six pages. It is top level rather than buried
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
have connected. **Connected apps** shows whether each grant is full or read-only.

**Keys** lists the named keys. Each row shows the label, its access level (and "drafts only" where set), the tools it is
limited to, when it expires and when it was last used, with a control to revoke it. A new
key's secret appears once, on creation, and is not recoverable afterwards.

### Tools

Which groups of tools an agent is offered, and nothing else. A group that is off is not
merely hidden: its tools are refused if asked for by name.

### Approvals

Off by default. When switched on, a tool call that would delete something, or change the
site, does not run: it is stored as a pending request, the agent is told it is waiting
for human approval (a normal reply, not an error) and is given a request id. A signed-in
administrator sees the request here, with its tool, the key that asked and its redacted
arguments, and answers it with **Approve** or **Decline**. After an approval the agent
repeats the identical call and it runs once.

- **Ask before** is `never`, `deletes`, or `deletes and site changes`. It applies to every
  key and every connected app, so a key made later is covered too. Which tools count is
  worked out from each tool's own access level and annotations rather than from a list of
  names: deletions, anything the plugin cannot classify (held in both modes, so an
  unrecognised tool fails closed), and, in the wider mode, every other `admin`-level write
  (plugins, themes, users, options, settings, menus, and the writes of the optional
  groups). Content edits at the `read` and `write` level never wait.
- Creating an account, and changing a password, email address or role on one (or anything on an account that can run the site), waits in both modes, because an agent that can make an administrator could sign in as it and approve its own calls. For the same reason an account that a tool call created or re-credentialed in the last 24 hours cannot answer requests: use another administrator account, or wait. Nothing on any screen clears that; `wp user meta delete <id> gmcp_changed_by_mcp` does.
- A pending request lapses after an hour. An approval lasts 15 minutes and works once, for
  that key, that tool and exactly those arguments (a hash of the canonical JSON, keyed so
  a stored row cannot be used to guess a password offline). A different argument, key or
  tool is a different request. An identical call after the approval was used is refused
  and asks again.
- It is out of band because there is nothing to hand the agent: no approval token exists.
  A request only leaves "pending" through the wp-admin screen, which requires a logged-in
  session cookie that belongs to the current user, `manage_options`, a nonce bound to that
  request and verdict, and a request that is not a tool call or a REST call. No tool reads,
  lists, approves, declines or edits the queue, at any access level.
- The two-step confirmation still applies inside an approved call, and one approval covers
  both legs: a call that only issued a confirmation token has not spent it.
- The screen shows the call in one canonical order (sorted, the tool's declared arguments
  first) and never shortens it: a call with an argument its tool does not declare, nested
  more than 12 levels, or longer than 16000 bytes written out is refused with an error
  instead of queued, because an approval covers every byte. A credential is hidden, and the
  screen says how many values are hidden and which fields, so you know a value you cannot
  see is part of what you approve (the hash covers the real value). Requests,
  approvals, declines, lapses, uses and refused replays are written to the audit log.
  There are no bulk actions and nothing to delete: a request leaves the list by being
  answered or by lapsing.

What it does not do: it does not make a gated tool safe, only attended. It does not gate
read-level or write-level tools, `wp_undo_change` (a write-level tool that replays earlier
values), or the one-time upload endpoint behind `wp_upload_request`. A preview waits like
the call it previews. A tool at the `admin` level with no destructive verb, annotation or
read verb in its name is a site change, not a deletion, so it waits only in the wider mode.

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
| `gmcp_fetch_allow_private` | Let `wp_fetch_page` connect to a private address for a site whose own host name is not obviously a local one. The link-local and reserved ranges cannot be opened this way |
| `gmcp_allow_unfiltered_post_html` | Store post HTML unfiltered |
| `gmcp_allow_unfiltered_widget_html` | Store widget HTML unfiltered |
| `gmcp_instructions` | The text a client shows its model at session start. Receives the default and the site URL; return a string to replace it |

## Security notes

- Only administrators can authorize an OAuth client, checked both at authorize time and again on every request. A grant approved as read-only stays read-only through every refresh.
- A drafts-only key cannot put anything in front of the public or change what is already there, through any tool, because the refusal sits under the post writes rather than in each tool. It does not cover writers that bypass WordPress and update the posts table directly, which is why the key is held below full administration. Orders, comments, users and options are outside it.
- Keys are stored as a SHA-256 hash and shown once, so the database holds nothing that can be replayed. A key also records the administrator who created it and acts as that account, which is what lets the log name a person; it stops working the moment that account stops holding `manage_options`, so revoking somebody's access revokes their agent with it.
- No row belonging to this plugin, and no option whose name looks like a credential, can be read or written through the option tools. The change journal additionally inspects the value it is about to record, so a `secret_key` or `pass` field inside a settings array is blanked before the rest of that array is kept, and an undo leaves the live value in place rather than writing the placeholder over it. Strings that parse as JSON or as a serialized array are unpacked and judged too, and blanked whole when they hold one. The check reads field names, not content, so it will not catch a secret held as a bare string under an innocuous option name.
- Optional human approval holds deletes, and optionally site changes, until an administrator signed in to wp-admin approves that exact call. There is no approval token for an agent to learn or mint, and no tool that reads or answers the queue. See Approvals above.
- The `readwrite` level cannot delete by another name. WordPress permanently deletes whatever carries an old `_wp_trash_meta_time` and every auto-draft older than a week, so setting a status of `trash` or `auto-draft` through `wp_update_post` or `wp_update_comment`, and writing any `_wp_trash_meta_` meta key through any tool, are refused; `wp_delete_post` is the way to trash and is held by the approval queue. `wp_run_cron_event` is held with the deletes.
- Tools do not execute arbitrary PHP or SQL. Every tool is a fixed WordPress operation with a schema.
- A preview link is a bearer credential for one unpublished post. Only a hash of it is stored, in a row the option tools refuse, and the audit log and debug trace scrub it from arguments and replies. It is shown once. Anyone who is given the link can use it until it expires or the post is published, and viewing it is not logged.
- `wp_get_php_error_log` and `wp_fetch_page` return text written by other people (visitors, plugins, whoever controls a page). Both label it as data, and both are `admin` level for that reason as well as for what they can reach. The log is scrubbed for credential shapes, not for meaning, so a secret inside an ordinary sentence can survive it.
- An open stream holds one PHP worker for up to 180 seconds. Size your pool accordingly if several agents connect at once.
- **The token-in-URL endpoint is gone, and so is the shared bearer token.** If you were connecting over either, connect with a named key in the `Authorization` header instead. An existing shared token is carried over into a key on upgrade, so it keeps working through the header without you doing anything; it is no longer readable back from the screen or the database. If your host strips the header, the fix is the header: re-saving your permalink structure regenerates the `.htaccess` rule that forwards it, and the connection check on the settings screen tells you whether it worked.

## Development

Lint without a local PHP install:

```
docker run --rm -v "$PWD":/app -w /app php:8.1-cli sh -c 'for f in includes/*.php *.php; do php -l "$f"; done'
```

A throwaway WordPress for testing lives in `.dev/` (see that directory's README). It carries
fourteen suites: `smoke.sh` for the transport, auth, content tools, prompts, resources and
previews; `smoke-admin.sh` for the administration tools, the change journal, named keys and
every guard around them, which rebuilds `.htaccess` partway through and is the destructive
one; `smoke-woo.sh` for the shop tools; `smoke-elementor.sh` for the Elementor tools;
`smoke-kirki.sh` for the Kirki tools; `smoke-yoast.sh` for the Yoast SEO tools;
`smoke-acf.sh` for the ACF tools; `smoke-gravityforms.sh` for the Gravity Forms tools;
`smoke-learndash.sh` for the LearnDash tools; `smoke-elementor-pro.sh` for the Elementor Pro
tools; `smoke-woo-subscriptions.sh` for the Subscriptions tools; `smoke-wsal.sh` for the
WP Activity Log tools; `smoke-redirection.sh` for the Redirection tools; `smoke-polylang.sh`
for the Polylang tools; `smoke-seo.sh` for the Rank Math and All in One SEO tools; and
`smoke-forms.sh` for the form-submission tools. `smoke-seo.sh` and `smoke-forms.sh` were
written without a running stack and have not been run. All but the first two need their
plugin installed.

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

### Releasing

1. Set the new version in **three** places: `Version:` and the `GMCP_VERSION` define in
   `guarded-mcp.php`, and `Stable tag:` in `readme.txt`. `.dev/check-release.sh version`
   fails if they disagree, and CI runs it on every push and pull request.
2. Merge to `main`. The release workflow refuses a tag whose commit is not reachable from it.
3. Tag the merged commit and push the tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.

The tag runs the release workflow, which checks the versions against the tag, builds the zip
with `.dev/build.sh`, checks its contents, and publishes it as a GitHub Release with checksums
and a provenance attestation. Nothing is sent to wordpress.org. The install links in this
README resolve only once the first release exists.

The workflow runs in the `release` environment. Add required reviewers to that environment
and restrict who may create `v*` tags (Settings, Environments and Rules): a tag's workflow
file comes from the tagged commit, so the on-main check alone does not stop someone with
write access.

## Licence

GPLv2 or later. Parts of this plugin derive from prior GPL work.
