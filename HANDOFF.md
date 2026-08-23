# Handoff — `huseyinfiliz/language-detection` (Flarum 1.x)

> **Status: Phase 1 (investigation) and Phase 2 (skeleton & initial setup) complete. Phase 3 is next.**
> This file exists so a fresh session can resume without re-doing discovery.
> It is a working document — remove it (or gitignore it) before release.
>
> Nothing is committed yet beyond `5bed04d Initial release`. `.claude/`, `HANDOFF.md`, `CHANGELOG.md`,
> `locale/tr.yml`, `migrations/`, and `resources/` are all still untracked.

---

## 1. What this extension is

Extension ID `huseyinfiliz-language-detection`, package `huseyinfiliz/language-detection`, **Flarum 1.x only**.

Exactly two responsibilities:

1. Automatically determine a visitor's Flarum locale from browser language and/or IP country.
2. Show admins which languages are being requested but are **not installed**.

Pipeline: `detect → resolve → apply → remember → aggregate → inform`.

### Non-negotiable constraints (from the build spec)

- Flarum **1.x** only. No 2.x APIs, no dual-version compatibility shims. Do not start the 2.x upgrade.
- Do not overengineer; do not expand scope beyond the spec.
- Inspect real Flarum 1.x source rather than guessing at API behaviour.
- **No Packagist** — no runtime calls, no API client, no caching. `resources/languages.php` is the single
  source of truth for the language catalog, maintained by the author, **not** admin-editable.
- Exactly **one** analytics table. No visitors/events/requests/languages/countries tables.
- **Never override an existing user locale.** A manual language choice must never be overwritten. *(Critical.)*
- Only aggregated daily statistics may be stored. Never store: IP addresses, raw User-Agent strings, raw
  `Accept-Language` headers, raw visitor identifiers, URLs, referrers. No raw request events.
- Validate locales against installed locales before applying. Never trust `Accept-Language`, cookies,
  country codes, or locale input. Never persist arbitrary locale values.
- Admin text lives in `locale/*.yml` — no hardcoded visible strings in PHP or JS.
- No new design system; match the author's existing admin UI style (the FriendsOfFlarum Badges idiom).

---

## 2. Environment facts

| Tool | Status |
|---|---|
| `php`, `composer`, `node`, `npm`, `yarn` | **MISSING** |
| `curl` 8.21, `perl` 5, `awk` (GNU 5.4), `xxd`, `od`, `gzip` | available |

Consequences:

- **`composer install`, `phpunit`, `tsc`, and `webpack` cannot run locally.** PHP tests and the JS build can
  only be verified in CI (`.github/workflows/{backend,frontend}.yml`, already wired to `flarum/framework`
  reusable workflows, `main_git_branch: 1.x`, prettier + typescript enabled) or on the author's machine.
  Never claim tests pass locally.
- `curl` + `perl` + `awk` **are** sufficient to download and generate the IP→country dataset (see §5).

### Reference clones (temp dirs — may vanish; re-clone as needed)

| Path | What |
|---|---|
| `C:/Users/husey/AppData/Local/Temp/flarum-core/framework/core` | Flarum **1.8.19** (sparse checkout of `framework/core`) |
| `/tmp/fof-badges` | branch `1.x` — the admin UI/LESS design idiom to mirror |
| `/tmp/fof-geoip` | branch `1.x` @ `aa104ed` — inspected, now **unused** (see §3) |
| `/tmp/hf-awards`, `/tmp/lang-about`, `/tmp/lang-utils` | secondary references |
| `/tmp/langpacks/*` | all **86** official `flarum-lang` packs |

> `Read` fails on `/tmp/...` paths; resolve with `pwd -W` and use the `C:/Users/...` form.
> `raw.githubusercontent.com` returns **405** via WebFetch — clone with git, or fetch `github.com` HTML /
> `api.github.com` instead.

### Preserved data

**`.claude/reference/flarum-lang-catalog.tsv`** — all 86 packs as
`dir <TAB> package <TAB> code <TAB> title_as_published`. Regenerated from the clones and committed to the
workspace so it survives session loss. This is the input for `resources/languages.php`.

Important: the published `title` fields are **inconsistent** — a mix of English and native names
(`Turkish`, but `日本語`, `Русский`, `Български`, `Italiano`), and one is lowercase (`hindi`). **Do not reuse
them verbatim.** The extension must ship its own curated English + native names.

---

## 3. Architectural decision: fof/geoip is dropped entirely

### Why

`fof/geoip` 1.x resolves country via `GeoIPRepository::get()` → `Api\GeoIP::getSaved()`, which reads **only**
the `ip_info` table. That table is populated **solely when a user makes a post**. So for a first-time guest —
precisely the visitor this extension exists to serve — there is never a cached row, and cache-only IP
detection would be inert. The alternative, `Api\GeoIP::get()`, is a **blocking external HTTP call** to
ip-api.com (free tier ≈ 45 req/min), which conflicts with the spec's "do not call external APIs" and
"no expensive operations on every page request".

### Decision

Build a **standalone, self-contained IP→country lookup inside this extension**. No `fof/geoip`, no external
APIs, no API keys, no runtime downloads. Works for every visitor out of the box.

> This deliberately overrides the original spec's §5 "Do not implement your own geolocation system" and §34's
> ban on bundled GeoIP databases. Decided by the project owner after the `ip_info` limitation was surfaced.
> **Accepted tradeoff:** the extension carries a data file that needs periodic regeneration to stay accurate.

### Resolution order in `IpCountryLookup::countryFor(?string $ip): ?string`

1. **Reject non-public IPs** — empty, malformed, loopback, private, link-local, reserved → `null`.
2. **Trusted edge / server headers**, when present (free, zero cost, more accurate than any bundled DB):
   `CF-IPCountry` (Cloudflare), `CloudFront-Viewer-Country`, `X-Vercel-IP-Country`, `Fastly-Geo-Country`,
   `X-AppEngine-Country`, plus `GEOIP_COUNTRY_CODE` / `MM_COUNTRY_CODE` from `$request->getServerParams()`
   (nginx/apache GeoIP modules). Validate strictly against `/^[A-Z]{2}$/`; treat `XX`, `T1`, `ZZ` as unknown.
   Spoofable in principle, but the only consequence is which UI language is offered — a visitor can already
   set `Accept-Language` freely, so this adds no meaningful risk. Document it.
3. **Bundled binary dataset** (below).
4. `null` → detection falls through to the next method in the configured order.

### Binary dataset design

Two files, fixed-width sorted records, **contiguous and exhaustive** (gaps filled with an "unknown" entry) so
each record needs only a range *start* — no end address, no length field:

| File | Record | Layout |
|---|---|---|
| `resources/ip4.dat` | **6 bytes** | 4-byte big-endian start IP + 2 ASCII country chars (`\0\0` = unknown) |
| `resources/ip6.dat` | **10 bytes** | 8-byte big-endian top-64-bits of start + 2 ASCII country chars |

Storing the 2-char code inline (rather than an index into a side table) costs the same as a `uint16` index,
needs no second file, and sidesteps the fact that a `uint8` index cannot safely hold ~250 ISO codes plus the
RIR extras (`EU`, `AP`, `ZZ`).

**Reader:** `fopen('rb')` → `filesize() / RECORD` = record count → binary search with `fseek` + `fread(6)`,
taking the greatest start ≤ target. ~18 iterations of tiny reads, served from the OS page cache. Nothing is
loaded into PHP memory. Detection runs at most once per visitor anyway (§6), so this is negligible.

Estimated size ≈ 1.4 MB (IPv4, ~230k ranges) + ~0.9 MB (IPv6, ~90k ranges) ≈ **2.3 MB**. Stored raw, not
gzipped — `fseek` requires it.

**Source data: RIR delegated-extended statistics.** Public, freely redistributable, no attribution
requirement, no EULA:

```
https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest
https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest
https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest
https://ftp.afrinic.net/stats/afrinic/delegated-afrinic-extended-latest
https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-extended-latest
```

RIR data is *registrant*-level, so it is coarser than a commercial DB — entirely adequate for picking a UI
language, and free of licensing encumbrance. **Upgrade path if more precision is ever wanted:** DB-IP Lite
(CC BY 4.0) via `sapics/ip-location-db`, which would add a required attribution line to the README.

**Generator:** `scripts/build-ip-data.php` — a maintainer-facing PHP CLI script, canonical and committed,
never invoked at runtime. Since PHP is unavailable here, the initial `.dat` files will be produced with an
equivalent throwaway `perl` script and validated by spot-checking known IP→country pairs
(e.g. `8.8.8.8`→US, a known `212.156.x`→TR, an APNIC range→CN).
**Risk to flag:** the committed PHP generator stays unverified until someone with PHP runs it once and
confirms byte-identical output.

### Country → language map

`resources/countries.php`: country code → **ordered candidate locale list**; `LocaleMatcher` picks the first
*installed* one. Examples: `TR => ['tr']`, `CH => ['de','fr','it']`, `BR => ['pt-BR','pt']`,
`CN => ['zh-Hans']`, `TW => ['zh-Hant']`, `RS => ['sr-Cyrl','sr-Latn']`, `MX => ['es_MX','es']`,
`AR => ['es_AR','es']`, `BE => ['nl','fr']`, `CA => ['en','fr']`.

Note `CA => ['en','fr']` not `['fr']` — English is the majority language, so majority-English countries must
list `en` first or they would be wrongly forced to a minority language. If no candidate is installed, the
request is recorded as a miss and detection falls through. Not admin-configurable (spec §34).

> **Corrected in Phase 2 — `en` is *not* guaranteed installed.** §3 originally justified the `en`-first
> ordering with "`en` is core's always-present default". That is wrong, and it matters. Verified in
> Flarum 1.8.19: `addLocale()` has exactly **two** callers —
> `Extend\LanguagePack` (one per installed pack) and `LocaleServiceProvider::register()`, which calls
> `addLocale($repo->get('default_locale', 'en'), 'Default')`. English ships as core *translations*
> (`addTranslations('en', locale/core.yml)`), never as a registered *locale*. So the locale guaranteed to be
> in `getLocales()` is **the configured `default_locale`**, not `en`: on a forum whose `default_locale` is
> `tr`, `hasLocale('en')` returns **false**.
>
> Consequences: candidate entries positioned *after* `en` are genuinely reachable, not dead weight, so the
> full ordered chains in `resources/countries.php` are meaningful. `LocaleMatcher` (Phase 3) must never treat
> `en` as an implicit terminal fallback — the guaranteed fallback is `LocaleManager::getLocale()` /
> `default_locale`.

---

## 4. Skeleton bugs to fix

**All fixed in Phase 2** except the two rows marked *(Phase 8)*.

| File | Problem | Fix |
|---|---|---|
| `extend.php:12` | `namespace HuseyinFiliz\\LanguageDetection;` — double backslash is a **PHP parse error**. The extension cannot load at all today. | ✅ single backslash |
| `composer.json:3` | description contains literal HTML entity `&#39;` | ✅ real apostrophe |
| `README.md` | same `&#39;` issue, plus a `PUT_DISCUSS_SLUG_HERE` placeholder | ✅ real apostrophe; Discuss link removed until a thread exists |
| `composer.json:10` | `flarum/core: ^1.2.0` | ✅ `^1.8.0` (core verified at 1.8.19); added `"php": "^8.0"` |
| `composer.json:27-32` | `extra.flarum-extension.category` and `icon` are empty | ✅ `category: language`, `fas fa-language` on `#2980b9` |
| `less/admin.less` | empty | *(Phase 8)* |
| `locale/en.yml` | stub `my_cool_key: My Cool Key` | ✅ real keys |
| `locale/tr.yml` | **does not exist** | ✅ created |
| `js/src/admin/index.ts` | stub `console.log(...)` | ✅ stub removed; real page registration *(Phase 8)* |

No `fof/geoip` entry is added to `require` **or** `suggest` — the dependency is gone (§3).

---

## 5. Locale matching (spec §7 is wrong)

**Flarum 1.x locale codes are hyphenated**, taken from each pack's `extra.flarum-locale.code` — *not*
`tr_TR` / `en_US` as the spec assumed. Proof, two independent ways:

- `LocaleManager::getJsFiles()` / `getCssFiles()` both do `explode('-', $locale)` to fall back from a
  regional code to its base language.
- Real published codes in `.claude/reference/flarum-lang-catalog.tsv`: `de`, `tr`, `pt-BR`, `zh-Hans`,
  `zh-Hant`, `sr-Cyrl`, `sr-Latn`.

There is **no `tr_TR` or `en_US` locale in Flarum at all**, so `Accept-Language: tr-TR` must resolve to
installed **`tr`**.

Three catalog irregularities that must be handled, not normalized away:

- `es_AR` and `es_MX` use **underscores** — inconsistent with every other pack.
- `uzb` is non-ISO (should be `uz`).
- Script subtags (`zh-Hans`, `sr-Cyrl`) are mixed-case and must not be lowercased when applied.

### Rule

`LocaleMatcher` compares **case-insensitively and separator-insensitively** against
`LocaleManager::getLocales()` keys, then applies the installed locale's **exact original key**.

Order: exact match → base-language fallback (`pt-BR` → `pt`) → sibling regional variant if only one exists →
no match. Never hardcode a locale list; always read `getLocales()` / `hasLocale()`.

---

## 6. Data model and analytics

### Single table `language_detection_stats`

`id`, `date`, `locale`, `country_code`, `requests`, `unique_visitors`, `created_at`, `updated_at`.
Unique on `(date, locale, country_code)`; indexes on `date`, `(locale, date)`, `(country_code, date)`.

**Deviation — `country_code` is `CHAR(2) NOT NULL DEFAULT ''`, not nullable.** The spec's nullable
`country_code` is *incompatible* with its own required UNIQUE constraint on MySQL: `NULL`s are treated as
distinct in a unique index, so every GeoIP-less request would insert a fresh duplicate row and the atomic
upsert (`ON DUPLICATE KEY UPDATE`) would never dedupe. `''` means unknown and renders as "Unknown" in the
dashboard. All other columns exactly as specified.

**Fallback requests need no extra column or table.** Stats store the **requested** locale; therefore
`fallback = SUM(requests) WHERE locale NOT IN (installed locales)` — computed at query time. This is the same
signal that drives the missing-languages report, satisfying spec §26 with zero schema additions.

**No Eloquent model.** The query builder via `ConnectionInterface` covers the upsert, the dashboard queries,
and cleanup; the atomic upsert needs raw SQL regardless. Avoids an extra class and honours §34. Use
`$db->table('language_detection_stats')` so the table prefix is applied automatically.

### Counting

- **Requests** are recorded on forum-frontend **GET** page views. Parsing `Accept-Language` is pure string
  work with no I/O, so this is cheap, and it keeps trends and totals meaningful.
- **Detection** runs at most once per visitor (guest cookie / user preference), so the IP lookup and locale
  write are not repeated.
- **Unique visitors without storing any identifier:** the visitor's cookie carries the date it was last
  counted; if that date ≠ today, increment `unique_visitors` and update the cookie. **Nothing
  visitor-specific is ever written server-side.** This is stricter than the spec's "random, non-identifying
  ID" and fully satisfies "do not store the visitor ID itself in the database". Approximate by nature
  (cleared cookies inflate, shared devices deflate) — document that.
- **Bot detection:** substring match of the User-Agent against a static list (`bot`, `crawler`, `spider`,
  `slurp`, `bingpreview`, `facebookexternalhit`, `headlesschrome`, `curl`, `wget`, `python-requests`, …).
  The UA is read and discarded, never stored.

### Cookies

`CookieFactory` prefixes names and applies Flarum's configured path/domain/secure/SameSite plus HttpOnly.
Actual names on the wire:

- `flarum_language_detection_locale` — the resolved locale (spec §14), 1 year.
- `flarum_language_detection_day` — date this visitor was last counted, 1 year.

Neither is an identifier.

---

## 7. Middleware ordering

```php
(new Extend\Middleware('forum'))
    ->insertBefore(\Flarum\Http\Middleware\SetLocale::class, DetectLanguage::class),
```

Verified forum pipeline order:

```
InjectActorReference → flarum.forum.error_handler → ParseJsonBody → CollectGarbage → StartSession
→ RememberFromCookie → AuthenticateWithSession → SetLocale → flarum.forum.route_resolver → CheckCsrfToken → …
```

Why *before* `SetLocale` — this is what makes "never override a manual choice" **structural** rather than a
special case. Core's `SetLocale` is:

```php
$actor = RequestUtil::getActor($request);
if ($actor->exists) { $locale = $actor->getPreference('locale'); }
else                { $locale = Arr::get($request->getCookieParams(), 'locale'); }
if ($locale && $this->locales->hasLocale($locale)) { $this->locales->setLocale($locale); }
$request = $request->withAttribute('locale', $this->locales->getLocale());
```

So: our middleware sets a detected locale; `SetLocale` then *overrides* it whenever an explicit user
preference or `locale` cookie exists. An explicit choice always wins, automatically.

At that position the actor is already resolved (`AuthenticateWithSession` runs earlier) and `ipAddress` is
already set by `ProcessIp` near the top. Registering on the `forum` frontend only, GET only, means this runs
on page loads — not on every SPA XHR.

**Gotcha:** `Extend\Middleware` stores `insertBefore` as `[$original => $new]`, keyed by the original class —
so only **one** middleware can be inserted before `SetLocale` per extender instance. Fine here.

**User locale rules (spec §15, critical):** if `$user->getPreference('locale')` is already set → **do
nothing**. Only write a preference for a user who has none. `locale` is a core-registered preference
(`User::registerPreference('locale')`), read/written via `getPreference()` / `setPreference()`.

---

## 8. Other spec deviations

- **§32 permission dropped.** Flarum 1.x's admin frontend is admin-only, so a
  `huseyinfiliz.language-detection.view` permission has no non-admin subject to gate — it would be
  decorative. Endpoints use `$actor->assertAdmin()`, the standard 1.x idiom. The spec's "if appropriate for
  Flarum 1.x" clause anticipates this.
- **§33 naming.** `src/Api/Statistics.php` alongside `src/Statistics.php` would mean two classes named
  `Statistics`. Using Flarum's `…Controller` convention instead.
- **§35 paths.** Keeping the skeleton's `js/src/{admin,forum}/…` (flarum-cli convention) rather than the
  spec's `js/{admin,forum}/…`.
- **New file** `src/IpCountryLookup.php` — required by §3; had no counterpart in §33 because geolocation was
  originally delegated to `fof/geoip`.
- **The IP notice changes meaning.** The spec's non-blocking warning ("IP-based language detection requires
  FriendsOfFlarum GeoIP…") is obsolete. Replace with a note stating the bundled dataset's build date and
  that regenerating it refreshes accuracy.

---

## 9. Verified Flarum 1.8.19 API notes

- `LocaleManager`: `getLocale()`, `setLocale()`, `addLocale()`, `getLocales()` (`[code => title]` for
  installed packs), `hasLocale()`, `getJsFiles()`/`getCssFiles()` (both `explode('-', …)`), `clearCache()`.
  `LocaleServiceProvider` always registers `default_locale`, so the fallback locale is guaranteed present —
  but **only that one**. `addLocale()` has exactly two callers in core (`Extend\LanguagePack` per installed
  pack, and `LocaleServiceProvider::register()` for `default_locale`), so `hasLocale('en')` is false on any
  forum whose `default_locale` is not `en`. See the correction box in §3.
- `Extend\Middleware`: `add`, `replace`, `remove`, `insertBefore`, `insertAfter`.
- `Extend\Console`: `command(string)`, `schedule(command, callback, args = [])`.
- `Extend\Settings`: `default()`, `serializeToForum()`. Not needed for the forum payload here — settings are
  admin-only and `Admin\Content\AdminPayload` exposes them as `app.data.settings`.
- Custom admin payload: `Extend\Frontend('admin')->content(fn (Document $d) => $d->payload['key'] = …)`
  → read in JS as `app.data['key']` (idiom confirmed in `fof/geoip`, which uses
  `app.data['fof-geoip.services']`).
- Admin JSON endpoint idiom (from `fof/badges`): a `RequestHandlerInterface` that calls
  `RequestUtil::getActor($request)` → `$actor->assertAdmin()` → returns
  `Laminas\Diactoros\Response\JsonResponse`.
- `ExtensionData`: `for()`, `registerPage()`, `registerSetting()`, `registerPermission()`. `AdminPage`
  provides `buildSettingComponent()`, `setting()`, `dirty()`, `saveSettings()`.
- Cookies via `Flarum\Http\CookieFactory` + `dflydev/fig-cookies` (`FigResponseCookies`, `SetCookie`).
- `Flarum\Console\AbstractCommand` — implement `fire()`, use `info()` / `error()`.

### Admin UI idiom to mirror (`fof/badges` `less/admin.less` + `BadgesPage.tsx`)

`.card-style()` mixin — `background: var(--body-bg, @body-bg); border: 1px solid var(--control-bg,
@control-bg); border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.05)`. Page root `padding: 24px;
max-width: 1400px; margin: 0 auto`. `-header` flex row; `-tabs` pill bar with `.active`; `.Button-badge`
count pill; `-content { .card-style(); padding: 24px; min-height: 400px; }`. Stat grid
`repeat(auto-fit, minmax(150px, 1fr))` of centered `.card-style()` cards. `.CardList` grid tables driven by a
`--grid-cols` custom property, collapsing to `flex-column` at the 768px breakpoint. Empty state = 48px icon
at 0.4 opacity. CSS custom properties always with LESS fallbacks.

The trend chart must be inline SVG in this same idiom using Flarum's CSS variables — **no chart library**,
no new palette, no new design system (spec §36).

---

## 10. Settings (exactly five — spec §30, "no additional settings")

| Key (prefixed `huseyinfiliz-language-detection.`) | Values | Default |
|---|---|---|
| `detection_order` | `browser_ip`, `ip_browser` | `'browser_ip'` |
| `default_locale` | installed locale, or empty = forum default | `''` |
| `enable_analytics` | bool | `'1'` |
| `ignore_bots` | bool | `'1'` |
| `retention_days` | `0` (never), `30`, `90`, `180`, `365` | `'90'` |

Registered via `Extend\Settings->default()` in `extend.php`. Defaults are **strings** on purpose — see
Phase 2 decision 4 in §12.

---

## 11. File layout to build

```
extend.php                         (fix namespace; wire frontend, locales, settings, middleware, routes, console)
composer.json                      (fix entities, ^1.8.0, php ^8.0, category, icon)
README.md                          (fix entities + discuss slug; privacy section per §17/§29)
CHANGELOG.md
migrations/2026_08_23_000000_create_language_detection_stats_table.php
resources/
  languages.php                    (86-entry catalog from .claude/reference/flarum-lang-catalog.tsv)
  countries.php                    (country -> ordered candidate locales)
  ip4.dat  ip6.dat                 (generated; Phase 5)
scripts/build-ip-data.php          (maintainer-only regeneration; never runs at runtime)
src/
  BrowserLanguageParser.php  LocaleMatcher.php  CountryLanguage.php
  IpCountryLookup.php        BotDetector.php    LanguageDetector.php
  Analytics.php              Statistics.php     LanguageCatalog.php
  Middleware/DetectLanguage.php
  Api/StatisticsController.php  Api/MissingLanguagesController.php  Api/CleanupController.php
  Console/CleanupCommand.php
locale/en.yml  locale/tr.yml
less/admin.less
js/src/admin/index.ts
js/src/admin/components/LanguageDetectionPage.tsx  (+ StatsCards, LanguagesTable,
                                                    MissingLanguages, CountriesTable, TrendChart, SettingsTab)
tests/unit/{BrowserLanguageParserTest,LocaleMatcherTest,LanguageDetectorTest,IpCountryLookupTest}.php
tests/integration/{DetectionTest,StatisticsTest}.php
```

Files are created because they carry behaviour — not to match a tree (spec §33). By the same rule, Phase 2
**deleted** the skeleton's `js/forum.ts`, `js/src/forum/index.ts`, `js/src/common/index.ts` and
`less/forum.less` — see §12 Phase 2 decision 1.

---

## 12. Next implementation steps

**Phase 2 — Skeleton & initial setup. ✅ DONE.**
Built: `extend.php` (namespace fixed; admin frontend, locales, five settings defaults — no class references
yet, so the extension loads), `composer.json`, `README.md`, `CHANGELOG.md`,
`migrations/2026_08_23_000000_create_language_detection_stats_table.php`, `resources/languages.php`,
`resources/countries.php`, `locale/en.yml`, `locale/tr.yml`.

Decisions taken during Phase 2 — all deliberate, all reversible:

1. **Forum frontend dropped.** Deleted `js/forum.ts`, `js/src/forum/index.ts`, `js/src/common/index.ts`,
   `less/forum.less`, and the `Extend\Frontend('forum')` block; `js/admin.ts` now exports only
   `./src/admin`. Every behaviour in §11 is server-side (middleware) or admin-side, so the forum bundle had
   nothing but a `console.log` in it. Verified safe: `flarum-webpack-config@2.0.2` (the locked version)
   builds entrypoints by **file existence** (`getEntryPoints()` probes `{forum,admin}.{js,ts}`), so an
   admin-only build is a supported configuration, not a broken one. `composer.json`'s `flarum-cli.modules`
   now has `forum: false, jsCommon: false` to match. Re-add if a forum-side need ever appears.
2. **`resources/languages.php` has 87 entries, not 86.** The 86 packs plus `en` with `'package' => null`,
   because the statistics tables need a display name for English and English has no `flarum-lang` package.
   Verified programmatically: all 86 codes and package names match
   `.claude/reference/flarum-lang-catalog.tsv` exactly, no duplicates.
3. **No standalone index on `date`.** §6 asked for three indexes; the unique index on
   `(date, locale, country_code)` already has `date` as its leftmost column, so a separate `date` index can
   never be chosen by MySQL for any query this extension issues (dashboard date ranges, cleanup deletes) and
   would only cost write throughput. `(locale, date)` and `(country_code, date)` are both present as
   specified. All index names are given **explicitly**: Laravel prepends the configured table prefix to
   generated index names, and `language_detection_stats_date_locale_country_code_unique` (56 chars) plus a
   long prefix would breach MySQL's 64-character limit.
4. **Setting defaults are strings (`'1'`, `'0'`, `'90'`), not PHP scalars.** Verified in core:
   `DefaultSettingsRepository::get()` returns the default **raw** from the collection, while a saved setting
   is always a string, so a non-string default makes the same key change type after the first save.
   `AdminPage.buildSettingComponent()` tests booleans as `!!value && value !== '0'` and selects as
   `value || defaultValue` — both correct for strings (note `'0'` is *truthy* in JS, which is exactly why the
   `retention_days: 0` "never" option must be the string `'0'` and not the integer `0`). PHP side reads
   `(bool)` (correct for `'1'`/`'0'`) and `(int)`.
5. **`locale/*.yml` covers settings, the IP dataset notice, and the cleanup command only** — 24 leaf keys,
   verified identical in both files with matching ICU placeholders. Dashboard keys land in Phase 8.
6. **README kept minimal.** Only the entity and placeholder bugs fixed; the Discuss link is removed rather
   than invented. The mandatory privacy/features prose is Phase 10's job, per §12 — writing it now would
   document behaviour that does not exist yet.

**Phase 3 — Browser detection (start here).** `BrowserLanguageParser` (q-values, ordering, region subtags,
malformed and empty input) + `LocaleMatcher` (exact → base → sibling → none) + default fallback. Unit tests.
Read the §3 correction box first: `en` is **not** a guaranteed fallback.

**Phase 4 — Apply & remember.** `Middleware/DetectLanguage` inserted before `SetLocale`; guest cookie;
authenticated users **only when they have no locale**; one-time behaviour. Integration tests, including
"manual locale never overwritten".

**Phase 5 — Standalone IP lookup.** `IpCountryLookup` (private-IP rejection → edge headers → binary search);
`scripts/build-ip-data.php`; generate and spot-check `ip4.dat` / `ip6.dat`; `CountryLanguage`. Unit tests
with a small synthetic `.dat` fixture rather than the shipped 2.3 MB files.

**Phase 6 — Analytics.** Atomic daily upsert, request counting, cookie-date unique counting, country codes,
bot filtering, `enable_analytics` honoured. Tests including bot exclusion and analytics-disabled.

**Phase 7 — Missing languages.** `LanguageCatalog` diffing requested locales against
`LocaleManager::getLocales()`, sorted by request volume, with a "View language package" link.

**Phase 8 — Admin dashboard.** `ExtensionPage` subclass in the `fof/badges` idiom; the three API endpoints;
summary cards; languages / missing / countries tables; 7·30·90-day inline-SVG trend; settings tab. Complete
`locale/*.yml`.

**Phase 9 — Cleanup.** `language-detection:cleanup` console command, `Extend\Console` scheduling, and the
manual "Delete old statistics" admin action.

**Phase 10 — Final review.** 1.x compatibility, security, performance, privacy, migrations, translations,
tests, README (privacy section is mandatory), CHANGELOG. **Do not begin the Flarum 2.x upgrade.**

---

## 13. Open risks

1. **`scripts/build-ip-data.php` is unverified** until run under real PHP; the initial `.dat` files are
   generated here via perl. Confirm parity before release.
2. **Dataset freshness** — RIR data drifts. Needs periodic regeneration and a release; the admin notice
   should surface the build date.
3. **Nothing can be built or tested locally** (no PHP/Node). CI is the only gate.
4. **RIR precision** is registrant-level and coarser than commercial DBs. Acceptable for language selection;
   DB-IP Lite is the documented upgrade path if it proves insufficient.
5. **Cookie-based unique counts are approximate** by design — a deliberate privacy tradeoff to document.
