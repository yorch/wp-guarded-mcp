# Agent feedback, triaged

Twelve items reported by an agent after a session building an Elementor site through this
plugin. Every claim below was tested against a running site rather than read off the
source: stack `wpfb` on port 8094, Elementor 3.x, the container proved to be serving this
worktree by a unique marker before anything else was believed.

Where a claim was wrong, or right for the wrong reason, that is recorded. Two of the
reporter's own diagnoses were mistaken and one of their requests is already shipped.

Verdict key: **confirmed** reproduced here; **partly** true of some of what it describes;
**wrong** did not reproduce.

---

## What has been done since

Five of the twelve are fixed on this branch, each with tests proven to fail without the
fix and both readmes brought along. A sixth defect was found in the test harness while
clearing the suites and fixed too.

| Item | State |
|---|---|
| 1, result shape | fixed, `1f4c1a7` |
| 2, credential guard | fixed, `219b86d`, by blanking the leaves rather than either option proposed below |
| 3, meta corruption | fixed, `ced2dff` |
| 11, field selection | fixed, `e9ccfdf`, by declaring `_fields` rather than building anything |
| 12, server-side half | fixed, `a076de1`; the client notification is still open |
| the WooCommerce mutation probe | fixed, `97d7e9b`; it silently never installed, so two assertions reported a bug that was not there |

Everything else below is untouched and still describes what is true today.

---

## 1. Single-item REST tools return a schema-invalid result — confirmed, and wider

Reported as `create_pages` / `create_posts`. It is eight tools, and the cause is a name
collision rather than a missing wrapper.

`GMCP_Server::format_tool_result()` (`includes/server.php:494`) decides whether a handler
already produced an MCP envelope by asking `isset( $result['content'] )`. A WordPress post
object also has a `content` field, holding `{raw, rendered, protected, block_version}`. So
a created page is mistaken for a finished envelope and passed through untouched, and
`result.content` reaches the client as an object where the protocol requires an array of
blocks.

Measured on a live call to `create_pages`:

| | |
|---|---|
| `type(result.content)` | `dict` |
| value | `{"raw":"","rendered":"","protected":false,"block_version":0}` |
| post actually created | yes, id 4, title verified in the database |

Affected, all confirmed by probe: `get_posts`, `create_posts`, `update_posts`,
`delete_posts`, `get_pages`, `create_pages`, `update_pages`, `delete_pages`.

Not affected, also confirmed: every `list_*` tool, because a collection is a numerically
indexed array with no top-level `content` key. **And every media tool** — `get_media`,
`create_media`, `update_media`, `delete_media` all return a valid shape, because
attachments have no `content` field in their REST response. The reporter's suspicion that
`create_media` shares the defect is wrong.

The hand-written tools are all safe. `tools-admin.php`, `tools-core.php`, `tools-woo.php`
and `tools-elementor.php` build their envelope through `json()` → `text()`, which appends
a proper block, so they never reach the ambiguous branch. Only the dynamic REST passthrough
returns bare data for `format_tool_result()` to guess about.

Two candidate fixes. Wrapping explicitly in `GMCP_Tools_Rest::handle_call()` closes the
instance. Tightening `format_tool_result()` to require that `content` be a *list of blocks*
before believing it closes the class, and is the one to prefer: any future handler that
returns a domain object with a `content` field is otherwise the same bug again.

The reporter's instinct that this smells like a missing response-schema contract test is
right. Every tool declares an `outputSchema`; nothing checks a reply against it. A single
loop asserting `result.content` is a list of `{type,text}` for every tool would have caught
this and will catch the next one.

## 2. Credential heuristic false-positives — confirmed, cause identified exactly

The trigger is a literal `key` field inside Elementor's SVG icon data. Forcing Elementor to
write `_elementor_assets_data` and dumping it:

```
[svg][font-icon][eicon-star][content] => [ width => 1000, height => 1000,
                                           path => M450 75L338…, key => eicon-star ]
```

`GMCP_Core::credential_field_patterns()` matches **substrings**, and `key` is one of the
patterns, so `holds_credential()` returns true for the whole option. Controls confirm the
probe works: a value with `api_key` returns true, a value of `['colour' => 'blue']` returns
false.

The substring rule is broad well beyond this one case. Measured against
`field_looks_secret()`:

| classified secret | correctly | incorrectly |
|---|---|---|
| | `key`, `api_key`, `apikey`, `secret_key`, `password`, `token`, `nonce`, `signature`, `private`, `license` | `monkey`, `keywords`, `hotkey`, `author`, `authors`, `passed`, `bypass`, `compass`, `licensed`, `privately` |

`author` is the consequential one: any option holding an `author` key silently loses undo.

End-to-end, through the API, with an option shaped like Elementor's:

```json
{ "tool": "wp_update_option", "what": "Option \"probe_assets\" changed",
  "reversible": false,
  "not_reversible_because": "The previous value looked like it held a credential, so it was never stored." }
```

That is the reporter's message, reproduced from first principles.

**I would not take either fix they propose.** A known-safe allowlist is a blocklist
inverted and will always lag the next plugin. Token-boundary matching is tempting but the
hard case defeats it: dropping bare `key` as a signal is a genuine loosening, because
`['key' => 'sk_live_…']` is a real way plugins store secrets, and this guard failing open
is the one direction that cannot be walked back.

The better third option is the one already half-written. `GMCP_Core::redact()` keeps a
value's shape and blanks only the leaves whose field names look secret — exactly the
"redacted-but-reversible snapshot" the reporter gestured at, minus the guesswork. Store
that instead of dropping the value, record which paths were blanked, and have undo restore
every unredacted leaf while leaving the redacted ones at their current value. The security
property is unchanged, no credential lands in a second row, and undo comes back for the
98% of the blob that is an SVG path.

The design point to settle before writing any of it: undo must never write `[redacted]`
into a live option. That is a worse outcome than no undo at all, and it is the failure this
change would introduce if done carelessly.

Whatever is chosen, this is a guard whose reach changes, so `README.md` and `readme.txt`
both need the sweep the contributor notes require.

## 3. `wp_update_post_meta` corrupts backslashes — confirmed, and worse than reported

Two separate defects, one of which the report understates.

The schema half is as described: `value` is typed `['string','number','boolean']`, so the
single-field form cannot take an array. The `meta` object form does already JSON-decode a
string, so "takes only scalar values" is true of one form and not the other.

The corruption half is the serious one. `update_post_meta()` unslashes internally.
`wp_write_post_meta_chunk` compensates with `wp_slash()` and says why in a comment.
`wp_update_post_meta` does neither. Sending this value:

```
{"re":"\\d+","win":"C:\\path"}
```

| | |
|---|---|
| tool replied | `Meta updated for post #9` |
| actually stored | `{"re":"\d+","win":"C:\path"}` |
| parses as JSON | no, `Syntax error` |
| same payload through the chunk tool | stored as a real array, `\d+` and `C:\path` intact |

A success message over a silently mangled write, which is the failure mode the contributor
notes open with. It affects **any** value carrying a backslash, not only JSON: a regex, a
Windows path, a LaTeX fragment.

Cheapest high-value item on the list. The fix is the decode-then-`wp_slash()` pair already
written thirty lines away in the chunk handler, plus widening the `value` type. A test must
assert the stored bytes, not the reply.

## 4. Backup scope and destination — partly, and one half must be refused

Three requests bundled together, with three different answers.

**Listing individual backups already exists.** `wp_list_backups` shipped in b51bb85 and
returns each backup newest-first with size and contents. The reporter very likely did not
see it, which makes this a symptom of their own item 12.

**Download URLs should be refused, and the reason given.** They are excluded deliberately:
both backup plugins guard their directory with an `.htaccess` that Apache honours and nginx
ignores entirely, so on an nginx site the unguessable archive name is the last thing
standing between a caller and a database dump holding every password hash. Handing out the
name removes it. This sits alongside "there is no restore tool" as a deliberate omission,
not a gap to be helpfully filled.

**Scope and destination is a fair gap.** `wp_start_backup` takes no arguments and starts a
full backup only. A `type` of full/db/files and a storage target is reasonable, but it is
adapter-dependent work: each backup plugin expresses scope differently, and "cannot tell"
has to stay a first-class answer the way it already is elsewhere in this surface.

## 5. Per-URL cache purging — confirmed, and it fits the existing design

`wp_flush_cache` already states plainly that it purges no CDN, no reverse proxy and nothing
already handed to a visitor. The `gmcp_cache_flushed` and `gmcp_post_changed` actions
already exist, so the "documented purge hook" half is partly built.

The genuinely new part is URL scope. It fits the stance `purge_page_caches()` already
takes — purge what we can name, hand the rest to a hook, report exactly what ran so the
caller can tell what is still stale. Moderate value, low risk, small surface.

## 6. Transactions and meta-aware undo — half wrong, half the biggest item here

**The transaction premise did not reproduce.** Creating a page produces *zero* journal
rows, not nine. Measured both ways: `wp_create_post` with `post_type: page`, delta 0;
`create_pages` through REST, delta 0. Creations are deliberately not journalled —
`observe_post()` says so: "Creations and deletions go past: this restores fields, and
neither of those is a field to restore."

Their nine rows were almost certainly **option** writes made incidentally while the call
was in flight, Elementor writing `_elementor_assets_data` and friends. The journal listens
to WordPress itself during a tool call, so it records them and attributes them to whatever
tool was running. That is also precisely why item 2 bit them, and the two reports are one
story.

So "group the nine rows into one undoable unit" partly dissolves. What survives is worth
doing and is cheaper than what was asked: group a call's incidental side-effect rows under
the call that caused them, so the log reads as one action with consequences rather than
nine peers.

**The meta half is real and is the largest item in the list.** Post meta genuinely is not
journalled — three tool descriptions say so explicitly — and Elementor work is almost
entirely meta, so undo misses the changes that matter most on exactly the sites this
plugin is being used to build. It is also the riskiest to build: `_elementor_data` runs
past 100KB routinely, so before-snapshots have a storage cost and a retention policy that
does not exist yet.

## 7. Batch create — confirmed gap, sequence it after 6

No batch or bulk tooling of any kind exists across all 63 tools. The ergonomic complaint is
fair: seven near-identical page calls and eight near-identical menu-item calls.

Worth building carefully rather than quickly. A batch write multiplies blast radius in a
plugin whose whole thesis is that each write is guarded and legible, and partial failure
needs an answer that is neither "rolled back" (nothing here is transactional) nor silence.
The journal grouping from item 6 is close to a prerequisite: batch writes without it turn
one mistake into N unrelated-looking journal rows.

## 8. Menu tooling and a nav-menu audit — strongest new feature in the list

Both halves check out.

`wp_update_menu_item` is genuinely absent. The menu surface is create and delete only:
`wp_create_menu`, `wp_delete_menu`, `wp_add_menu_item`, `wp_delete_menu_item`,
`wp_assign_menu_location`, `wp_list_menus`, `wp_get_menu_items`. Retitle, re-parent and
reorder all require deleting and rebuilding.

`wp_create_menu` takes `name` and optional `locations`, with no control over the slug —
and WordPress derives that slug from the name. The slug is exactly what an Elementor
nav-menu widget stores, which is what makes the audit half valuable rather than merely
nice: nothing in the codebase inspects widget settings for menu references, so a widget
pointing at a slug that does not exist renders empty with no error anywhere. That failure
is already documented as a known trap in this environment, and it cost the reporter real
time. An audit is read-only, cheap, and turns a silent failure into a sentence.

## 9. Referential integrity for shortcode mode — confirmed gap

`elementor_apply_template` in its default shortcode mode writes
`[elementor-template id="N"]` into page content. Nothing anywhere reads that string back:
the only occurrences in the codebase are the write itself and the description. So there is
no reference query and no warning before a referenced template is deleted or unpublished.

A "what references template N" query and a refusal-with-reason before deleting a
referenced template fit the plugin's established character, which is to refuse and explain
rather than proceed and log. Good value, bounded scope.

## 10. Kit and global operations — real, largest, defer

Confirmed absent; the Elementor surface is five tools and none touches the kit. This is the
broadest ask and the most coupled to Elementor's own version-to-version internals, which
makes it the one most likely to acquire a maintenance burden disproportionate to its use.
Worth doing eventually, worth doing last.

## 11. Field selection on the heavy listers — already works, just undiscoverable

The cheapest win in the entire report, because the feature exists.

`rest_do_request()` honours WordPress's global `_fields` parameter today. Passing it
through the tool works right now:

| call | bytes | approx tokens |
|---|---|---|
| `list_pages` (3 empty pages) | 4556 | 1139 |
| `list_pages` with `_fields=id,title,status,link` | 186 | 46 |
| `wp_get_posts` (the lightweight equivalent) | 501 | 125 |

A 24x reduction, on pages with no content. On real pages it is far larger: `_links` alone
is 730 bytes per row of pure protocol overhead and `content` was 1382.

It is invisible because `build_schema_from_args()` generates the input schema from the
endpoint's own `args`, and `_fields` is a global REST parameter that does not appear there.
No agent would ever guess it. The fix is to add `_fields` and `context` to the generated
schema with a description saying what they are for. Roughly five lines, no behaviour
change, and it makes the reporter's complaint disappear without building anything.

## 12. `tools/list_changed` on upgrade — confirmed, plus a server-side bug they missed

The notification is absent, and the server does **not** advertise `tools.listChanged` — the
`initialize` reply returns `{"tools":{},"prompts":{},"resources":{}}`. So nothing is
breaking a promise today, but adding the notification means advertising the capability
too, or compliant clients are entitled to ignore it.

**The adjacent bug matters more.** The dynamic REST tool list is cached in the transient
`gmcp_tools_cache_v4` for a full day, and *nothing ever deletes it* — not activation, not
upgrade, not a settings change. The only invalidation is a human bumping the `v4` suffix in
source. So after an upgrade the **server itself** can serve a stale REST tool list for
twenty-four hours, entirely independently of any client cache. The transient also gates
dispatch: `handle_call()` refuses a tool absent from it, so a newly added REST tool is not
merely unlisted, it is uncallable.

That is very likely a real contributor to the detour the reporter describes, and unlike the
client-cache half it is fully within this plugin's power to fix. Bust the transient when
`GMCP_VERSION` changes.

---

## Suggested order

Grouped by what the work actually is, not by the reporter's numbering.

**Bugs, small, high confidence.** 3 (meta corruption, a few lines, silent data loss today),
11 (`_fields`, five lines, 24x payload cut), 1 (result shape, plus the contract test that
should have caught it), 12's server-cache half (bust on version change).

**Needs a security decision before code.** 2. The redact-and-record approach is better than
either option offered, but "what does undo do with a redacted leaf" has to be answered
first, and the answer has to be written down in both readmes.

**Good features, bounded.** 8 (menu update, menu slug, nav-menu audit), 9 (template
references), 5 (`wp_purge_url`), 12's notification half.

**Larger, needs design.** 6 (meta journalling and side-effect grouping), 7 (batch, after 6),
4 (backup scope; the listing half is done and the download-URL half should be declined),
10 (kit operations).

## Test environment

Stack `wpfb` on port 8094, brought up per the parallel-work rules with
`COMPOSE_PROJECT_NAME` and `GMCP_PORT` set on every command. Container confirmed serving
this worktree by a unique marker before any result was believed. Dynamic REST tools are off
by default and were enabled with `mcp_tools_rest` for items 1 and 11. Elementor installed
for item 2. Every absence check above was run alongside a control proving the probe could
find a present example.
