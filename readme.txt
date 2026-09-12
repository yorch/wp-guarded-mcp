=== Guarded MCP ===
Contributors: yorch
Tags: mcp, ai, claude, agent, chatgpt
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe MCP server for Claude and any AI agent. Full site administration with guardrails, no API keys, and nothing else bundled.

== Description ==

Guarded MCP turns your site into a [Model Context Protocol](https://modelcontextprotocol.io) server, so an AI agent such as Claude Code can administer it through conversation.

It is built on one assumption: the agent will occasionally get it wrong. So it hands an agent everything an administrator can do, and puts a guard on each of the operations you would not want done on a misread instruction.

= What makes it different =

Most plugins in this space are AI frameworks that also speak MCP. This one is only the MCP server. No chatbot, no provider API key, no front-end asset, nothing rendered to your visitors. Your agent talks to the model; this plugin is what the agent reaches into.

The guardrails exist because an agent administering your site also reads your comments, post bodies and plugin descriptions, written by anonymous people. So the guards are placed where a model cannot argue its way past them:

* Deleting a plugin, theme or menu takes two calls — the first changes nothing and returns a token bound to that target.
* Installs come from the wordpress.org repository by slug; an arbitrary ZIP URL is refused unless you open a filter.
* Post and widget HTML is filtered regardless of who is calling.
* The registration default role is checked by construction — anything above subscriber is refused.
* It refuses to break itself: no deactivating or deleting the plugin mid-call, no deleting the active theme.
* The plugin's own credentials are not readable through its own tools.
* The riskiest tools can preview what they would do before doing it.
* Edits can be put back — one call reverts what an agent changed.

= What an agent can do =

Content and site data: posts, pages, block content, taxonomies, comments, media, users, post meta, site options, post types and block patterns.

Site administration (off by default): plugins and themes; navigation menus; widgets; General/Reading/Discussion settings; permalinks; scheduled events; Site Health.

WooCommerce, Elementor, Kirki, Yoast SEO and ACF, each on a switch of its own that appears only when the plugin is installed.

Backups, if you have a compatible backup plugin. There is no restore tool, deliberately.

= Connecting =

Two ways in: **OAuth** for clients that support it (paste the endpoint URL, no shared secret), and **named keys** for clients that cannot (choose access level, expiry and allowed tools).

= Privacy =

No telemetry. It sends nothing about you or your site anywhere.

== Installation ==

1. Install and activate the plugin.
2. Open MCP Server in the admin menu.
3. On the Connect page, copy the snippet for your client. It is filled in with this site's real endpoint, and with your token for clients that need one.
4. Press "Run the setup checks" on that same page if anything does not connect. It walks the same steps a client does and tells you which one failed, rather than leaving you to guess.
5. If you want an agent to manage plugins, themes, menus, widgets or settings, switch on the site administration tools on the Tools page. They are off by default.

== Frequently Asked Questions ==

= Do I need an OpenAI or Anthropic API key? =

No. This plugin never calls an AI model. Your agent does that, using whatever account it already has. This plugin is only the endpoint your agent connects to.

= Is it safe to let an AI agent administer my site? =

That depends on what you switch on, which is why the administration tools are off by default and why deletions take two steps. The honest answer is that an agent will occasionally do the wrong thing, so the plugin is built to make the irreversible operations hard to reach by accident and to refuse the ones that would leave you locked out. Start with the content tools, watch how your agent behaves, and enable more when you are comfortable.

= My client returns 401 with a token I know is correct. =

Press "Run the setup checks" on the MCP Server screen. It calls the endpoint the way an agent would and tells you whether the endpoint is unreachable or the credentials are not arriving, which are different problems with different fixes. The same check appears in Tools, Site Health.

The usual cause is that your server is not passing the Authorization header through to PHP, which is common on Apache. The plugin reads the header from a fallback location for exactly this reason, but if that is unavailable, re-saving your permalink structure regenerates the .htaccess rule WordPress uses to forward it.

= Does it work with ChatGPT, Gemini or a local model? =

Yes. Nothing in the plugin is specific to one vendor. Any client that speaks the Model Context Protocol can connect.

= My server strips the Authorization header. Is there another way in? =

Not any more, and that is deliberate. Earlier versions answered on a URL containing the credential, which put the secret in the request path, where proxies, access logs and browser history all keep a copy of every request. The plugin now recovers the header from the two places Apache commonly hides it, which covers most sites that appeared to be stripping it. Press "Run the setup checks" to find out whether yours is one of them: the check makes a real request with a short-lived key and tells you whether it arrived.

= Can I limit what an agent is allowed to do? =

Yes, three ways. A named key carries one of three access levels. The administration and WooCommerce tools are separate switches, both off by default. And a named key can be limited to a specific list of tools and given an expiry date. Developers can go further with filters: `gmcp_tools` to change the catalog and `gmcp_allow` to override the auth decision.

= Can I undo something an agent did? =

If the agent modified something, usually. If it created or deleted something, no.

The plugin records what a setting, post or page said before an agent modified it, and `wp_undo_change` puts one back. It covers widgets and menus too, because widgets are stored in settings and menu items are posts.

It does not record post creations or deletions. It watches WordPress rather than the tools, and WordPress does not announce a post insert the same way it announces an edit, so a post the agent created is not in the journal and neither is one it deleted. Users, comments and terms are not watched at all. Deleting a post without forcing it sends it to the trash, where WordPress can restore it, so the commonest case is usually recoverable anyway. Post meta is watched, including adding and removing a field, because with a page builder the meta is where the work is; those previous values live in a table of their own with a two-week retention, so an undo offered long after the fact says the copy has expired rather than putting back something stale.

The audit log is the complete record: it lists every call the agent made, including the creations and deletions the journal cannot reverse. It also does not cover deleting a plugin's files, or anything that has already left WordPress, such as an email that has been sent.

= Can I see what a tool would do before it does it? =

Yes, for the six where the result is not obvious from the call: deleting or updating a post, a search and replace, and deleting a term, a media file or a comment. Pass `preview` and the tool describes what would happen and changes nothing. It is most worth doing before a regex replace, which otherwise reports success whether it matched everything or nothing at all.

= Does it work on multisite? =

It has not been tested on multisite. The code has network-aware branches, but until they have been exercised properly, treat multisite as unsupported.

== Screenshots ==

1. The Connect page: the endpoint to give your agent, ready-made snippets for Claude Desktop and Claude Code, and the connection check.
2. The consent screen an administrator sees when an OAuth client asks to connect.
3. The Access page: named keys, each with its own access level, tool list and expiry.
4. The Audit Log page: every call an agent made, refusals included, with the reason each was turned down. Filter by tool, account or recency, and search.
5. One entry in full: what changed, field by field, with the values before and after, the arguments as they were recorded, and the entry's place in the hash chain.

== Changelog ==

= 1.0.0 =
* First release.
* Model Context Protocol server over Streamable HTTP, with OAuth 2.1 including PKCE and dynamic client registration, or named keys with three access levels, their own expiry and their own tool list.
* Content tools: posts, block content, taxonomies, comments, media, users, post meta, options, post types and block patterns.
* Site administration tools, off by default: plugins, themes, menus, widgets, settings, permalinks and Site Health.
* Two-step confirmation on destructive operations, wordpress.org-only installs, filtered post and widget markup, and refusal of any operation that would make the site or the endpoint unreachable.
* Audit log of every tool call, refusals included, with redacted arguments, a tamper-evident hash chain, and automatic pruning.
* Backup support: start one before a risky change, list what exists and see when one last completed, with UpdraftPlus and Backuply driven directly and a filter for anything else. Listings never include archive filenames, because on both plugins the filename or its folder is what keeps the archive from being downloaded.
* Connection test on the settings screen and in Site Health, which distinguishes an unreachable endpoint from credentials that never arrived.
* WooCommerce tools on their own switch: products, stock, orders, order notes, customers and sales figures. No refunds.
* Named keys with their own access level, expiry date and tool list, stored hashed.
* A change journal, so a setting or post an agent modified can be put back.
* Preview mode on the six riskiest tools, which describes what would happen and changes nothing.
* MCP prompts for common upkeep jobs, and MCP resources for attaching site content to a conversation.
* A one-call site briefing, so an agent orients in one request rather than six.
* Tool failures arrive as results the model can read, with isError set, rather than as JSON-RPC errors a client may discard along with the rest of the response.
* Scheduled events can be listed, run and removed, so a cron event Site Health keeps flagging can be diagnosed and cleared. Only events the site already scheduled can be run, and removing one takes a confirmation step.
* Copy post meta between posts, duplicate a post, and write or read an oversized value across several calls, so a large design never has to pass through a tool argument. A duplicate is a draft unless you ask otherwise.
* Post meta keeps its backslashes. WordPress unslashes every meta value it stores, which used to turn a regex, a Windows path or a JSON payload into something that no longer meant what was sent, while the tool still reported success. Every meta writer now goes through one function that slashes the value and decodes a JSON string for an array, so an ordinary update no longer needs the chunked writer to survive.
* Delete an option, not only set one, so a stale cache that only clears by removing the row can be cleared. Nine options are refused, each with what breaks if it goes. The reply carries the removed value, because undo cannot put it back.
* Flush the object cache, expired transients or one post, and say plainly which CDN or reverse proxy it could not reach and you still have to purge yourself.
* Undo covers post meta, which is what a page-builder site is made of, and the check that keeps credentials out of the record now walks far enough to reach the bottom of a page design. It used to stop six levels down and refuse everything below that, so a design was declined for being deep rather than for holding anything.
* Create several posts in one call, up to twenty, stopping at the first failure and reporting what was created, what failed and what was never tried. Creations are still not undoable, and the reply says so.
* Ask for a database-only or files-only backup where the backup plugin can express it, and get a reason where it cannot.
* Read the Elementor kit's global colours, fonts and settings without writing any of them.
* Menu items can be edited rather than deleted and rebuilt, a new menu can be given the slug that other things reference, and a menu health report names items pointing at missing pages, locations with no menu, and Elementor widgets referencing a menu slug that does not exist.
* One page's cached copy can be purged instead of the whole site's cache, and anything that is not this site is refused.
* What still references an Elementor template can be asked for before the template is deleted, covering both the shortcode and a template embedded in another page's design.
* Meta keys are matched exactly rather than lowercased, and a write that lands under a different spelling than you asked for says so.
* A folded page says how many refusals sit on other pages, a row opens in place to show its reason and what it changed, and a chain check that covered only part of the log says so and offers to walk the rest.
* The audit log screen folds repeated identical calls into one counted row, says on the row why a call was refused, names the way a caller got in beside the client, searches the small columns by default with a checkbox for the rest, and exports the filtered view as CSV or JSON.
* Elementor tools on their own switch: theme-builder conditions written to both the template and Elementor's cached registry, regenerate CSS, and apply a library template to a page without the design passing through a tool argument.
* Kirki tools on their own switch: customizer field discovery, value get/set that resolves the field's storage model (theme_mod, grouped option or standalone option), and Google Fonts cache clearing. Writing a Kirki value through the generic option tools is a silent success because the value lands in the wrong row; these resolve the storage from the field's registration.
* Generic theme mod tools in the core group: wp_get_theme_mod, wp_set_theme_mod, wp_list_theme_mods and wp_remove_theme_mod. The critical one is wp_set_theme_mod, which uses set_theme_mod to merge one key rather than replacing the whole theme_mods array, so nav_menu_locations and the other mods survive.
* Yoast SEO tools on their own switch: per-post SEO metadata read/write through the Surfaces API, and indexable rebuild. Writing _yoast_wpseo_* post meta through the generic post-meta tool is a silent success because Yoast reads from the wp_yoast_indexable table on the front end, not from post meta; these write through Yoast's own API and rebuild the indexable. Computed analysis fields are refused.
* Advanced Custom Fields tools on their own switch: custom field discovery, value get/set through update_field and get_field with field key references. Writing a custom field through update_post_meta is a silent success because ACF needs a hidden field key reference to return the right type; these write through update_field, which writes both the value and the reference. Unregistered fields are refused, and the post_id is restricted to known forms.

== Upgrade Notice ==

= 1.0.0 =
First release.
