# Handoff — `huseyinfiliz/language-detection` (Flarum 1.x)

> **Status: Phases 1–3 are complete. Phase 4 (apply & remember) is next — its full work order is §15;
> start there.** §7 carries the middleware position, core's `SetLocale` source and the user-locale rules,
> including a correction box added on 2026-08-24 that Phase 4 must read.
> This file exists so a fresh session can resume without re-doing discovery.
> It is a working document — remove it (or gitignore it) before release.
>
> **Repository state, 2026-08-24:** branch `1.x`, pushed to `origin/1.x`.
> Phase 3 (`702995c`) adds `src/BrowserLanguageParser.php`, `src/LocaleMatcher.php` and their two unit tests.
> `extend.php` still references no project classes, by design (§14) — Phase 4 is the first phase that
> wires anything up.
>
> **Commit convention (standing instruction from the project owner):** author **and** committer are
> `Hüseyin Filiz <mysuperuser01@gmail.com>`; never add a `Co-Authored-By: Claude … <noreply@anthropic.com>`
> trailer, and never put `noreply@anthropic.com` anywhere in commit metadata. See §2 for the two git
> failure modes this repo hits.

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
- `curl` + `perl` + `awk` **are** sufficient to download and generate the IP→country dataset (see §3).

### Reference clones (temp dirs — may vanish; re-clone as needed)

| Path | What |
|---|---|
| `C:/Users/husey/AppData/Local/Temp/flarum-core/framework/core` | Flarum **1.8.19** (sparse checkout of `framework/core`) |
| `/tmp/fof-badges` | branch `1.x` — the admin UI/LESS design idiom to mirror |
| `/tmp/fof-geoip` | branch `1.x` @ `aa104ed` — inspected, now **unused** (see §3) |
| `/tmp/hf-awards`, `/tmp/lang-about`, `/tmp/lang-utils` | secondary references |
| `/tmp/langpacks/*` | all **86** official `flarum-lang` packs |

> All of the above were **verified still present on 2026-08-24**. They live in temp directories, so re-clone
> if a later session finds them gone; the Flarum core checkout is the one Phase 3 actually needs.
> Note the core checkout is **sparse and has no `vendor/`** — Symfony/Illuminate source cannot be read from it.
>
> **Superseded on 2026-08-24 (Phase 3):** the `flarum-core` temp checkout is **gone**, but it no longer
> matters — the repo now has a full `vendor/` (gitignored, present on disk), so `vendor/flarum/core/src/`,
> `vendor/symfony/translation/`, `vendor/flarum/testing/` and `vendor/phpunit/phpunit` (locked at **9.6.36**)
> are all readable in place. Prefer `vendor/` over re-cloning; it also carries the Symfony/Illuminate source the
> sparse checkout lacked. `/tmp/fof-badges` and `/tmp/langpacks/*` were not re-checked this session.
>
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

### Test infrastructure (scaffolded and committed; unit tests written in Phase 3)

| Path | Notes |
|---|---|
| `tests/phpunit.unit.xml` | suite is `./unit`, suffix `Test.php`; registers Mockery's `TestListener`, so **Mockery is available**. Needs no `bootstrap` attribute — it is byte-for-byte flarum/testing's own unit config, and `vendor/bin/phpunit` loads the autoloader itself |
| `tests/phpunit.integration.xml` + `tests/integration/setup.php` | integration suite; needs a real database (`composer test:setup`, run once), so it effectively only runs in CI |
| `tests/unit/BrowserLanguageParserTest.php`, `tests/unit/LocaleMatcherTest.php` | Phase 3; base class `Flarum\Testing\unit\TestCase` (lowercase `unit` namespace segment, as the vendored class declares it) |
| `tests/fixtures/.gitkeep` | still an empty placeholder — Phase 5's synthetic `.dat` fixtures land here. `tests/unit/.gitkeep` was removed once real tests existed |

`composer.json` has `require-dev: flarum/testing ^1.0.0` and maps `autoload-dev` PSR-4
`HuseyinFiliz\LanguageDetection\Tests\` → `tests/`. Scripts: `composer test:unit`, `test:integration`, `test`.

**Namespace convention:** `tests/unit/FooTest.php` declares `HuseyinFiliz\LanguageDetection\Tests\Unit\FooTest`
— capital `Unit` against a lowercase `unit/` directory. Both `fof/badges` and the author's
`huseyinfiliz/awards` do exactly this; it works because PHPUnit `require`s each discovered file directly, so
PSR-4 is never asked to resolve the class. **Consequence:** a shared helper or fixture *class* placed in
`tests/unit/` would fail to autoload on case-sensitive Linux CI. Keep test classes self-contained, or put
shared fixtures in `tests/fixtures/` and require them explicitly.

### Two git failure modes in this repo

1. `git config user.name` / `user.email` were **empty**. Git then refuses to commit, and from this harness the
   failure surfaces as a *missing tool result* rather than git's "please tell me who you are". Both are now set
   repo-locally in `.git/config` (global config untouched) — but re-check in a fresh clone.
2. `git commit -F -` fed by a heredoc failed repeatedly. Write the message to a file **outside** the repo
   (e.g. under `$TMPDIR`) and use `git commit -F <file>`. Verify afterwards with
   `git log -1 --pretty='%an <%ae>%n%cn <%ce>'`.

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

Order, applied **per candidate** in preference order (not as tier-by-tier sweeps across all candidates):
exact match → progressive one-subtag-at-a-time truncation (`zh-Hans-CN` → `zh-Hans` → `zh`) → sibling regional
variant if exactly one exists → no match. Never hardcode a locale list; always read `getLocales()` /
`hasLocale()`. **§14 is the authoritative version of this algorithm**, including why per-candidate ordering
matters and the single `uzb`/`uz` alias.

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

> **Correction added 2026-08-24 (Phase 3), read before Phase 4 — core never writes a `locale` cookie.**
> The middleware position and the whole "manual choice wins structurally" argument are correct, but the guest
> half needs a caveat that changes how the cookie in §6 works. Grepped the entirety of `vendor/flarum/core`
> (both `src/**.php` and `js/src/**`) for a `locale` cookie **write**: there is exactly **one** hit anywhere,
> and it is the *read* in `SetLocale` quoted above. Nothing in core 1.8.19 ever sets that cookie.
>
> Two consequences:
>
> 1. `SetLocale` reads the raw cookie param **`locale`**, *not* `flarum_locale`. `CookieFactory::getName()` is
>    `$this->prefix.'_'.$name` with `prefix` defaulting to `flarum` (`cookie.name` in config), so anything
>    written through `CookieFactory` is invisible to `SetLocale`. Our `flarum_language_detection_locale`
>    cookie (§6) therefore **cannot** work by feeding `SetLocale` — and should not try to.
> 2. For a guest there is consequently no core-managed "manual choice" to be overridden: core 1.x ships no
>    guest language switcher at all. The override still matters for **logged-in users** (via
>    `getPreference('locale')`), and it still comes for free if a *third-party* language-switcher extension
>    writes an unprefixed `locale` cookie — which is exactly the behaviour we want.
>
> So Phase 4's guest path is: call `$locales->setLocale($detected)` **directly**, and use our own prefixed
> cookie purely as the "already detected, don't do it again" memo. `SetLocale` runs afterwards, finds no
> `locale` cookie, skips its own `setLocale()`, and then propagates *our* value into the request attribute via
> `withAttribute('locale', $this->locales->getLocale())`. Verified against the source above: the `if ($locale
> && hasLocale($locale))` guard means a missing cookie is a no-op, not a reset.

At that position the actor is already resolved (`AuthenticateWithSession` runs earlier) and `ipAddress` is
already set by `ProcessIp` — which is piped in `Foundation\InstalledApp::getMiddlewareStack()`, *outside* the
`flarum.forum.middleware` list, so it runs ahead of the entire forum pipeline. Registering on the `forum`
frontend only, GET only, means this runs on page loads — not on every SPA XHR.

**Gotcha:** `Extend\Middleware` stores `insertBefore` as `[$original => $new]`, keyed by the original class —
so only **one** middleware can be inserted before `SetLocale` per extender instance. Fine here. Second gotcha
in the same method: `extend()` does `array_splice($stack, array_search($original, $stack), 0, $new)`, and
`array_search` returns `false` when the target is absent, which `array_splice` coerces to offset `0` — a
missing anchor silently inserts at the **front** of the pipeline rather than erroring. `SetLocale` is always
present on the forum frontend, so this cannot bite here, but do not reuse the pattern against a class that
might not be registered.

**User locale rules (spec §15, critical):** if `$user->getPreference('locale')` is already set → **do
nothing**. Only write a preference for a user who has none. `locale` is a core-registered preference
(`User::registerPreference('locale')` in `UserServiceProvider:132`, with **no transformer and no default**, so
`getPreference('locale')` returns `null` when unset), read/written via `getPreference()` / `setPreference()`.
Note `setPreference()` is wrapped in `if (isset(static::$preferences[$key]))` — writing an *unregistered* key
is a **silent no-op**, not an error. `locale` is registered, so this is only a warning against inventing new
preference keys without an extender.

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
- **Core contains no `Accept-Language` handling whatsoever** — grepped the whole of `src/`: zero hits for
  `Accept-Language` / `acceptLanguage`. `Http\Middleware\SetLocale` reads only the user preference and the
  `locale` cookie. So the header parser must be written from scratch; there is nothing in core to reuse or
  extend.
- `LocaleManager` is **trivially constructible in a unit test**: it is a plain class (no container bindings, no
  interfaces) whose constructor is `__construct(Translator $translator, string $cacheDir = null)`, and
  `addLocale()`/`getLocales()`/`hasLocale()` are simple array operations on a protected `$locales` map.
  `Flarum\Locale\Translator` extends Symfony's `Translator`, whose constructor takes a locale string. So a real
  `new LocaleManager(new Translator('en'))` with a few `addLocale()` calls is preferable to a Mockery double.
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
  `CookieFactory::make()` returns a `SetCookie` whose name is `getName($name)` = `$this->prefix.'_'.$name`,
  with `prefix` from `config('cookie.name')` defaulting to `flarum`; it also applies `path`, `domain`,
  `secure` (from the config URL scheme), and `httpOnly(true)`. **A cookie written through `CookieFactory` is
  therefore always prefixed**, which is why `SetLocale`'s unprefixed `locale` read cannot see it — see the
  correction box in §7 before designing Phase 4's cookie.
- `Extend\Middleware::insertBefore($original, $new)` stores `[$original => $new]` and applies it with
  `array_splice($existing, array_search($original, $existing), 0, $new)`. `array_search` returns `false` for a
  missing anchor, which `array_splice` reads as offset `0` — a typo'd target class silently moves the
  middleware to the front of the stack instead of failing. `ProcessIp` is *not* in this list at all: it is
  piped in `Foundation\InstalledApp::getMiddlewareStack()`, ahead of the whole forum pipeline, so
  `$request->getAttribute('ipAddress')` is already populated anywhere in `flarum.forum.middleware`.
- `User::registerPreference('locale')` (`UserServiceProvider:132`) is declared with **no transformer and no
  default**, so `getPreference('locale')` is `null` until something writes it — a clean "has the user chosen?"
  test. `setPreference()` is guarded by `if (isset(static::$preferences[$key]))`, so writing an *unregistered*
  key is a **silent no-op** rather than an error; any new preference needs `Extend\User->registerPreference()`.
- Integration tests: `Flarum\Testing\integration\TestCase::request($method, $path, $options)` accepts only
  `json`, `authenticatedAs` and `cookiesFrom` — **there is no header option**. Set request headers by chaining
  on the returned request (`->withHeader('Accept-Language', 'tr')`), and use
  `requestWithCookiesFrom($request, $previousResponse)` for anything that has to prove once-per-visitor
  behaviour across two requests.
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

**Phase 3 — Browser detection. ✅ DONE.** Work order in §14, followed as written.
Built: `src/BrowserLanguageParser.php`, `src/LocaleMatcher.php`,
`tests/unit/BrowserLanguageParserTest.php` (31 header cases + 5 dedicated tests),
`tests/unit/LocaleMatcherTest.php` (21 candidate cases + 6 dedicated tests). Nothing else changed except
`CHANGELOG.md` and this file; `extend.php` deliberately still references no project classes.

Decisions taken during Phase 3:

1. **The optional macrolanguage map was included.** §14 left `['no' => ['nb','nn'], 'ku' => ['ckb','kmr']]`
   as a take-it-or-leave-it improvement. Taken, because real browsers send a bare `no` and without it the
   Phase 7 missing-languages report would list `no` as a missing language on a forum that has Norwegian
   installed — a visibly wrong signal to an admin, not just a missed match. It feeds tier 3's existing
   "exactly one installed" rule rather than adding a mechanism, so `no` still declines when both Bokmål and
   Nynorsk are present, exactly as `sr` declines between `sr-Cyrl` and `sr-Latn`.
2. **The output cap is applied after sorting and deduplication, not before parsing.** §14 says to cap "the
   output at ~10 tags before doing any work", but capping the *element list* first would be wrong: nothing
   requires a client to list its highest-q tag first, so an early cut could discard the most-preferred tag.
   The 1024-byte input cap already bounds the work (≈340 elements worst case), so `MAX_TAGS` is applied last.
3. **An over-long header is cut back to whole elements.** A blind `substr` to 1024 bytes can land mid-element
   and turn `de-DE` into a plausible-looking but invented `de-D`, which would then pass shape validation. The
   parser drops everything after the last comma in the truncated string; if there is no comma, it returns `[]`.
4. **`CODE_ALIASES` is applied to the language subtag, not the whole code**, so a client sending `uzb-UZ`
   normalizes to `uz-uz` and still reaches installed `uzb` by truncation.
5. **Tests use a real `LocaleManager`, not a Mockery double** — `new LocaleManager(new Translator('en'))` plus
   `addLocale()` calls, per §9. Base class is `Flarum\Testing\unit\TestCase` (note the lowercase `unit`
   namespace segment, which is how the vendored class actually declares itself). Providers are `static`, and
   PHPUnit is locked at 9.6.36, so `@dataProvider` annotations are correct and attributes are not available.
6. **`tests/phpunit.unit.xml` needs no `bootstrap` attribute.** It is byte-for-byte the same config
   `flarum/testing` ships for its own unit suite, and the composer-installed `vendor/bin/phpunit` stub loads
   `vendor/autoload.php` itself.

**Verification actually performed** (see the honesty rule in §14): the PHP was **not executed** — there is
still no PHP in this environment, so the tests are **unverified until CI runs**. What was done instead: both
algorithms were re-implemented as a throwaway perl model and the full §14 test matrix plus every case in the
two written test files was run through it — **67/67 cases produce the expected result**. That validates the
algorithm (regex behaviour, sort stability, truncation, per-candidate tier ordering, alias folding), *not* the
PHP syntax. Structural checks also passed: UTF-8 without BOM, LF endings, no tabs, no trailing whitespace,
final newline, balanced braces/parens/brackets, and `! ` spacing per `.styleci.yml`'s
`logical_not_operators_with_successor_space`.

**Phase 4 — Apply & remember. ← NEXT. Full work order in §15.** `Middleware/DetectLanguage` inserted before
`SetLocale`, plus a `LanguageDetector` that owns the resolution chain; guest cookie; authenticated users
**only when they have no locale**; one-time behaviour. Integration tests, including "manual locale never
overwritten". §7 carries the verified pipeline order, core's `SetLocale` source, the `insertBefore` gotcha and
the user-locale rules — read its 2026-08-24 correction box before designing the cookie, because core never
writes a `locale` cookie itself. §13 risk 6 is closed, so `setLocale()` can be called with `es_MX` / `zh-Hans`
unguarded. This is the phase that first references project classes from `extend.php`.

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
6. ~~**`Translator::setLocale()` input validation is unverified.**~~ **Closed 2026-08-24 (Phase 3).** Read
   directly from `vendor/symfony/translation/Translator.php`: `setLocale()` calls `assertValidLocale()`, which
   is `preg_match('/^[a-z0-9@_\.\-]*$/i', $locale)`. Underscores, hyphens and mixed case are all accepted, so
   `es_MX` and `zh-Hans` pass. Phase 4 can call `setLocale()` with any key `getLocales()` returns without
   guarding it.

---

## 14. Phase 3 work order — ✅ implemented, kept as the algorithm's specification

> **Phase 3 is done** (§12 records what was built, the six decisions taken, and exactly what was and was not
> verified). This section is retained because it is still the authoritative statement of the parsing and
> matching rules — Phase 10's review should check the code against it, and Phases 4–7 depend on its contracts.
> Do not re-implement it.

**Goal:** two pure, dependency-light classes plus their unit tests. Browser-language *detection and
resolution* only. Nothing is wired up yet.

### Scope discipline — what Phase 3 must NOT touch

- **No middleware.** `Middleware/DetectLanguage` is Phase 4.
- **No analytics, no database, no cookies.** Phases 4 and 6.
- **No IP lookup, no `resources/countries.php` consumer.** `CountryLanguage` is Phase 5.
- **No admin UI, no new locale keys.** Phase 8.
- **Do not register these classes in `extend.php`.** It currently references no project classes at all, which
  is what lets the extension load; leave it that way until Phase 4 has something to wire. Adding a
  `use`/reference now buys nothing and risks a fatal on an installed forum.

### Deliverables

```
src/BrowserLanguageParser.php
src/LocaleMatcher.php
tests/unit/BrowserLanguageParserTest.php
tests/unit/LocaleMatcherTest.php
```

Namespace `HuseyinFiliz\LanguageDetection\`; tests in `HuseyinFiliz\LanguageDetection\Tests\Unit\` (see §2 for
the lowercase-directory caveat). No changes to any other file except `CHANGELOG.md`.

### `BrowserLanguageParser`

Turns a raw `Accept-Language` header into an **ordered list of language tags, most preferred first**. Pure
function, no dependencies, no I/O, no knowledge of installed locales — that separation is what makes both
classes independently testable.

```php
public function parse(?string $header): array   // string[], most-preferred first
```

Rules:

1. `null`, empty, or whitespace-only → `[]`.
2. **Cap the input** at ~1024 bytes and the output at ~10 tags before doing any work. Pure hygiene against a
   pathological header, not a real attack vector.
3. Split on `,`; each element is `tag` optionally followed by `;q=<value>`.
4. Missing `q` → `1.0`. An **unparseable** `q` (`;q=abc`, `;q=`) → treat as `1.0` and keep the tag; the tag
   itself is still a valid signal. Clamp to `[0,1]`.
5. **`q=0` means "explicitly not acceptable"** → drop that tag entirely.
6. `*` → drop. It carries no signal, and the fallback chain already covers "anything".
7. Shape-validate each tag against `/^[A-Za-z]{1,8}([-_][A-Za-z0-9]{1,8})*$/` and drop anything else. This is
   the injection guard — never pass unvalidated header text onward. Underscores are **accepted** even though
   browsers send hyphens: a client sending `tr_TR` is giving us real information, and `LocaleMatcher`
   normalizes separators anyway.
8. Sort by `q` descending, **stable**, so header order is preserved among equal q-values (browsers list in
   preference order). PHP 8.0+ sorts are stable and `composer.json` requires `php: ^8.0`, so `usort` is enough
   — no decorate-sort-undecorate needed.
9. Deduplicate case-insensitively, keeping the highest-q (first) occurrence.
10. **Preserve each tag's original case verbatim.** Normalization belongs to `LocaleMatcher`.
11. A malformed element must never discard the valid ones — skip individually, never throw.

Tests: `'tr,en;q=0.8'`→`['tr','en']`; `'en;q=0.8,tr'`→`['tr','en']` (q beats position);
`'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7'`→ all four in order; `'fr;q=0.5,de;q=0.5'`→`['fr','de']` (stable);
`'tr-TR'`→`['tr-TR']` (region preserved — truncation is the matcher's job); `'zh-Hans-CN,zh-Hans;q=0.9'`→ both;
`'EN-us'`→`['EN-us']`; `'*'`, `'en;q=0'`, `null`, `''`, `'   '`, `','`, `';;;'` → `[]`; `'en;q=0,tr'`→`['tr']`;
`'<script>alert(1)</script>,tr'`→`['tr']`; `'en;q=abc'`→`['en']`; 20 elements → capped at 10; a 5000-char
header → does not blow up.

### `LocaleMatcher`

Resolves parsed tags to an **installed** locale.

```php
public function __construct(LocaleManager $locales) {}

/** @param string[] $candidates ordered, most preferred first */
public function match(array $candidates): ?string;   // an exact key from getLocales(), or null
```

**Normalization for comparison** (never for output): lowercase, `_` → `-`. Build a
`normalized => original key` map from `array_keys($locales->getLocales())` once per instance, lazily. If two
installed keys normalize identically, first wins — document it; it cannot happen with the real catalog.

**Critical: iterate per candidate, applying all tiers to each, in preference order.** Do *not* run a global
exact-match pass across all candidates first. With installed `[en, pt]` and candidates `['pt-BR','en']`,
per-candidate correctly yields **`pt`** (the visitor prefers Portuguese), while a tier-by-tier sweep would
wrongly return `en`. This matches RFC 4647 "lookup" behaviour and is the single most important test in the
file.

For each candidate, in order:

1. **Exact** (normalized) → return the installed key's original spelling. So `es-MX` → returns **`es_MX`**,
   underscore and all; `zh-hans` → returns `zh-Hans`.
2. **Progressive truncation**, one subtag at a time: `zh-Hans-CN` → `zh-Hans` → `zh`. Not a single strip to the
   base language — `zh-Hans` and `sr-Cyrl` are real installed keys, and truncating straight to `zh` would skip
   the pack that actually matches.
3. **Unambiguous sibling:** collect installed keys sharing this candidate's base language. If **exactly one**,
   return it; if more, decline rather than guess. So `pt-PT` (or bare `pt`) → `pt-BR` when that is the only
   `pt*` installed; `sr` → `null` when both `sr-Cyrl` and `sr-Latn` are installed.
4. No hit → move to the next candidate.

Return **`null`** when nothing matches. **Never substitute `en`** (§3 correction box) and do not apply the
`default_locale` fallback here — the caller does that in Phase 4. The matcher *must* be able to report "no
match", because that `null` is exactly the signal the missing-languages report (Phase 7) is built on.

#### Code aliasing — resolved against the real catalog, do not re-litigate

The 87 catalog keys were checked for codes that a browser could never match. Every three-letter key —
`ast`, `fil`, `kab`, `ckb`, `kmr`, `tok` — has **no** ISO 639-1 equivalent, so browsers send it verbatim and
exact matching already works. Exactly one is broken:

- **`uzb` (Uzbek) is non-ISO-639-1 and `uz` exists.** A browser sending `uz` / `uz-UZ` would match nothing.
  Add a one-entry alias applied *during normalization of both sides* — `['uzb' => 'uz']` — so installed `uzb`
  normalizes to `uz`, candidate `uz` matches it, and the returned key is still the original `uzb`. This is code
  equivalence, not a hardcoded locale list, and it is the only such case.

Deliberately **not** aliased:

- **`ku` → `ckb`/`kmr`** — `ku` is a macrolanguage and *both* Kurdish packs exist, so it is genuinely
  ambiguous. Declining is consistent with tier 3.
- **`no` → `nb`/`nn`** — same situation, and `no` is common in the wild. *Optional* improvement if you want it:
  feed a tiny macrolanguage map `['no' => ['nb','nn'], 'ku' => ['ckb','kmr']]` into **tier 3's existing
  "exactly one installed" logic** rather than inventing a new mechanism. Skip it if you prefer strict
  minimalism; it is not required for Phase 3 to be correct.
- **Legacy codes** `iw`/`in`/`ji`/`mo` — modern browsers send `he`/`id`/`yi`/`ro`. Not worth carrying.

#### Known gap, already covered elsewhere — do not build a script table

`zh-CN` truncates to `zh` (not installed), then tier 3 finds two `zh*` siblings and declines → `null`. Mapping
`zh-CN`→`zh-Hans` and `zh-TW`→`zh-Hant` would need a region→script table. **Don't add one:**
`resources/countries.php` already handles it via IP detection (`CN => ['zh-Hans', …]`, `TW => ['zh-Hant']`), so
the case is covered without a new mechanism.

Tests, with installed set `['en','tr','pt-BR','zh-Hans','zh-Hant','sr-Cyrl','sr-Latn','es_MX','uzb']`:
`['tr']`→`tr`; `['tr-TR']`→`tr`; `['TR']`→`tr`; `['pt-BR']`→`pt-BR`; `['pt-PT']`→`pt-BR`; `['pt']`→`pt-BR`;
`['es-MX']`→`es_MX`; `['es_MX']`→`es_MX`; `['zh-Hans-CN']`→`zh-Hans`; `['zh-CN']`→`null`; `['sr']`→`null`;
`['sr-Cyrl']`→`sr-Cyrl`; `['uz']`→`uzb`; `['uz-UZ']`→`uzb`; `['de','tr']`→`tr`; `[]`→`null`; `['xx-YY']`→`null`.
Plus the two that encode the decisions above:

- **Ordering:** installed `['en','pt']`, candidates `['pt-BR','en']` → **`pt`**, not `en`.
- **§3 correction:** installed `['tr']` only (a forum with `default_locale = tr`), candidates `['en']` →
  **`null`**. `en` is not a fallback.
- **Ambiguous `es`:** installed `['es_AR','es_MX']`, candidates `['es']` → `null` (two siblings).

### Verification available in this environment

There is **no PHP**, so nothing can be executed — no phpunit, no syntax check. CI (`.github/workflows/backend.yml`,
`enable_backend_testing: true`) is the only gate. **Never state or imply that the tests pass.** Say what was
written and that it is unverified until CI runs. Structural checks that *are* possible: UTF-8 without BOM, LF
endings, balanced braces, and StyleCI conformance by eye — the `recommended` preset with `align_double_arrow`,
`multiline_array_trailing_comma`, `new_with_braces` and `blank_line_after_opening_tag` disabled
(`.styleci.yml`).

Finish by adding a `CHANGELOG.md` line under `## [Unreleased] / ### Added`, then commit per the convention in
the status header above.

---

## 15. Phase 4 work order — apply & remember (**← START HERE**)

> Self-contained on purpose: a fresh session should be able to build Phase 4 from this section plus the four
> cross-references below, without re-doing discovery. Every API claim here was read out of
> `vendor/flarum/core` or `vendor/flarum/testing` at 1.8.19 on 2026-08-24.

**Read first, in this order:** §7 (middleware position, core's `SetLocale` source, **and the correction box** —
core never writes a `locale` cookie), §6 "Cookies", §10 (the five settings), §14 (the contracts of the two
Phase 3 classes you are about to consume).

**Goal:** the first phase that changes what a visitor actually sees. A guest arriving with
`Accept-Language: tr` gets the forum in Turkish if `tr` is installed; the decision is made **once** and
remembered; a visitor who has already chosen a language is never touched.

### Scope discipline — what Phase 4 must NOT touch

- **No IP lookup.** `IpCountryLookup`, `CountryLanguage`, `resources/ip4.dat`/`ip6.dat` and
  `scripts/build-ip-data.php` are all Phase 5. Consequence: the `detection_order` setting is **unobservable in
  Phase 4** — both values behave identically, because only one of the two sources exists. Do not write a test
  asserting `ip_browser` differs from `browser_ip`; do leave the seam described below so Phase 5 is an
  insertion rather than a rewrite.
- **No analytics, no database writes, no bot detection.** Phase 6 owns `language_detection_stats`,
  `Analytics`, `BotDetector` and the `flarum_language_detection_day` cookie. Phase 4 writes exactly one cookie
  (the locale memo) and, for a user with no locale preference, one `users.preferences` update.
- **No admin UI, no new `locale/*.yml` keys.** Phase 8. Phase 4 adds no user-visible text at all, so it needs
  no translations — which is also why it cannot violate the "no hardcoded visible strings" rule.
- **No forum JS bundle.** Phase 2 deleted it deliberately (§12 Phase 2 decision 1); everything here is
  server-side. Do not re-add `js/forum.ts`.
- **Do not change `BrowserLanguageParser` or `LocaleMatcher`.** §14 is their specification and Phases 5–7
  depend on those contracts. If Phase 4 seems to need a change there, that is a signal the logic belongs in
  the new code instead.

### Deliverables

```
src/LanguageDetector.php
src/Middleware/DetectLanguage.php
extend.php                            (add the Extend\Middleware block — the first project class referenced)
tests/unit/LanguageDetectorTest.php
tests/integration/DetectionTest.php
CHANGELOG.md
```

Namespaces `HuseyinFiliz\LanguageDetection\` and `HuseyinFiliz\LanguageDetection\Middleware\`; tests in
`HuseyinFiliz\LanguageDetection\Tests\Unit\` / `…\Tests\Integration\` (see §2 for the lowercase-directory
caveat). No other file changes.

### `LanguageDetector` — the resolution chain, testable without HTTP

Splitting this out of the middleware is what keeps the ordering rules unit-testable and gives Phase 5 one
obvious place to add IP detection.

```php
public function __construct(
    BrowserLanguageParser $parser,
    LocaleMatcher $matcher,
    SettingsRepositoryInterface $settings
) {}

/** @return string|null an exact installed locale key, or null to leave the locale alone */
public function detect(ServerRequestInterface $request): ?string;
```

1. Read the header with `$request->getHeaderLine('Accept-Language')`. Laminas returns `''` for an absent
   header (verified in `MessageTrait::getHeaderLine()`), and `parse('')` already returns `[]`, so no null check
   is needed — pass it straight through.
2. `$this->matcher->match($this->parser->parse($header))`. A non-null result is already an exact
   `getLocales()` key, so **no further validation is required or wanted** — the matcher is the validator, and
   re-checking with `hasLocale()` would only hide a regression.
3. On `null`, fall back to the `huseyinfiliz-language-detection.default_locale` setting when it is non-empty
   **and** `hasLocale()` accepts it. (§10: empty string means "use the forum default", i.e. return `null` and
   let core do nothing.) This needs `LocaleManager` too — inject it, or keep the fallback in the middleware;
   either is fine, but decide once and say so in the commit message.
4. Otherwise `null`. **Never substitute `en`** — see the §3 correction box; `en` is not guaranteed installed.

**Phase 5 seam:** read `detection_order` here and structure step 2 as an ordered list of sources rather than a
straight-line call, so Phase 5 adds a second source instead of restructuring. Keep it honest — a one-element
list is fine; do not build a driver registry for it.

`SettingsRepositoryInterface` has exactly four methods (`all`, `get`, `set`, `delete`), so a ~10-line in-test
fake is preferable to a Mockery double, matching the Phase 3 decision to use a real `LocaleManager`. Note there
is **no `MemorySettingsRepository` in core 1.8** — do not reach for one.

### `Middleware/DetectLanguage`

Implements `Psr\Http\Server\MiddlewareInterface`. Registered as:

```php
(new Extend\Middleware('forum'))
    ->insertBefore(\Flarum\Http\Middleware\SetLocale::class, Middleware\DetectLanguage::class),
```

Middleware are resolved with `$container->make($middleware)` (`Forum\ForumServiceProvider:96`), so plain
constructor injection of `LocaleManager`, `LanguageDetector` and `CookieFactory` works with no binding.

Flow, in order — every branch must `return $handler->handle($request)`:

1. **`GET` only.** `if ($request->getMethod() !== 'GET') { … }`. The forum stack also carries form POSTs;
   detection has no business there.
2. **Authenticated actor** (`RequestUtil::getActor($request)`, `$actor->exists`):
   - `$actor->getPreference('locale')` is non-null → **do nothing at all.** No cookie, no write, no
     `setLocale()`. This is the critical rule (§7, spec §15) and deserves its own named test.
   - Otherwise detect; on a hit, `$actor->setPreference('locale', $detected)` + `$actor->save()`, and
     `$this->locales->setLocale($detected)` so *this* request is already translated.
3. **Guest:** read our own cookie —
   `Arr::get($request->getCookieParams(), $this->cookies->getName('language_detection_locale'))`. Present and
   `hasLocale()` → `setLocale()` and stop; detection does not run again. This is the "at most once per
   visitor" rule from §6.
4. **Guest, no cookie:** detect. On `null`, do nothing (core's default locale stands). On a hit,
   `setLocale($detected)`, then set the cookie **on the response**:
   `FigResponseCookies::set($response, $this->cookies->make('language_detection_locale', $detected, 60 * 60 * 24 * 365))`.
   Cookie name on the wire is `flarum_language_detection_locale` (§6); `CookieFactory` supplies prefix, path,
   domain, secure and HttpOnly.

`setLocale()` needs no input guard — §13 risk 6 is closed: Symfony's `assertValidLocale()` is
`preg_match('/^[a-z0-9@_\.\-]*$/i', …)`, so `es_MX` and `zh-Hans` pass.

**Why this ordering is safe:** core's `SetLocale` runs immediately after us and, per §7, only overrides when an
explicit signal exists (`getPreference('locale')` for users, an unprefixed `locale` cookie for guests). For a
user we only ever write the preference when it was empty, so we can never lose a manual choice; for a guest
core finds no cookie of its own and leaves our value alone, then propagates it via
`withAttribute('locale', $this->locales->getLocale())`. A third-party language switcher that *does* write an
unprefixed `locale` cookie still wins over us, for free.

### Tests

**Unit (`LanguageDetectorTest`)** — a `ServerRequest` with a header plus the settings fake: `tr` header on a
forum with `tr` installed → `tr`; unmatched header with `default_locale = tr` → `tr`; unmatched header with
`default_locale = ''` → `null`; `default_locale` set to a *not installed* code → `null`; no header at all →
falls through the same path as an unmatched one.

**Integration (`DetectionTest`)** — extends `Flarum\Testing\integration\TestCase`, and in `setUp()`:
`$this->extension('huseyinfiliz-language-detection')` (the ID is the composer name with `/` → `-`). Two
verified traps, both of which will silently produce a passing-but-meaningless test if ignored:

- **`request()` has no header option.** Its `$options` are only `json`, `authenticatedAs` and `cookiesFrom`.
  Set the header by chaining: `$this->request('GET', '/')->withHeader('Accept-Language', 'tr')`.
- **`authenticatedAs` and `cookiesFrom` cannot be combined in one `request()` call.** `authenticatedAs` →
  `requestAsUser()`, which authenticates via the **`flarum_remember` cookie** (it inserts a `session_remember`
  access token and calls `withCookieParams([...])`) — *not* an `Authorization` header, despite what the
  docblock on `request()` says. `cookiesFrom` is applied afterwards and calls `withCookieParams()` again,
  **replacing** the array, so the remember cookie is dropped and the "user" is silently a guest. For a
  two-request authenticated scenario, merge by hand:
  `$req->withCookieParams(array_merge($req->getCookieParams(), $cookiesFromPrevious))`.

  The upside of cookie-based auth: `RememberFromCookie` → `AuthenticateWithSession` both run *before*
  `SetLocale`, so the actor genuinely is resolved by the time our middleware sees the request on a plain forum
  `GET`. (The forum stack has no `AuthenticateWithHeader` — that is API-only.)

Cases to cover: guest sends `tr` on a forum with Turkish installed → response is Turkish and carries
`Set-Cookie: flarum_language_detection_locale=tr`; second request via `cookiesFrom` → still Turkish and
detection did not re-run; guest sends an uninstalled language → forum default, and (Phase 6 will assert the
stats row, not this phase). **A user with `locale` already set is never overwritten** — assert the preference
value is unchanged *and* that the response is in their chosen language, not the header's. A user with no
preference gets one written. Seed users with `prepareDatabase(['users' => [...]])`; seed settings with
`prepareDatabase(['settings' => [['key' => …, 'value' => …]]])` (`populateDatabase()` special-cases the
`settings` table to upsert on `key`).

### Verification available in this environment

Unchanged from §14 and still binding: **there is no PHP here**, so nothing can be executed — no phpunit, no
`php -l`. CI (`.github/workflows/backend.yml`, `enable_backend_testing: true`) is the only gate. **Never state
or imply that the tests pass.** Report what was written and that it is unverified until CI runs. Integration
tests additionally need MySQL, so they will not run anywhere but CI regardless. Structural checks that *are*
possible: UTF-8 without BOM, LF endings, no tabs, no trailing whitespace, final newline, balanced
braces/parens, `! ` spacing, and StyleCI conformance by eye against `.styleci.yml`.

Finish by adding a `CHANGELOG.md` line under `## [Unreleased] / ### Added`, then commit per the convention in
the status header above.
