# Audit log

The audit log records every tool call, refusals included, with the arguments it
was given, what it changed, what made it, what it was aimed at, how long it took
and why it was turned down. Without it an agent works with no visible record at
all: you can see that a plugin is gone, but not that your agent removed it,
when, or that it tried three times first. Refusals are the interesting entries,
which is why they are kept.

It lives in its own table, `{prefix}gmcp_audit`, indexed by time, tool and
actor. That replaced an option row, which was a read-modify-write: two calls
landing together could lose an entry, and it held a hundred rows at most.

It is read through a list, one row per call, and a page for any single entry.
The list is a WordPress list table, so it pages, sorts by column, remembers how
many rows you want under Screen Options, and behaves like the rest of wp-admin.
It filters by tool, by account, by how recently, and by whether the call was
refused, that last one as a link rather than a menu because refusals are what
the table is kept for.

Each row is one line. That is a constraint rather than a simplification: the
previous screen printed every changed field inline, so a single call that
touched eight objects pushed the next call off the screen, and a list you
scroll past to reach the next entry has stopped being a list. The row says what
the call changed in a phrase, and the entry page has room for the rest.

The entry page holds everything the log recorded, which the list deliberately
does not: the full refusal message rather than the first 160 characters, every
changed field with its before and after, the arguments as they were stored, and
the entry's own hash and the one it follows. It also says whether that entry
still matches its own hash, and says in the same breath that this is a
statement about one entry and not about the chain.

There is no checkbox column and no bulk actions, which is a decision. Every
bulk action a log could offer is a deletion, and rows here hash the row before
them: removing one from the middle is exactly what the chain exists to make
visible, so putting a convenient button on it would be building the attack into
the product.

## Five decisions

### Arguments are recorded, redacted

An entry that does not say what was asked for is half an entry, but
`wp_create_user` takes a password and `wp_update_option` takes whatever a
settings array holds. Everything goes through `GMCP_Core::redact()`, which
keeps the shape and blanks the leaves, and `user_pass` is dropped whatever the
detector thinks. A field reading `[redacted]` is itself information: it says a
secret was passed. The option name in `wp_update_option` is deliberately kept,
because the credential patterns are written for field names inside a value,
where `key` signals a secret, and at the top level of a call it is the name of
the thing being changed.

### What was called is not what changed

An entry saying `wp_update_post` ran on post 12 with certain arguments does not
say the post went from private to publish, and that is usually the question.
Each entry therefore carries a summary of what actually moved: which object, of
what kind, and for each field the value before and the value after. Widgets,
menus and menu items are named as what they are rather than as the option or
post they are stored in.

It is a summary rather than a copy, for two reasons. Field-level copies of post
bodies would eat the retention bounds, so a value longer than a line is recorded
as its size: "the title changed, and the body went from 1.4 KB to 1.6 KB" is the
useful sentence, and a field that did not change is simply absent. And some of
those values are passwords. Anything credential-shaped is recorded as
`[redacted]` on both sides, judged by the same `gmcp_credential_field_patterns`
the change journal uses, so the log says a password was changed without saying
to what.

### Each row hashes the one before it

Nothing here stops somebody with database access editing a row, and pretending
otherwise would be worse than not trying. What the chain does is make it visible:
the screen recomputes it and names the first row that no longer matches, and
says whether the row was edited or one before it removed. That is the
difference between a history and an audit. Rows carried over from the
option-based version have no hash and are reported as uncovered rather than as
tampering.

The verdict names an entry, and the screen links to it, so "the chain breaks at
entry 412" leads to entry 412 rather than handing you a number and no way to use
it.

### It says how much it checked

Recomputing the whole table means reading every recorded argument back out of
the database, which on a full one is tens of megabytes, so the screen checks the
most recent thousand rows and offers a button for the rest. The count it
reports is the count it read, and a partial check does not merely say so, it
looks different: a complete pass is a sentence, while a partial one is set apart
on the page under "only part of the chain was checked" and gives all three
numbers, so "1,000 of 8,300 entries were checked, the most recent first, and
those are intact. The other 7,300 were not looked at." Reassurance and partial
reassurance reading alike at a glance is the whole failure this guards against,
since glancing is what a person does with it. The complete pass is scoped the
same way for the same reason: it used to say "intact across all N entries" using
the count of rows it verified, which excludes any carried over from before the
log was chained, so on an upgraded site "all" named a smaller number than the
table held. It now says how many were signed and how many were not, and on a
site where nothing is signed it says there is no chain to check rather than
reporting one intact across zero entries.

An earlier version read the oldest rows instead and said only "intact across
1,000 entries", which was true, read as a verdict on the whole log, and never
examined the period anyone would most want to check.

### Pruning is bounded three ways

Age alone lets a runaway agent fill a disk in a day; a row cap alone lets one
enormous entry do it; a byte cap alone throws away last week because of last
year. So retention in days (90 by default, configurable), a hard cap of 50,000
entries and one of 50 MB of recorded arguments and changes, whichever is hit
first, pruned by a daily WP-Cron event. There are Prune now and Clear
everything buttons on the screen.

## The chain's construction

The changes column arrived after rows had already been written, which the chain
has to survive, and the first attempt at that was wrong in an instructive way.
Hashing joined the columns with a separator, which is safe while the list of
columns is fixed: moving content from one column into the next leaves a
separator behind and the recomputation differs. It stops being safe as soon as
the list can vary in length, which a nullable column makes it do. A row's
recorded changes could be appended to the end of its neighbour and the column
blanked, and the shorter recomputation would rebuild the longer string exactly,
so the chain would call the row intact while the changes it covered had been
erased from it. The hash therefore commits to the shape of a row as well as to
its contents: each field contributes its name and the byte length of its value,
prefixed by the field count.

That cannot be applied backwards, because re-signing old rows under a new
construction is the same as not signing them. The upgrade records the id of the
last row written under the old one, and every row is checked the way it was
written: the old join at or below that mark, the canonical encoding above it.
Old rows keep verifying, new rows are unambiguous, and nothing was rewritten to
make either true.

## Two things the chain cannot do

Stated here rather than left to be assumed. It cannot notice that the log has
been shortened from the front, because the first row the walk sees is the only
thing that says what preceded it, and a chain with no external anchor has
nothing to check that claim against. A truncated table verifies clean, and
reports itself complete, because the total is counted from what survives. And
it cannot tell you who edited a row, only that somebody did.

## One page can be purged rather than all of them

`wp_purge_url` takes URLs on this site and asks each page cache it can name to
drop just those, which matters because dropping everything on a busy site is a
thundering herd. It refuses anything that is not this site, and the checks are
the ones that catch a careless implementation: a host is compared for equality,
so `example.com.evil.test` fails, and a protocol-relative `//evil.test/x` is
read as a foreign host rather than as a path. A path containing `..` is refused
outright, because every per-URL purge turns the URL into a filesystem path and
globs it. One bad entry refuses the whole call, since purging nineteen and
mentioning the twentieth in passing is the kind of thing a reader skims. The
object cache is deliberately untouched: its entries are keyed by post and
option, never by URL, so the only lever is the site-wide flush this tool exists
to avoid.

## The log can leave the screen

As CSV or JSON, and both export the rows the current filters select rather than
the whole table, so what you get is what you were looking at. A match too large
to send is cut at its oldest end and the file name says so, because a file
outlives the screen that would otherwise have carried that caveat. An export
cannot give up a secret: the redaction happened on the way in, so there is no
unredacted copy to export. Cells that open with `=`, `+`, `-`, `@`, a tab or a
carriage return are written with a leading apostrophe, which is visible in the
file and deliberate. A spreadsheet treats such a cell as a formula and runs it,
and this log carries post titles, refusal messages and comment text that an
anonymous person wrote, which is the same reason the rest of the plugin is
careful.

## Meta keys are matched exactly

They used to be lowercased on the way in, which is fine until it is not: a key
spelled `myPlugin_Data` addressed a different row, or none, and a write created
the wrong one while reporting success. Every meta tool now takes the key as
given, and the empty string and `"0"` are refused rather than passed on, because
WordPress tests a meta key for truth before using it and treats both as no key
at all, so a read of `"0"` answers with every key on the post.

One half of this cannot be fixed from here and is reported instead. The database
compares `meta_key` case-insensitively, so `myPlugin_Data` and `myplugin_data`
are one row to every write WordPress performs; reads come from a cache keyed by
the spelling actually stored, and those compare exactly. Writing a key that
differs only in case from one already on the post therefore updates that row,
leaves its original spelling, and the value is then invisible to a read of the
key you just wrote, with nothing erroring anywhere. The writers say which
spelling a value landed under whenever it is not the one you asked for.

## Who is not overclaimed

Two columns, because the honest answer needs both. `called_by` is the OAuth
application, the named key's label, or the authentication method a shared token
used: it is the closest this site has to who was driving. The screen names the
way in alongside it rather than instead of it, because the three are not
equivalent: OAuth is a consent that stops working when the account stops being
an administrator, a bearer token is a shared secret. `acted_as` is the WordPress
account the call ran as, and a key carries its own owner rather than borrowing
the lowest-numbered administrator, so that name is the same whoever sent the
request. The reply says so in as many words rather than leaving a reader to
infer it.

## The screen is built for the way agents actually behave

Agent traffic is repetitive: a reader who asked for the log after twenty-eight
polling calls used to get a page of twenty-eight identical rows and found the
refusals three pages later. Consecutive identical calls now fold into one row
carrying a count, expandable in place, with a link that unfolds the whole page.
The item total always reports the true number of entries, because a log that
rounds down what it holds is not a log. Anything that recorded a change never
folds, however alike two such calls look: five writes of the same option are
five different before-and-after pairs, and merging them would hide exactly what
the log exists to show.

## The page says what is behind it

Folding is within a page, which is what keeps the item total honest, so a long
run still occupies its own page and the interesting rows sit further back. A
folded page therefore also says how many refusals match the current filters but
fall on other pages, linked. It stays quiet when nothing folded, when the
reader has unfolded the page, when an outcome filter is already in force, and
when every matching refusal is on the page already: a line printed on every
page is how a reader learns to skip the line that will one day matter.

## A row opens in place

Comparing three refusals used to be three page loads and three journeys back.
The row already holds its reason and its changes, so an expander shows both
without another query. Arguments and the chain hashes stay on the entry page,
because arguments are capped at 64,000 bytes each and putting twenty-five of
those in one page trades one problem for a worse one. A row that changed
nothing grows no expander, judged by the same test the Changed column uses, so
the two can never disagree. On a folded row the expander shows the reason and
never the changes: the reason is part of the fold key so it is true of every
entry in the run, while anything that changed the site never folds in the first
place.

## A refusal says why on the row

Refusals are the interesting entries, and they used to all render as the single
word "Refused" with the reason a page load away. The reason now sits under the
row, cut to a readable length with the whole of it on the entry page. It is a
full-width line rather than another column because the Result column is about a
hundred pixels wide and a two-hundred-character refusal wrapped to eight lines
in it, which made the rows that mattered the hardest ones to read.

## Searching is narrow by default

The log holds up to 50 MB of recorded arguments, and a substring search across
all of it is a full scan of the table. The search box reads the target and the
result, both small, and a checkbox puts the arguments and the changes back when
you need to ask what touched post 12. The `outcome` column is indexed, so the
Refusals view reads the page it returns rather than scanning to find it.

## A partial check looks partial

Verifying the chain walks the newest thousand entries, and the table may hold
fifty thousand, so a reassuring sentence could cover two percent of the log and
look exactly like one that covered all of it. A complete pass is still a green
sentence. A partial one is its own box, says how many entries were checked and
how many were not, and offers a button that walks the whole chain; that walk
takes about six tenths of a second on a full table of fifty thousand rows, at
flat memory, because it verifies in chunks.

The result of a full walk is remembered with the date it ran, since it is the
only thing that can say anything about the rows the window never reaches. It is
never phrased as current state. A remembered pass is dropped the moment the
quick walk disagrees with it, and that rule is load-bearing rather than tidy:
the record is invalidated by the row count and the highest id, neither of
which moves when a row is edited in place, which is exactly the tampering the
chain exists to catch. Without it the screen printed a break and, directly
underneath, that all fifty thousand entries were intact and nothing had changed
since. A remembered break is kept either way, because it covers rows the window
cannot reach and the window finding nothing does not answer it.

## The agent can read the log

An agent can read the log through `wp_get_audit_log` at `admin` level, filtered
by tool, outcome, date or free text, and the reply carries the tamper verdict
so a caller is told immediately if what it is reading has been altered. Nothing
exposed through MCP can prune or clear it: an agent that can edit its own audit
trail is not being audited.
