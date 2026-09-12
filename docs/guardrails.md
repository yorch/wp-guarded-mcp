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
`theme_mods_*` rows, so `wp_undo_change` can put a write back.

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

`wp_list_changes` and `wp_undo_change` put back a setting or post an agent
*modified*. The change capture layer listens to WordPress rather than to the
tools, so it also covers widgets, which live in options, and menu items, which
are posts. Only writes made during a tool call are recorded, never a person's own
edits.

That layer is shared with the audit log, which wants the same facts from the
other side: the journal keeps the previous value so it can put it back, the
audit log keeps a description of the difference so a reader can see what moved.
Two copies of the same diff would drift, and the day somebody added a field to
one list the other would quietly stop mentioning it.

Undo records modifications and not creations or deletions of posts, and that is
a real limit rather than a nicety. The capture layer reports both and the
journal declines them: putting back a creation means deleting something, and
putting back a deletion means recreating it, and neither is the same write in
reverse. Users, comments, terms, plugins, themes and media are reported too and
journalled none of them, for the same reason. The audit log has all of it,
which is the difference between the record and undo. Deleting a post without
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
