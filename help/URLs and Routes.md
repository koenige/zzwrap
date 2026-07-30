<!--
# zzwrap
# how URLs, webpages, and named routes work
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2026 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
# Variables
# audience = programmer
-->

# URLs, webpages, and named routes

Zugzwang uses two related mechanisms:

| Direction | Question | Mechanism |
|-----------|----------|-----------|
| **Incoming** | Which page handles this URL? | `webpages.identifier` → `wrap_match_page()` |
| **Outgoing** | Which URL belongs to this logical name? | `routes.cfg` + `webpages` → `routes.json` → `wrap_path()` |

Every public URL is a **webpage** row. **Named routes** do not create URLs — they scan webpages and cache stable path keys for templates and PHP.

## Part A — Webpage URLs (incoming)

When a browser requests a path, zzwrap resolves it to a row in the
`webpages` table.

1. The web server rewrites page traffic to the front controller (e.g.
   `main.php`), which runs `zzwrap()` (`zzwrap/zzwrap.php`).
2. `wrap_match_page()` (`zzwrap/match.inc.php`) compares the request path
   to **identifiers** in `webpages` (including `*` wildcards and configured
   URL placeholders).
3. The matching page supplies **content** (zzbrick blocks), **parameters**,
   and metadata.

There is no per-URL router file in code. Paths live in the database.

### What you edit

- **`identifier`** — path segment(s), e.g. `/search`, `/event/*`
- **`ending`** — `/`, `.html`, or `none`
- **`content`** — zzbrick blocks (`%%% request … %%%`, `%%% forms … %%%`, …)
- **`parameters`** — page settings (see webpages parameters help), including
  optional `route=<key>` for named routes (Part B)
- **`live`** — whether the page is served to visitors (preview bypasses this)

**To add or fix a page URL:** create or edit the webpage in the CMS — not
`routes.cfg` alone.

## Part B — Named routes (outgoing links)

For consistent linking in templates and PHP, use **named route keys**
(e.g. `search`, `login_entry`) instead of hard-coded paths.

Named routes are declared in config, **resolved from webpages**, and cached
as JSON.

### Pieces

| Piece | Role |
|-------|------|
| `routes.cfg` (per module, merged) | Route **keys** (`[search]`, …), optional `brick=…`, `match_parameters`, `default`, `expand`, fallbacks, access groups |
| `wrap_routes_write()` (`zzwrap/routes.inc.php`) | Scans `webpages` for matching bricks or `parameters` with `route=<key>`; writes `config_dir/routes.json` (or `routes-<sitekey>.json` if `multiple_websites`) |
| `wrap_routes_read()` | Reads that JSON; regenerates when missing or older than `routes_cache_seconds` |
| `wrap_path($area, …)` | Resolves a route key to a path: loads `routes.json`, optional `wrap_access($area)`, placeholder substitution, `base` / `host_base` |

### Binding a route to a page

A route key must be tied to exactly one suitable webpage (unless
`routes.cfg` supplies `default` or a fallback). Two ways to expose a page
to the generator:

1. **Brick in content** — match the `brick=` declared in `routes.cfg`
   (e.g. content contains `%%% request search %%%`).
2. **`route=` parameter** — add `route=<key>` to the webpage’s
   `parameters` field (documented in settings.cfg with `scope[] = webpages`).

Then wait for or trigger regeneration of `routes.json` (cache age or
missing file).

### Sub-routes and ambiguity

- **`expand`** in `routes.cfg` can produce keys like `area[subkey]` from
  page-local settings.
- Bricks ending with ` *` can map multiple subkeys under one base key;
  `area[subkey]` may fall back to `area[*]` when building paths.
- If several pages match and disambiguation fails, the route may be omitted
  or set to `null` in JSON — then `wrap_path()` warns or returns empty/NULL
  depending on options.

**To add or fix a named route:** adjust merged `routes.cfg`, ensure a
webpage exposes the brick or `route=` parameter, then check
`routes.json`.

## Cheatsheet

| Task | Where to look |
|------|----------------|
| New public URL | `webpages` table — identifier, content, live |
| Link in template/PHP | `wrap_path('route_key', …)` → `routes.json` |
| Declare route key | Module `configuration/routes.cfg` |
| Attach key to page | Matching brick in content **or** `&route=key` in parameters |
| Debug 404 on visit | `wrap_match_page()`, identifier, `live`, placeholders |
| Debug empty/wrong link | `routes.cfg`, webpage binding, `routes.json`, `wrap_path()` |

Implementation: `zzwrap/routes.inc.php` (generation + `wrap_path`),
`zzwrap/match.inc.php` (incoming match), `zzwrap/zzwrap.php` (bootstrap order).
