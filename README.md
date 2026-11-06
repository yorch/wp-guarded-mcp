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

If your host strips the `Authorization` header before PHP sees it, there is no alternative path: re-save your permalink structure to regenerate the `.htaccess` rule that forwards it, and use the connection check on the settings screen to see whether it worked.

## Access levels

An access level belongs to a named key, or to an OAuth grant. A key carries the level it was created with. An OAuth grant is **full** or **read-only**, as chosen on the consent screen, and acts as the administrator who approved it. A read-only grant is held to the `readonly` row below: only read-level tools are listed, and a write or admin tool named anyway is refused. The choice is stored with the grant and copied by every refresh, so nothing the client sends can raise it; a grant made before the choice existed stays full. To change one, revoke it under Connected apps and approve again.

| Level | Content only | + administration | + WooCommerce | What it can do |
|---|---|---|---|---|
| `admin` | 56 | 95 | 107 | Everything, including deletes, users and options |
| `readwrite` | 39 | 52 | 61 | Create and update, no destructive tools |
| `readonly` | 19 | 29 | 33 | Reads only |

These nine numbers are checked by `smoke-admin.sh` against a running site, because all
nine had drifted behind the code before anything checked them. Elementor, Kirki, Yoast SEO,
ACF, Gravity Forms, LearnDash, Elementor Pro, WP Activity Log, Redirection, Polylang, Rank Math and All in One SEO,
Wordfence, Stream, Beaver Builder, the form-submission tools and the site-file export group
are not counted: their tools are optional groups that come and go with a plugin (or, for the
export group, with its switch), so folding them in would make the table depend on what
happens to be installed or enabled.

A **named key** narrows this further. It carries its own level, an optional expiry date,
and an optional list of the only tools it may call, so a key handed to a deploy script
can be limited to reading posts and nothing else. Keys are stored hashed and shown once.
There is no shared token; an existing one is carried over into a key on upgrade so
nothing stops working.

### Drafts-only keys

A key can also be **drafts only**. It may create and edit drafts and pending posts, and upload media for them, and it is refused every write that would publish, schedule or make a post private, and every edit, trash, deletion or unpublishing of a post that is already live (publish, future or private, or any status a plugin registers as public). That covers post fields, custom fields, categories and tags, the featured image, block content, and revert through the change journal, whichever tool is asked, including the generated REST tools. Copying a live page into a draft is allowed, because the live page is only read.

The flag caps the key at read and write: a drafts-only key cannot have full administration, because those tools reach code that writes posts in ways this cannot see. What it covers is posts and the things stored as posts (pages, products, menu items), their custom fields, category and tag assignments, featured image, and media attached to a post. Media attached to no post can be neither edited nor deleted by it, because a live page may be showing that file and nothing here can tell which. What it does **not** cover: comments (it can create, approve and edit them), taxonomy terms themselves (renaming or deleting a category changes every live post in it), users, options, and anything else that is not a post. Orders and subscriptions are refused to the generic post tools for every key, and the shop tools need `admin`, which this flag caps away. A preview link on a draft is not out of its reach either: it shows that draft to whoever holds the URL. Pair the key with a tool list when it should do less than that. A refused call is recorded in the audit log like any other refusal. Keys made before this existed carry no flag and behave as they always did.

## Tools

Each group is a switch on the Tools page. A group that is off is not merely hidden: its
tools are refused if asked for by name.

**Content and site data**, on by default: posts and pages, block content, taxonomies and
terms, comments, media (including upload by URL or by a one-time upload link), users,
post meta, site options, post types, block patterns, and theme mods. `wp_copy_post_meta`
and `wp_duplicate_post` copy inside PHP so a large design never leaves the server;
`wp_write_post_meta_chunk` and `wp_read_post_meta_chunk` stage a value across several
calls. `wp_download_request` returns a one-time URL that streams an attachment's file, so a
file's bytes never pass through a tool reply or the audit log; a file attached to a post that is
not published can only be requested with an admin-level key, a file attached to a form submission
(or to anything hanging from one) is not available at any level and, below admin, answers exactly as
a missing attachment does, the token expires after five minutes at the endpoint and not only in the
reply, and the endpoint refuses anything that does not resolve inside the site's uploads directory.
The token in the URL is scrubbed from the audit log and the debug log like a preview link's. No
generic tool writes `_wp_attached_file`, because repointing an attachment at another file under
uploads would move that file out from under these rules. `wp_create_posts` creates up to twenty in one call. `wp_site_briefing` answers
"what am I looking at" in one call. `wp_write_blocks` takes either a list of block specs or
a Markdown string (headings, paragraphs, lists, blockquotes, fenced code, emphasis, links
and images); Markdown HTML is shown as text, not run.

**Site files**, off by default: `wp_export_request` reads one of four named roots —
`plugins`, `themes`, `mu-plugins` or `uploads` — with an optional relative path under it. In
its default `manifest` mode it walks the tree and reports the file count, total bytes, depth,
a digest and the entries that WOULD be excluded with the reason (symbolic links, hard links,
non-regular files, unreadable entries, anything resolving outside the root, names that are not
safe in an archive, files private by name, and uploaded files that belong to a form
submission). The digest covers
each file's name, size and contents, so a file rewritten in place is a change. It never returns
file contents, and it refuses over 20,000 entries or 512 MiB rather than truncating the count.
Its `pull` mode takes the digest from a manifest, refuses if the tree has changed, and always
waits for a signed-in administrator on the Approvals screen, whatever `mcp_approval_mode` is
set to. Only after that approval does it build a zip outside the web root, with 0600
permissions, and return a one-time URL that streams it for five minutes; the archive is never
returned in a tool reply, and the token is scrubbed from the audit log. `uploads` means all of
uploads, which on a real site can contain customer exports — that is why the group is off
until somebody switches it on. Some things are withheld in every root and are never read:
dot-files (`.env`, `.htpasswd`), `wp-config*`, private keys and keystores, database dumps and
SQLite files, logs, backup archives and editor leftovers; in `uploads`, the private directories
of the common form, shop and backup plugins; and every uploaded file that belongs to a form
submission (the files `wp_download_request` already refuses at every level), including the sizes
WordPress generated from it. Naming one of them in `path` is refused. This is a list of names
and a database question, not a content scan: a secret in a file with an ordinary name (a
`config.php` in a plugin) is archived, a file no attachment row points at in a directory the
list does not name is archived, and the plugin directory names are written from memory of each
plugin's layout (extend them with `gmcp_export_private_directories` and `gmcp_export_private_names`).
A tree whose every entry is withheld has nothing to archive and the pull says so.

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
`wp_revoke_preview_links` (ending every link on the site needs an admin-level key) make and end an expiring, non-indexed link to one unpublished
post, 24 hours by default and 7 days at most. Viewing a preview link is not logged.

**WooCommerce**, off by default, and the switch only appears when the shop is installed:
products, stock levels, orders, order notes, customers, a sales summary and a store
briefing. Tools carrying personal data (orders, customers) are `admin`, not `read`;
products, stock and sales figures stay at `read`. Refunds are not included. The generic post
and comment tools treat an order as WooCommerce's order table does, by its id, whichever storage
the shop uses: they do not write to an order, to a post or comment that shares its id, or to an
order note (`wc_add_order_note` adds one), at any access level, and below admin they do not
return them.

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
values (`default` clears every robots directive on the post, because Rank Math only falls back
to the site-wide setting when none is stored), a drafts-only key cannot write to a live page,
and orders are refused. The write level is a judgement, argued in
[docs/guardrails.md](docs/guardrails.md#rank-math-and-all-in-one-seo), and it is weaker for
All in One SEO because no generic tool reaches its table. The All in One SEO focus keyphrase
is readable and not writable. How each plugin stores its data was written without an installed
copy to check and is flagged in the source.

**Form submissions**, off by default, on one switch that appears when Contact Form 7 or
Flamingo, Fluent Forms, Ninja Forms or WPForms is installed. **Read only: nothing can be edited,
trashed or deleted.** A counts-only briefing per plugin at `read`; listing and reading
individual submissions at `admin`, because a submission is personal data a member of the
public wrote. Only fields present in the form's current definition and of a plain-input type
are returned (an allowlist), password and file-upload answers are named but never returned,
and IP addresses, user agents and referring URLs are never returned at any level (which includes
the Contact Form 7 mail subject: Flamingo builds it from a template that can contain them, so it is
returned only when the form uses the default `[your-subject]`, and otherwise withheld with a note;
Fluent Forms' own setting that deletes entries on submission is reported, since a zero for such a form means nothing). There is
no search over answers, because a text search over submissions is an oracle for the values
withheld. Every reply carries a `log_state` block that separates "none arrived" from "the
plugin that stores them is not installed", "its table is missing" and, for Ninja Forms, "this
form records nothing" — Contact Form 7 alone stores nothing, so it is Flamingo that is read.
Because Flamingo and Ninja Forms keep a submission as a post, the generic post readers
(`wp_get_post`, `wp_get_post_snapshot`, `wp_get_post_meta`, `wp_read_post_meta_chunk`,
`wp_get_posts`, `wp_get_post_terms`, `wp_get_comments`, `wp_get_media`, the briefing, the
resources and the REST-generated tools) refuse those post types and what hangs off them (a
revision, an upload, a comment, a child post), and below admin answer one exactly as they
answer a post that does not exist. No generic tool writes to a submission or to anything in its
tree, at any access level, and none moves a post or a comment into or out of such a tree or
points a featured image or a menu item at one, so the tree cannot be rearranged to get round
the readers. The change records withhold a submission's title and values whichever state of it
they describe, whether or not this group is on. Those guards are a list of readers and writers,
and the guardrails page says exactly what they do not cover (counts, for one).

**WPForms** is in that group and is deliberately the smallest part of it: two tools, a form's
schema and a submission briefing, and **no entry tool at all**. Measured on WPForms Lite
2.0.3.1: `wpforms()->obj('entry')` is null, the plugin ships no entries component, and none of
`wpforms_entries`, `wpforms_entry_fields` or `wpforms_entry_meta` exist — Lite counts
submissions in form meta (`wpforms_entries_count`, the key its own reports class reads) and
emails them. So on the free plugin there is nothing to read, and on Pro the tables have never
been measured from here, which is not a good enough footing for personal-data handling no test
can exercise. The briefing names its source for every count, because "no submissions" and
"nothing is storing submissions" look identical as a number. A form is a `wpforms` post whose
post content is one JSON document holding the fields, the notification recipients and the
confirmation redirects, so `wpforms_get_form` counts those settings and never returns them —
and the generic post tools are refused on that post type, below admin for reads and for every
write, because otherwise the withholding would be decoration. A saved user template is the same
document under a second post type, `wpforms-template` (measured on Lite 2.0.4: registered
there, and read through `wpforms_decode()` like a form), so it is refused the same way. The
SQL readers ask the same list below admin (`read_excluded_post_types()`), so a listing does not
depend on the per-row check alone, and a REST route one of these types takes over is not turned
into tools.

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
typed at the login form and that field regularly holds a mistyped password. An event about a
form submission or its contact record (the `gmcp_personal_data_post_types` list) returns no
message and no title, old title, new title or address, at admin level too, because a form store
titles a submission from what the visitor typed ("Enquiry from" and their IP address) and names
a contact by email address, and the log copies that title into every event about the post. It
is judged by the type the log recorded, which is all that is left once the submission is
deleted, and by the post's parent chain while the post still exists, so an upload under a
submission is held as well. What it does not cover: an upload to a submission that has since
been deleted records no post and no type, and its file name (which a visitor chose) is not
recognised.

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

**Stream**, off by default, and the switch only appears when Stream is installed: a counts-only
briefing by connector, context and action over at most 90 days at `read`, and the individual
records at `admin`. It is a separate group from WP Activity Log rather than an adapter behind
the same names, because the two store different shapes — Stream has one row with a connector,
a context and a rendered summary, WP Activity Log has an alert id, a severity and a metadata
table — and a shared tool name would have to lie about one of them. What is shared is the
policy, deliberately: read only, counts at read, rows at admin, no IP address at any level, an
allowlisted metadata reader, no free-text search, no per-user ranking. The metadata allowlist
exists because of a measured value: on a record for a created user, the new account's email
address is the first of several values under a repeated key called `user_meta`, with nothing
in the name to give it away. Stream's retention is a real hole rather than a hypothetical one
— measured on this stack, one `wp_update_option` setting `general_records_ttl` to 1 was
accepted and stored — so `wp_stream` is refused by the same rule that refuses `wsal_`, and it
is a prefix rule because it covers `wp_stream`, `wp_stream_db` and `wp_stream_network`.

**Wordfence**, off by default, and the switch only appears when Wordfence is installed. Three
read tools answering "is this site being watched, and is the watching working": `wf_status`
at `read` (firewall mode, login-security thresholds, whether a scan is running, whether one
has ever completed, issue counts by status and severity, blocks by type, and a list of the
states that mean protection is weaker than the settings suggest), `wf_scan_issues` and
`wf_lockouts` at `admin`. The status tool is the one that matters: on a fresh install every
count is zero and the honest answer is "nothing has looked yet", so the scan block reports
`never` alongside the counts, read from the same key Wordfence's own status widget reads. The
firewall's `learning-mode` is reported with what it means, because "enabled" and "blocking"
are different claims. `wf_lockouts` lists only the block types that name one address — a
country or pattern block stores an all-zero IP, which renders as `::` — and the counts in
`wf_status` break down by type so those are not lost. A login lockout's recorded reason ends
in what the visitor typed at the login form (`... was: 'username'`, and the same for the
password-reset form and for an invalid username), which regularly is a mistyped password or an
email address, so that text is replaced with `[withheld: typed text]` and the fixed wording
around it is kept. Only those three templates of Wordfence's are recognised; a reason of any
other shape is returned as stored. There is **no write tool, and there will
not be one**: dismissing an issue, whitelisting an address, changing a firewall mode or
starting a scan are each a way to make the watcher quieter, and an agent that can quiet it and
then read it back can confirm its own silence. No configuration value that is a credential or
a person is returned — Wordfence keeps `apiKey`, `encKey` and `alertEmails` in the same table
as the values reported here, and none of those names appears in the code. Issue long messages
and data blobs are withheld at every level, and the message a failed scan recorded is withheld
from a read key because it is an exception message and those carry paths. Measured while
building this: the option writer cannot reach any of it, because Wordfence 9.0.2 keeps its
configuration in its own `wp_wfconfig` table and rewrites its version option on load, so the
protection here is that the group has no writers rather than a prefix rule.

**Beaver Builder**, off by default, and the switch only appears when Beaver Builder is
installed. The trap this group exists for is that **the layout is not `post_content`**: a page
keeps its layout in `_fl_builder_data` and the front end renders from that, so a generic write
to the body appears to work and leaves the page exactly as it was. A page can also hold
unpublished builder changes in `_fl_builder_draft`, so a reader that reports only the published
copy can describe a layout no visitor sees — every reply says whether drafted changes exist and
reports both copies' node counts. The tools report the SHAPE and never the settings: a node's
`settings` is the content and, for the `html` module, arbitrary markup including script, and
`_fl_builder_data_settings` holds layout-level `css` and `js` that run on every page using the
layout. So node types, counts and depth are returned, layout setting *names* are returned,
whether custom code is present is returned, and no value of either is. The layout keys are not
readable through the generic meta tools either: `wp_get_post_meta` and `wp_get_post_snapshot` list
them as withheld, and a key is judged the way the database compares it, so an accented or
space-padded spelling is the same key. `beaver_layout` reports a post's title, type and status, so
it asks the same read policy the generic readers do: a form submission, and below admin an order
or another plugin's configuration, answers as a post that does not exist. There is no tool that
edits or replaces an existing layout, and none that accepts layout settings from the caller: it
would be persistent script injection, the same reasoning that keeps a Custom Code writer out of
the Elementor Pro group. The writes are `beaver_clear_cache`, which deletes the generated CSS/JS
so the builder regenerates them — derived files, admin level, and it says so when the site
enqueues the builder's CSS inline and there is nothing to delete — and, behind a second switch
(`mcp_tools_beaver_write`, off by default), `beaver_create_page`. That tool makes a NEW page from
a typed list of four module types (heading, rich text, button, photo) and has no argument that
names an existing post. The builder's own writer applies no sanitising, so the tool cleans every
value itself: text through `wp_kses_post` or as plain text, a link as an absolute http(s) URL with
a host and no user information or a path on the site (judged after sanitising, so a tab inside
`//` is still refused), a fixed set of heading tags, and a photo only by attachment id, which must
be an image and must be one a featured image could use (a form submission's upload is refused).
`bb_js_code`, `bb_css_code`, `custom_attributes`, `attributes`, `popup`, `lightbox_content_html`,
`photo_url` and `photo_src` are refused by name. It takes at most 50 modules, 100,000 bytes in a
field and 400,000 in all. The page is made as a draft and made public last; the reply is the
stored layout read from the table, and a page whose layout did not store as built is deleted
again before the tool answers. It is admin level and classed as a site change by the approval queue
(held in its wider mode, not in the deletes-only one). The change journal records the layout rows it writes and can undo them, but it has no
entry for the creation of a page, which comes off with `wp_delete_post`. A rich-text line that is
only a URL becomes a link, because the module would otherwise fetch it as an embed when the page
renders. Not covered: what a caller writes in rich text is theirs (a link in a post body can point
anywhere), and a theme or plugin that renders the module's output differently. There is no tool that applies or imports a
template: applying a saved one is reversible, importing one rewrites the design system from an
archive built elsewhere in a single call.

**Redirection**, off by default, and the switch only appears when the Redirection plugin is
installed. Five tools: list and get at `write`, and create, update and switch on/off at
`admin`. A redirect is the one change that can both hand your visitors to someone else's site
and lock you out of your own, so the tools keep a policy of their own. The **target** must be
a path on this site or a URL on this site's own host; an `https` URL on any other host is
refused unless the site lists that host through the `gmcp_redirect_allowed_hosts` filter,
which is empty by default (exact host names, default port, no userinfo, no wildcard). The target
must also survive `wp_sanitize_redirect()` unchanged: that function deletes characters, so
`/^/evil.com` would be sent as `//evil.com`, and any target it would alter is refused. The
**source** must be a literal path; regular-expression sources are refused unless the site opts
in through `gmcp_redirect_allow_regex`, and are then still refused if the pattern matches a
protected path (a `%` is refused in a pattern, because Redirection decodes it twice). A source on
`wp-login.php`, `wp-admin`, `wp-cron.php`, `xmlrpc.php`, the REST API, this plugin's endpoint or
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
the plugin's version changes. A route that belongs to a form-submission or commerce post
type is not generated at all, and every call is judged by the same read policies as the
generic post tools (including media attached to a submission or an order); a list leaves
those rows out where the query is built, to the full depth of their tree.

**Preview.** Six tools whose effect is not visible from the call accept `preview`, which
describes what would happen and changes nothing: `wp_delete_post`, `wp_update_post`,
`wp_alter_post`, `wp_delete_term`, `wp_delete_media` and `wp_delete_comment`.

**Undo.** `wp_list_changes` and `wp_undo_change` put back something an agent *modified*:
a setting, a post, a custom field, a term's name, slug, description or parent (which
includes renaming a menu), which terms a post carries, and an individual theme setting
(a menu-location assignment is one). A menu item's edit is covered because a menu item is a
post and some custom fields. Only writes made during a tool call are recorded, never a
person's own edits. **The creation or deletion of a post, term, menu or menu item, and changes to users, are not covered**, and adding or
deleting a menu item is a creation or a deletion. The options WordPress itself writes as a side effect of a creation
(`wp_calendar_block_has_published_posts` under `wp_create_post`, for one) are ordinary option writes and are journalled
under the tool that ran; the created post is not. An undo is itself recorded, so
reverting the undo puts the edit back; that second step is refused if the thing has
changed since, so it cannot overwrite newer work with an older copy. For a custom
field that includes a row someone added to the same key in the meantime.

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
- An undo waits exactly where the write it would repeat waits: `wp_undo_change` is looked up in the change journal and held as the strictest tool that made the recorded change (an option put back waits in the wider mode; a post edit put back does not), and an entry it cannot find is held.
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
  instead of queued, because an approval covers every byte. A field named like a credential, or a JSON
  string with one inside, is hidden whole. In any other text only the part that looks like a credential
  is hidden (a `define( 'DB_PASSWORD', ... )` line inside a tagline), the rest of the text stays
  readable with `[hidden values present: N]` after it, and the
  screen says how many values are hidden and which fields, so you know a value you cannot
  see is part of what you approve (the hash covers the real value). Each text is judged on its own: a
  secret split across two arguments is not recognised by the screen. Requests,
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

The record itself: every tool call, refusals included, with the arguments it was given
(credential-shaped fields and strings that carry a pasted secret are replaced by `[redacted]`),
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
- A drafts-only key cannot publish a post or change a post that is already live, through any tool, because the refusal sits under the post writes rather than in each tool. That is posts, their custom fields, term assignments, featured image and attached media: not comments, taxonomy terms, users, options, or media attached to no post (edits to which are refused because a live page may be showing it). It does not cover writers that bypass WordPress and update the posts table directly, which is why the key is held below full administration.
- Keys are stored as a SHA-256 hash and shown once, so the database holds nothing that can be replayed. A key also records the administrator who created it and acts as that account, which is what lets the log name a person; it stops working the moment that account stops holding `manage_options`, so revoking somebody's access revokes their agent with it.
- No row belonging to this plugin, and no option whose name looks like a credential, can be read or written through the option tools. The change journal additionally inspects the value it is about to record, so a `secret_key` or `pass` field inside a settings array is blanked before the rest of that array is kept, and an undo leaves the live value in place rather than writing the placeholder over it. Strings that parse as JSON or as a serialized array are unpacked and judged too, and blanked whole when they hold one. Free text is read as well, so a post whose body has a pasted `{"api_key": "..."}`, a `define( 'DB_PASSWORD', ... )` line or an `Authorization: Bearer` header in it is not copied into the journal or the audit log: that field is left out of the undo record and the rest of the edit stays undoable. This is a heuristic for the shapes people paste, tuned so that prose which merely mentions a password is kept and undone as usual; it will not catch a secret that is a bare word under an innocuous name.
- Undoing an edit never sets a post's status to `trash` or `auto-draft`, for the same reason the write tools refuse those statuses: WordPress deletes on them from a daily job. The other fields of that entry are still restored.
- Optional human approval holds deletes, and optionally site changes, until an administrator signed in to wp-admin approves that exact call. There is no approval token for an agent to learn or mint, and no tool that reads or answers the queue. See Approvals above.
- Site-file export is off by default, and its manifest returns counts and exclusions rather than file contents. Private files by name (dot-files, `wp-config`, keys, dumps, logs, backups) and uploaded files that belong to a form submission are withheld from the manifest and the archive; a secret inside a file with an ordinary name is not found. A pull is always held for a person, whatever the approval mode says; the approval is bound to the manifest digest — which covers each file's name, size and contents — so a tree that changed after it was read needs a new one, and one whose file was rewritten in place is refused when the pull runs. The archive is built outside the web root with 0600 permissions, streamed once through a five-minute token, and deleted after it is sent, including when the client disconnects mid-download. The token is scrubbed from the audit log, like the download and upload tokens.
- The `readwrite` level cannot delete by another name. WordPress permanently deletes whatever carries an old `_wp_trash_meta_time`, every auto-draft older than a week, and, where Akismet or a similar plugin is active, every comment marked spam for fifteen days, so setting a post status of `trash` or `auto-draft` through `wp_update_post`, a comment status of `trash` or `spam` through `wp_update_comment`, and writing any `_wp_trash_meta_` meta key through any tool (in any spelling the database reads as the same key: case, trailing spaces, accents, invisible characters), are refused; `wp_delete_post` and `wp_delete_comment` are the way to delete and are held by the approval queue. An agent therefore cannot mark a comment as spam at any level; a person does that in wp-admin. `wp_run_cron_event` is held with the deletes.
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
twenty-three suites: `smoke.sh` for the transport, auth, content tools, prompts, resources and
previews; `smoke-admin.sh` for the administration tools, the change journal, named keys and
every guard around them, which rebuilds `.htaccess` partway through and is the destructive
one; `smoke-woo.sh` for the shop tools; `smoke-elementor.sh` for the Elementor tools;
`smoke-kirki.sh` for the Kirki tools; `smoke-yoast.sh` for the Yoast SEO tools;
`smoke-acf.sh` for the ACF tools; `smoke-gravityforms.sh` for the Gravity Forms tools;
`smoke-learndash.sh` for the LearnDash tools; `smoke-elementor-pro.sh` for the Elementor Pro
tools; `smoke-woo-subscriptions.sh` for the Subscriptions tools; `smoke-wsal.sh` for the
WP Activity Log tools; `smoke-redirection.sh` for the Redirection tools; `smoke-polylang.sh`
for the Polylang tools; `smoke-seo.sh` for the Rank Math and All in One SEO tools;
`smoke-forms.sh` for the form-submission tools; `smoke-wordfence.sh` for the Wordfence tools;
`smoke-stream.sh` for the Stream tools; `smoke-beaver.sh` for the Beaver Builder tools;
`smoke-beaver-write.sh` for the tool that builds a Beaver Builder page;
`smoke-download.sh` for the one-time download endpoint; `smoke-export.sh` for the site
files manifest, the approval-gated archive and its one-time endpoint; and `smoke-listing-filters.sh`
for the listing tools under another plugin's query filters. `smoke-seo.sh`,
`smoke-forms.sh`, `smoke-polylang.sh` and `smoke-redirection.sh` were written without a
running stack and have not been run. The plugin-specific suites need their plugin installed;
`smoke-download.sh`, `smoke-export.sh` and `smoke-listing-filters.sh` need only this plugin.

Wordfence makes every call on a stack slow: it checks in with its own servers when it loads,
and with no outbound route each request waits for that to time out — measured at 0.3s per
`wp` call without it and 10.6s with it. `smoke-wordfence.sh` therefore takes minutes rather
than seconds, and the other suites are usually run with Wordfence deactivated.

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

Versions come from commit messages. Write commits, and squash-merged PR titles, as
[Conventional Commits](https://www.conventionalcommits.org/).

| Commit | Effect on the next release |
|---|---|
| `fix:`, `perf:`, `revert:` | patch (1.0.0 to 1.0.1) |
| `feat:` | minor (1.0.0 to 1.1.0) |
| `feat!:`, or a `BREAKING CHANGE:` footer | major (1.0.0 to 2.0.0) |
| `docs:`, `ci:`, `chore:`, `refactor:`, `test:`, `build:`, `style:` | none by themselves |
| a `Release-As: 1.4.0` footer | forces that version, even on a `chore:` |

**What release-please reads.** This repository squash-merges with the PR description as the
commit body, so it reads the PR title *and* the description. A `BREAKING CHANGE:` or
`Release-As:` footer only counts if it is in the description (one in a branch commit is
discarded), and a line shaped like a commit message in the description can be read as a
separate change. The `PR title` check validates the title and warns about those footers. It
is advisory: it blocks a merge only if the ruleset lists it as a required status check, and a
direct push to `main` skips it.

**How a release happens.**

1. A workflow keeps one open pull request, "chore(main): release X.Y.Z", that bumps `Version:`
   and the `GMCP_VERSION` define in `guarded-mcp.php` and `Stable tag:` in `readme.txt`, and adds
   the entries to `CHANGELOG.md`. It collects changes until you merge it.
2. **Merging that pull request starts the release.** The workflow tags `vX.Y.Z` and creates the
   GitHub Release as a **draft**.
3. A second job checks that the tag points at the commit that was released and that the commit
   is on `main`, runs `check-release.sh version` against the tag, builds the zip with
   `.dev/build.sh`, checks its contents, attests it, attaches the zips and checksums, and only
   then publishes the draft. A release is never public, and never "Latest", without its files.

Nothing is sent to wordpress.org. The install links in this README resolve only once a release
exists.

**Things that are easy to get wrong.**

- **The version lives in three places and release-please edits them by marker.** The
  `Release-Please:` lines in the plugin header and the `readme.txt` header, and the
  `x-release-please-version` comment on the `GMCP_VERSION` line, are what it finds. They are
  written as `Key: value` lines on purpose: wordpress.org's readme parser ends the header at the
  first non-blank line with no colon, and a bare marker there would silently drop the headers
  after it. `.dev/check-release.sh version` fails if a marker is missing or unclosed, or if the
  three versions disagree, and CI runs it on every push and pull request.
- **Switch on "Allow GitHub Actions to create and approve pull requests"** (Settings, Actions,
  General), or add a `RELEASE_PLEASE_TOKEN` secret. Without one of them release-please cannot
  open the release PR. The secret (a fine-grained token or GitHub App token with contents, pull
  requests and issues write) also makes `Package` and `Plugin Check` run on the release PR:
  pull requests created with the default token do not start other workflows.
- **`readme.txt`'s Changelog and Upgrade Notice are not updated by release-please.** It
  writes `CHANGELOG.md` and the GitHub Release notes. A release whose `readme.txt` still ends at
  the previous version's entry ships with a stale changelog, so edit it on the release PR's branch.
- **The first release depends on `bootstrap-sha`** in `release-please-config.json`. It starts the
  history at the commit that was `main` when this was added, so only later `feat`/`fix`/`perf`
  commits count and nothing is proposed until one lands. To release the current state as is, merge
  a PR whose description ends with a `Release-As: 1.0.0` footer.
- **Pushing a tag by hand releases nothing, but a tag pushed early is not harmless.** release-please
  creates the release by tag name, and GitHub does not move a tag that already exists, so someone
  who pushes `vX.Y.Z` before the release PR is merged decides which commit that tag, and the
  draft, point at. The publish job builds only the commit release-please released, and refuses if
  the tag points anywhere else or the commit is not on `main`. That refusal is the defence; a tag
  ruleset (Settings, Rules) restricting who can create `v*` tags makes it unnecessary to rely on.
- **The control is who can push or merge to `main`.** Merging the release PR, or pushing a commit
  that does what one would, is what releases. Check that the ruleset requires a pull request and
  the `Package` and `PR title` checks, and keep merge rights narrow. The `release` environment can
  put required reviewers in front of the upload and the publishing, but not the release PR.
- **If the publish job fails, the draft and its tag already exist and nothing is public.** For a
  cause outside the repository (an approval, a network error), use "Re-run failed jobs"; a
  half-finished upload is replaced, because a draft has no downloads to protect. If the draft
  exists but the job never ran (release-please failed after creating it), run the `Release`
  workflow by hand from `main` with the tag as input; it refuses anything that is not a draft. If
  the commit itself cannot be built, abandon that version: delete the draft and its tag, merge the
  fix, and the next release PR ships it as the next version.

## Licence

GPLv2 or later. Parts of this plugin derive from prior GPL work.
