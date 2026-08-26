# Handoff — `huseyinfiliz/language-detection` (Flarum 1.x)

> **Status: Phases 1–8 are complete. Phase 9 (cleanup command and admin action) is next — its full work order
> is §20; start there.** §6 carries the data model, §17 is now the specification of the counting that fills it,
> §18 is the specification of the missing-languages report, §19 is the specification of the dashboard that
> renders both, and §14 is the specification of the matcher all of those defer to.
> This file exists so a fresh session can resume without re-doing discovery.
> It is a working document — remove it (or gitignore it) before release.
>
> **Repository state, 2026-08-26:** branch `1.x`, pushed to `origin/1.x` through Phase 4 (`cb43798`);
> Phase 5 (`b5a7567`), Phase 6 (`875e7eb`), Phase 7 (`e56150e`) and Phase 8 (`52d800f` backend, `d033594`
> frontend) are committed locally and still need pushing.
> Phase 5 adds `src/IpCountryLookup.php`, `src/CountryLanguage.php`, `scripts/build-ip-data.php`, the
> generated `resources/ip4.dat` / `ip6.dat` / `ip-data.php`, two new unit test files and the `.dat` fixtures —
> **2.2 MB of committed binary data**, which is the first push where that matters. It is also the first phase
> whose behaviour `detection_order` can actually change, so CI now exercises both orders.
> Phase 6 adds `src/Analytics.php`, `src/BotDetector.php`, three new test files and the counting hook in
> `Middleware/DetectLanguage.php`. It is the first phase whose core behaviour is *database* behaviour, so it is
> also the first where CI's MySQL run — not local reading — is the only thing that can confirm the feature
> works at all.
> Phase 7 adds `src/LanguageCatalog.php` and two test files, and touches nothing that already existed except
> `CHANGELOG.md`. It is read-only: `SELECT` and nothing else, no settings, no registration in `extend.php`.
> Phase 8 adds `src/Statistics.php`, three files under `src/Api/`, `src/Content/AdminPayload.php`, two test
> files, ten modules under `js/src/admin/`, the whole of `less/admin.less` and 52 locale keys per language.
> **It is the first phase whose deliverable cannot be verified by CI at all** (§13 risk 10): the tracked
> `js/dist/admin.js` is still the scaffolding's bundle of an empty initializer, nothing in CI builds one, and
> **the admin page will not appear in any browser until someone runs `yarn build` in `js/` and commits the
> result.** Do that before release, or before showing the page to anybody.
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

### Test infrastructure (unit tests from Phases 3–4; the first integration test landed in Phase 4)

| Path | Notes |
|---|---|
| `tests/phpunit.unit.xml` | suite is `./unit`, suffix `Test.php`; registers Mockery's `TestListener`, so **Mockery is available**. Needs no `bootstrap` attribute — it is byte-for-byte flarum/testing's own unit config, and `vendor/bin/phpunit` loads the autoloader itself |
| `tests/phpunit.integration.xml` + `tests/integration/setup.php` | integration suite; needs a real database (`composer test:setup`, run once), so it effectively only runs in CI. `processIsolation="true"`, so each test method gets a fresh process and no singleton leaks between methods |
| `tests/unit/BrowserLanguageParserTest.php`, `tests/unit/LocaleMatcherTest.php`, `tests/unit/LanguageDetectorTest.php`, `tests/unit/IpCountryLookupTest.php`, `tests/unit/CountryLanguageTest.php` | Phases 3–5; base class `Flarum\Testing\unit\TestCase` (lowercase `unit` namespace segment, as the vendored class declares it) |
| `tests/integration/DetectionTest.php` | Phases 4–5; base class `Flarum\Testing\integration\TestCase`, which defines only `tearDown()` — `parent::setUp()` resolves to PHPUnit's own empty one. **Boots on the first `send()`, not in `setUp()`** — see §12 Phase 5 decision 8 |
| `tests/fixtures/ip-dataset/{ip4.dat,ip6.dat,ip-data.php}` | Phase 5's hand-built dataset, 36 and 60 bytes; documented as an ASCII table in `IpCountryLookupTest`'s class docblock. The directory name is fixed by `IpCountryLookup`'s filenames. `tests/fixtures/.gitkeep` is gone |

`composer.json` has `require-dev: flarum/testing ^1.0.0` and maps `autoload-dev` PSR-4
`HuseyinFiliz\LanguageDetection\Tests\` → `tests/`. Scripts: `composer test:unit`, `test:integration`, `test`.

**Namespace convention:** `tests/unit/FooTest.php` declares `HuseyinFiliz\LanguageDetection\Tests\Unit\FooTest`
— capital `Unit` against a lowercase `unit/` directory. Both `fof/badges` and the author's
`huseyinfiliz/awards` do exactly this; it works because PHPUnit `require`s each discovered file directly, so
PSR-4 is never asked to resolve the class. **Consequence:** a shared helper or fixture *class* placed in
`tests/unit/` would fail to autoload on case-sensitive Linux CI. Keep test classes self-contained, or put
shared fixtures in `tests/fixtures/` and require them explicitly. Phase 4 took the first route: its
`SettingsStub` is declared in `tests/unit/LanguageDetectorTest.php` itself, which loads because PHPUnit
`require`s the whole file.

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

> **Superseded in Phase 5 — the signature is `countryFor(ServerRequestInterface $request): ?string`**, with
> `countryForIp(?string $ip): ?string` for the dataset half alone (§12 Phase 5 decision 1). The *order* below is
> exactly what was implemented, with one clarification: steps 1 and 2 are reversed in effect, because an edge
> header is trusted even when the connecting address is private — it is the edge's own verdict about a visitor
> whose address never reached us (§12 Phase 5 decision 2).

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

> **Measured in Phase 5: the estimate is inverted.** `ip4.dat` is 900,168 bytes (150,028 records) and `ip6.dat`
> is 1,360,030 bytes (136,003 records) — 2.2 MB in total, but IPv6 is the larger file, because gap records
> outnumber allocations in a sparsely-delegated address space. See §12 Phase 5 for the full figures.

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

> **Corrected in Phase 6's work order — the upsert needs no raw SQL string.** `Builder::upsert()` exists at the
> locked `illuminate/database` v8.83.27 and a string-keyed `$update` entry whose value is a `Query\Expression`
> compiles to `` `requests` = requests + 1 `` with the expression stripped from the bindings. §17 has the
> verified grammar excerpt and the four traps that come with it. The rest of this paragraph stands.

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
  ip-data.php                      (generated sidecar: build date + record counts; Phase 5)
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
tests/unit/{BrowserLanguageParserTest,LocaleMatcherTest,LanguageDetectorTest,IpCountryLookupTest,
            CountryLanguageTest,BotDetectorTest}.php
tests/fixtures/ip-dataset/{ip4.dat,ip6.dat,ip-data.php}   (hand-built; Phase 5)
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

**Phase 4 — Apply & remember. ✅ DONE.** Work order in §15, followed as written.
Built: `src/LanguageDetector.php`, `src/Middleware/DetectLanguage.php`,
`tests/unit/LanguageDetectorTest.php` (9 tests), `tests/integration/DetectionTest.php` (7 tests), plus the
`Extend\Middleware` block in `extend.php` — the first project class this extension registers, so this is also
the first commit where CI boots the extension and runs the migration.

Decisions taken during Phase 4:

1. **`LocaleManager` is injected into `LanguageDetector`** as a fourth constructor argument, so the
   `default_locale` fallback lives with the rest of the chain instead of in the middleware. §15 allowed either;
   this keeps the whole of "what locale should this request use" in one class that needs no HTTP and no
   container to test.
2. **The Phase 5 seam is an ordered list intersected with a `SOURCES` constant** — `SOURCE_BROWSER` and
   `SOURCE_IP` exist as constants, `SOURCES` lists only the browser, and `array_intersect` (which preserves its
   first argument's order) filters the `detection_order` preference down to what is implemented. Phase 5 adds
   `SOURCE_IP` to `SOURCES` and one branch to `fromSource()`. No driver registry, per §15.
3. **The guest memo is validated on read, not just on write.** A cookie value that is not in `getLocales()` is
   discarded and detection re-runs. That covers a tampered cookie and a language pack removed since the memo
   was written, and it is the reason an arbitrary cookie value can never reach `setLocale()`.
4. **A `null` detection writes nothing** — no cookie for a guest, no preference for a user. So a visitor whose
   language is installed *later* is picked up on their next page view, instead of being permanently remembered
   as "undetectable".
5. **The integration test registers its locales on the `LocaleManager` singleton** rather than through
   `Extend\LanguagePack`, which needs a real installed `Extension` instance and so is unusable in tests. The
   test forum registers only its `default_locale`, and the added locales carry no translations, so every
   assertion is on the rendered `<html lang>` attribute (`Frontend\Content\Meta` sets `$document->language`
   from `LocaleManager::getLocale()`; `views/frontend/app.blade.php` renders it) rather than on translated
   text.
6. **Two requests in one integration test cannot both be trusted for `lang`.** `LocaleManager` is a container
   singleton and nothing resets the translator between `send()` calls, so a second response's `lang` reads `tr`
   even if the middleware did nothing. The round-trip test therefore asserts on the **absent second
   `Set-Cookie`** — which is what actually proves the cookie is read back under the name it was written with —
   and a separate test applies a memo from a clean start with no `Accept-Language` at all.
7. **No POST/GET-guard test.** The `GET` guard is real and worth keeping, but it cannot be observed from the
   integration harness: `CheckCsrfToken` sits *inside* our middleware and throws before the handler returns, so
   no cookie appears on a POST whether the guard exists or not. A test that cannot fail was not written.

**Verification actually performed:** the PHP was **not executed** — there is still no PHP in this environment,
so both test files are **unverified until CI runs**, and the integration suite additionally needs MySQL. What
was done: every API the new code touches was re-read in `vendor/` (`SetLocale`, `CookieFactory`,
`RequestUtil`, `User::getPreference`/`setPreference`, `Extend\Middleware`, `SettingsRepositoryInterface`,
`Frontend\FrontendServiceProvider:60`, `app.blade.php`, and the whole of `flarum/testing`'s integration
`TestCase` / `BuildsHttpRequests` / `RetrievesAuthorizedUsers`), and the structural checks from §15 all pass:
UTF-8 without BOM, LF endings, no tabs, no trailing whitespace, final newline, balanced braces/parens, `! `
spacing, alphabetical imports.

**Phase 5 — Standalone IP lookup. ✅ DONE.** Work order in §16, followed with three deviations (decisions 1, 7
and 8 below).
Built: `src/IpCountryLookup.php`, `src/CountryLanguage.php`, `scripts/build-ip-data.php`, the generated
`resources/ip4.dat` / `resources/ip6.dat` / `resources/ip-data.php`, `tests/unit/IpCountryLookupTest.php`
(18 tests), `tests/unit/CountryLanguageTest.php` (8 tests), `tests/fixtures/ip-dataset/{ip4.dat,ip6.dat,ip-data.php}`,
plus the seam in `src/LanguageDetector.php`, 7 new tests in `tests/unit/LanguageDetectorTest.php`, 6 new tests in
`tests/integration/DetectionTest.php`, and the `.dat` rules in `.gitattributes`. `extend.php` is **unchanged** —
see decision 3.

Decisions taken during Phase 5:

1. **`countryFor()` takes the request, not the IP** — `countryFor(ServerRequestInterface $request): ?string`,
   with a second `countryForIp(?string $ip): ?string` for the dataset path alone. §16 left the signature as a
   judgement call. One argument won because the edge headers, the server params and the `ipAddress` attribute
   all live on the request, so a two-argument form would have let a caller pass an address that disagreed with
   the request it came from. `countryForIp()` exists because it is the half worth testing in isolation, and
   because it is the half with no HTTP in it.
2. **Edge headers are read *before* the address, not as a fallback.** §3 already ordered them this way and Phase
   5 confirms why it is not merely an optimisation: Flarum 1.x honours no `X-Forwarded-For`, so behind a CDN
   `ipAddress` is the CDN's own address, and a dataset lookup on it would return the *CDN's* country with total
   confidence. The header is the only thing that knows where the visitor is. Consequence, deliberately: a
   spoofed header beats a real address. Documented in the class, and the stakes are unchanged from
   `Accept-Language`, which a visitor can already set to anything.
3. **No container binding was needed, so `extend.php` was not touched.** Both new classes take a single
   `?string $directory = null` / `?string $path = null` constructor argument, and Laravel's
   `resolvePrimitive()` fills an unresolvable primitive from its default rather than throwing, so
   `$container->make(LanguageDetector::class)` autowires the whole 6-argument chain as-is. That nullable
   argument is also the test seam — no `Extend\ServiceProvider`, no bind closure, nothing to keep in sync.
4. **Keys are compared as raw big-endian bytes with `strcmp()`, never unpacked.** §16 flagged the trap and it is
   real, but be precise about its status: **no key in the shipped `ip6.dat` is at or above `8000::`** (the
   highest is `2c0f:fff1::`), and every address that high today is in `fc00::/7` or `fe80::/10` and is rejected
   by the private/reserved filter before the search runs. So the bug is *latent*, not live — which is exactly
   why `tests/fixtures/ip-dataset/ip6.dat` carries a synthetic `9000:: => TR` record and
   `test_an_ipv6_address_above_8000_is_not_read_as_negative` asserts on it. Any future dataset that does reach
   that high finds the test already in place.
5. **IPv4-mapped IPv6 is unwrapped *before* the private/reserved filter.** `::ffff:0:0/96` is itself a reserved
   prefix, so filtering first would discard every dual-stack visitor whose address arrives in mapped form.
   Unwrapping first is also what makes `::ffff:192.168.1.1` recognisably private rather than merely reserved.
6. **`\0\0` and "no country in the map" are the same answer to the caller.** `fromIp()` returns `null` for an
   unknown range, an unmapped country, and a country whose languages are not installed, without distinguishing
   them — they all mean "this source has no opinion, run the next one". Phase 7's missing-languages report is
   the place that will care about the difference, and it will have the country code to work from.
7. **Deviation — fixtures live in `tests/fixtures/ip-dataset/`, not as `ip4-test.dat` / `ip6-test.dat`.**
   §16's flat filenames are unreachable: the seam is the *directory*, and `FILE_V4` / `FILE_V6` / `FILE_INFO`
   are fixed names inside it. A directory of correctly-named files also makes two more paths testable — the
   sidecar (`datasetInfo()`) and a missing dataset (point the constructor at a directory that does not exist).
   `tests/fixtures/.gitkeep` was deleted, as §16 anticipated.
8. **Deviation — the integration suite's boot moved out of `setUp()`.** `setting()` is seeded through
   `SetSettingsBeforeBoot` and is a documented no-op once the app is booted, and Phase 4's `setUp()` called
   `$this->localeManager()`, which boots. So the one test §16 called "the only assertion that `detection_order`
   does anything at all" could not have worked as written. Fixed by moving the `forumDefault` read and the two
   `addLocale()` calls into an overridden `send()` behind a `$booted` flag: boot now happens on the first
   request, after any `setting()` call. **All 7 pre-existing tests are byte-identical** — the change is in the
   harness around them.
9. **`REMOTE_ADDR` cannot be set on an integration request, so the dataset path is untested at the HTTP level.**
   `request()` builds `new ServerRequest([], [], $path, $method)` with empty server params, and PSR-7 has no
   `withServerParams()` — so `ProcessIp` always yields its `127.0.0.1` default and no integration test can
   exercise a real address. §16 asked only for the edge-header path, which is fortunate rather than lucky. The
   dataset path is covered at the unit level, where the request is built by hand; the `XX` test documents the
   `127.0.0.1` consequence explicitly so nobody later reads it as a dataset assertion.
10. **The `.dat` files are marked `binary` in `.gitattributes`, and the block sits *after* the `text=auto`
    catch-all** — the last matching line wins, and the first attempt at this put the block above it, where
    `git check-attr` duly reported `text: auto`. Git's own NUL heuristic would have reached the same conclusion,
    but a fixed-offset record boundary is not a thing to leave to a heuristic in a repo with
    `core.autocrlf=true`. Verified after the fix: `.dat` files report `text: unset, diff: unset`, `.php` files
    still report `text: auto`, and every staged `.dat` blob is byte-identical to the file on disk
    (`git hash-object --no-filters` matches `git rev-parse :<path>`).
11. **A wrong test, not wrong data.** The `CountryLanguageTest` shape sweep initially flagged
    `AR => ['es_AR','es']` and `MX => ['es_MX','es']` as malformed. `src/LocaleMatcher.php`'s own docblock
    already records that Flarum publishes those two pack codes with underscores where `pt-BR` uses a hyphen, and
    that `normalize()` folds the separator on both sides. The regex was corrected to accept either separator;
    `resources/countries.php` was **not** touched. Worth stating because the tempting fix was the wrong one.

**Dataset facts** (the generated files, not the code): `ip4.dat` is 900,168 bytes / 150,028 records / 7,558
`\0\0` gap records / 239 countries, first record `0.0.0.0 => \0\0`, last `224.0.0.0 => \0\0`. `ip6.dat` is
1,360,030 bytes / 136,003 records / 67,297 gaps / 232 countries, first `:: => \0\0`, last
`2c0f:fff1:: => \0\0`. **§3's and §16's size estimates are inverted relative to reality** — they predicted
~1.4 MB for IPv4 and ~0.9 MB for IPv6; it is the other way round, and 2.2 MB in total rather than 2.3 MB. The
sidecar records `built`/`data_date` `2026-08-24`, the five registries, and both record counts.

**Verification actually performed:** the PHP was **not executed** — there is still no PHP in this environment,
so all four touched test files and all three new source files are **unverified until CI runs**, and the
integration suite additionally needs MySQL. The `.dat` files, however, are *data*, and data can be checked here.
What was done, with a throwaway perl mirror of the reader (never committed):

- **All nine §16 spot-check pairs pass**, plus three more: `8.8.8.8`→`US`, `212.156.4.1`→`TR`, an APNIC
  range→`CN`, `2606:4700::`→`US`, `2a01:4f8::1`→`DE`, `::ffff:8.8.8.8`→`US` (unwrapping), and both edges of both
  files.
- **A full sweep of both files** proved every record byte-aligned, keys strictly ascending with no duplicates,
  no two adjacent records carrying the same country (the merge step works), and no malformed country codes.
- **Both fixtures verified by hex dump** against the ASCII table in `IpCountryLookupTest`'s class docblock.
- Structural checks as in earlier phases: UTF-8 without BOM, LF endings, no tabs, no trailing whitespace, final
  newline, `! ` spacing per `.styleci.yml`, alphabetical imports.

None of that says anything about the PHP. The perl mirror agreeing with the perl-built data proves the
*algorithm and the dataset*, not one line of `IpCountryLookup.php` — and `scripts/build-ip-data.php` remains
entirely unrun (§13 risk 1).

**Phase 6 — Analytics. ✅ DONE.** Work order in §17, followed with one deviation (decision 1 below).
Built: `src/Analytics.php`, `src/BotDetector.php`, `tests/unit/AnalyticsTest.php` (21 tests),
`tests/unit/BotDetectorTest.php` (5 test methods, 18 cases once its two data providers expand),
`tests/integration/StatisticsTest.php` (15 tests), plus the counting hook in `src/Middleware/DetectLanguage.php`.
`extend.php` is **unchanged** — see decision 2.

Decisions taken during Phase 6:

1. **Deviation — `record()` takes the clock as a third argument.** §17 sketched
   `record(ServerRequestInterface $request, bool $isNewVisitor): bool` and explicitly left the shape to
   judgement; the signature shipped is `record(ServerRequestInterface, bool, Carbon)`. The reason is ownership,
   not style: `'flarum.forum.handler'` is a container **singleton** (*verified* —
   `vendor/flarum/core/src/Forum/ForumServiceProvider.php:92-103`, whose middleware are `$container->make()`d
   once per booted app), so `DetectLanguage` is built once and reused for every request through that process. A
   `Carbon` memoised on `Analytics` would freeze at the first request's time and stay there — visible in the
   integration suite, where several `send()` calls share one instance, and fatal in a long-lived process, where
   views would accumulate against a date that has already passed. Reading `Carbon::now()` once per `process()`
   and passing it down is also what makes §17's midnight requirement true *by construction* rather than by
   discipline: the row's `date` and the cookie's value are the same string because they are the same object.
2. **`extend.php` needed no change, as §17 predicted.** `Illuminate\Database\ConnectionInterface` — note the
   namespace: `Illuminate\Database`, **not** `Illuminate\Contracts\Database`, which has no such file in this
   vendor tree — is a container singleton with `db.connection` / `flarum.db` aliases, so the whole five-argument
   `Analytics` and the now four-argument middleware autowire as they stand.
3. **The over-long tag is skipped in *preference order*, not simply dropped.** §17 says "skip any tag longer
   than 20 bytes", which leaves open what to record instead. `requestedLocale()` walks the parsed tags in the
   order the parser returns them (most-preferred first) and takes the first whose normalised form fits, so
   `zh-Hans-CN-x-aaaaaaaa,tr;q=0.9` records `tr` — a real language the visitor really asked for — rather than
   `''`. Only a header in which *every* tag is over-long records nothing.
4. **The bare `bot` token was kept, and the CUBOT false positive is asserted deliberately.** §17 offered either
   choice provided the tradeoff was stated. Kept because the trade is asymmetric in a way that decides it: both
   directions distort an already approximate statistic, but under-counting leaves an admin with a number that is
   a little low, while over-counting hands them inflated traffic figures they might *act* on — installing a
   language pack for readers who were never there. `test_a_phone_whose_brand_name_contains_bot_is_counted_as_one`
   asserts `true` on a real CUBOT NOTE 20 UA so that nobody later reads the behaviour as an oversight.
5. **`AnalyticsTest` was written, and it asserts the upsert arguments after all.** §17 called the file optional
   and advised skipping SQL assertions because "asserting that with a mocked `ConnectionInterface` tests the
   mock's expectations, not the SQL". That is right about the *statement* and wrong about the *arguments*: three
   of §17's own four traps are visible in the arguments alone and are silent failures if got wrong — a
   numeric-keyed `$update` compiles to `values(col)` and would make every view write `requests = 1`; a missing
   timestamp leaves every row with nulls; a bound `unique_visitors + ?` has no placeholder to bind to. So a spy
   captures `[$values, $uniqueBy, $update]` and the tests pin those three. What is *not* asserted here, per §17,
   is any claim about what MySQL does with them.
6. **"Nothing was written" is asserted with an expectation-free double.** Mockery throws
   `BadMethodCallException` on any call to a mock with no expectations, so
   `Mockery::mock(ConnectionInterface::class)` *is* the assertion for the analytics-off and bot-ignored paths.
   No query counting, and no way for a stray write to pass quietly.
7. **`AnalyticsTest` mocks the settings repository instead of reusing `SettingsStub`.** Not a preference:
   `LanguageDetectorTest` declares `SettingsStub` in the same namespace, and **neither `tests/unit/` nor
   `tests/integration/` is autoloadable** — `autoload-dev` maps `HuseyinFiliz\LanguageDetection\Tests\` →
   `tests/`, while the namespace segments are `Unit`/`Integration` and the directories are `unit`/`integration`.
   PHPUnit loads each test file whole, so a second declaration would be a fatal error, and one test class cannot
   reference another's constants. That last part is why `StatisticsTest` spells out
   `flarum_language_detection_locale` rather than reading `DetectionTest::COOKIE`, which would have resolved
   only by accident on a case-insensitive filesystem and broken on CI's Linux.
8. **The second-view integration test resends `Accept-Language`.** Obvious in hindsight and easy to get wrong:
   the row is keyed on `(date, locale, country_code)`, so a second request without the header would record the
   `''` locale, land on a *different* row, and prove nothing about incrementing. §17's warning about
   `DetectionTest`'s `lang` caveat (§12 Phase 4 decision 6) does not apply here — these are row assertions —
   but the keying trap is adjacent and worth the same care.
9. **`COOKIE` renamed to `LOCALE_COOKIE`** now that the middleware owns two cookies. Verified safe: no external
   reference exists — `extend.php` names only `DetectLanguage::class`, and `DetectionTest` carries its own
   literal. The name on the wire is unchanged.
10. **The POST test targets a route that exists.** The first draft sent `POST /`, which cannot fail: with no
    route to resolve, the exception unwinds past this middleware before a response exists, so nothing is counted
    whether the `GET` guard is there or not — exactly the reasoning that made Phase 4 decline its own POST test
    (§12 Phase 4 decision 7). Rewritten to `POST /global-logout` with `authenticatedAs`, which is a real forum
    route (*verified* — `vendor/flarum/core/src/Forum/routes.php:52`) whose controller returns an
    `EmptyResponse`, and `requestAsUser()` sets `bypassCsrfToken` (*verified* — `BuildsHttpRequests:56`), so the
    request completes normally. Remove the guard and the test genuinely writes a row.
11. **A wrong reason, corrected before commit.** `StatisticsTest`'s `today()`/`yesterday()` helpers call
    `$this->app()` before reading `Carbon::now()`, and their first docblock justified it with
    `Foundation\Site.php:25`'s `date_default_timezone_set('UTC')` "on boot". That is not what happens: the call
    is in `Site::fromPaths()`, which the web entry point uses and which the integration harness never calls —
    it builds `InstalledSite` directly (*verified* — `flarum/testing`'s `TestCase::app()`). The helpers were
    kept, because reading the date through the same clock the middleware reads is right regardless and the
    `app()` call costs nothing, but the docblock now says the true reason instead of a plausible one.

**Verification actually performed:** the PHP was **not executed** — there is still no PHP, composer, node or
npm in this environment, so all three new test files and both new source files are **unverified until CI runs**.
Beyond that, and this is the point worth stating plainly: **Phase 6's central mechanism cannot be verified here
at all.** That the unique index collapses repeat views onto one row rather than accumulating near-duplicates,
and that `on duplicate key update` increments what is already there atomically under concurrency, are facts
about MySQL. They are unexercised until `tests/integration/StatisticsTest.php` runs against a real MySQL in CI,
and no amount of local reading substitutes. Unlike Phase 5 — where the `.dat` files were *data* and a perl
mirror could check them — there is no local stand-in for a database.

What was done instead: every vendor API the new code touches was re-read at the locked versions.
`Builder::upsert()` (`Query/Builder.php:3116`: `$update === []` → plain insert; `is_null($update)` →
`array_keys(reset($values))`), `MySqlGrammar::compileUpsert()` (line 188 — ignores `$uniqueBy`, and the
`is_numeric($key)` branch that makes the numeric-key form emit `values()`), `Grammar::parameter()` (returns an
`Expression`'s value verbatim, so raw SQL is inlined with no placeholder), `Builder::cleanBindings()` (line
3369 — rejects every `Expression`), and `Connection::prepareBindings()` (line 630 — formats a `DateTimeInterface`
through the grammar's date format, which is what makes passing `Carbon` for the timestamps correct rather than
merely convenient). Also re-verified: `FigResponseCookies::set()` composes through `SetCookies`, which is keyed
by cookie name, so adding the day cookie to a response already carrying the locale cookie preserves both.
Structural checks as in every earlier phase: UTF-8 without BOM, LF endings, no tabs, no trailing whitespace,
final newline, balanced braces/parens/brackets, `! ` spacing per `.styleci.yml`, alphabetical imports.

**Phase 7 — Missing languages. ✅ DONE.** Work order in §18, followed with three deviations (decisions 1, 2
and 3 below). Built: `src/LanguageCatalog.php`, `tests/unit/LanguageCatalogTest.php` (20 tests),
`tests/integration/MissingLanguagesTest.php` (12 tests), plus a `CHANGELOG.md` line. Neither file uses a data
provider, so those are case counts as well as method counts. **Nothing that already existed was touched** —
`extend.php` unchanged, no migration, no settings, no locale keys, and `SELECT` only.

Decisions taken during Phase 7:

1. **Deviation — `LocaleManager` dropped from the constructor.** §18 sketched
   `__construct(LocaleMatcher, LocaleManager, ConnectionInterface, ?string $path = null)`; the class ships with
   three arguments and no locale manager. It would have been dead weight: the only question this phase asks of
   the installed set is *"is this tag served?"*, and that is `LocaleMatcher::match()` in its entirety — the
   matcher already holds the manager. Marking *which catalog entries are installed* is a different feature and
   belongs to Phase 8's languages table, which will need the manager itself. Autowiring is unaffected (decision
   4).
2. **Deviation — a public `keyFor()` beyond §18's `entryFor()`.** §18 specified only "entry for a code".
   `missingFrom()` needs the catalog *key* as the group identifier, not the entry: two tags belong in one row
   when they resolve to the same pack, and comparing entry arrays to decide that would be both slower and
   ambiguous (nothing stops two catalog rows from sharing a name). `entryFor()` is now a thin wrapper over
   `keyFor()`, and the key doubles as the `locale` a row reports.
3. **Deviation — `missingFrom()` takes an associative map.** §18 sketched `array<string, int[]>`. Shipped as
   `array<string, array{requests: int, visitors: int}>`, because the positional form makes `$volume[0]` /
   `$volume[1]` the reader's problem at every use site, and getting them the wrong way round is a silent bug
   that swaps two plausible-looking numbers in an admin's table.
4. **`extend.php` needed no change, as §18 predicted — and the integration test proves it rather than
   assuming it.** Every `MissingLanguagesTest` case resolves the class with `$container->make()`, so if the
   nullable `?string $path` ever stopped autowiring, twelve tests fail with a clear reason. (Laravel's
   `resolvePrimitive()` fills an unresolvable primitive from its default; that is the mechanism `CountryLanguage`
   already relies on.)
5. **Each row carries the `tags` it was built from.** Not in §18. The roll-up is the report's most opinionated
   step — `de`, `de-DE` and `de-AT` become one row — and a number an admin cannot decompose is a number they
   have to take on trust. Sorted, so the integration assertion is not at the mercy of MySQL's row order.
6. **A row's `locale` is the catalog key when one was found and the requested tag when not.** Which means the
   column is *usually* installable-as-spelled (`pt-BR`, `es_AR`, `zh-Hans` — exactly the string an admin types)
   but not always. Phase 8 must not treat it as a package coordinate; the `package` field is the only thing
   that is one, and it is nullable.
7. **`keyFor()` has no unambiguous-sibling tier, deliberately.** `LocaleMatcher` has one and it is right there,
   because a forum's installed set is small and a lone `nb` really is the only thing that could answer `no`. The
   catalog is the opposite: every variant Flarum publishes is in it, so `sr` would choose between `sr-Cyrl` and
   `sr-Latn` and `zh` between `zh-Hans` and `zh-Hant`, *every time*, forever. Picking one would be a guess
   presented to an admin as a recommendation. Null therefore means two things — no pack exists (`sw`, `zu`) or
   several could (`no`, `ku`, `sr`, `zh`) — and both are reported with the package column blank rather than
   dropped, because suppressing demand nobody can act on yet would make every total on the page smaller than
   the truth. **Phase 8 owes both cases a display string** (decision 11).
8. **The `zh-cn` gap is pinned, not patched.** `zh-cn` resolves to no pack: region→script needs a table this
   extension does not carry. Fixing it *here alone* would be worse than the gap — `LocaleMatcher` declines
   `zh-CN` at detection time too (its own test asserts `['zh-CN'] → null` even with `zh-Hans` installed), so an
   admin would install the pack this report named and those visitors would **still** not be served Chinese. The
   fix belongs in the matcher, is out of Phase 7's scope, and is raised as an open question in §19.
   `test_a_region_that_implies_a_script_is_not_guessed` locks the current behaviour in so that it reads as a
   decision rather than an oversight.
9. **The window is `subDays($days - 1)`, clamped with `max(1, …)`.** So `missing(7)` covers seven calendar days
   *including today*, which is what makes it agree with the seven-bar chart Phase 8 draws beside it. The clamp
   stops a zero or a negative from asking for a cutoff in the future and reporting nothing at all — which would
   look like good news. Pinned from both sides in integration, since it is a query behaviour.
10. **`fold()` repeats `LocaleMatcher::normalize()`'s three lines rather than reaching for them.** Same choice
    `Analytics` made, same reason plus one: `normalize()` is `protected` and no part of that class's contract,
    and what is folded here is a requested tag against a **published catalog**, not against a forum's installed
    locales. Coupling them would mean a future change to *matching* silently re-points every package link on the
    admin page. `CODE_ALIASES` is read from the constant rather than copied, so the alias list itself stays in
    one place.
11. **Three errors in §18 were found and fixed before it shipped as a specification.** Worth recording because
    the corrected text is what the code follows: (a) §18 had `CODE_ALIASES` applied in the wrong **direction** —
    the map is `['uzb' => 'uz']`, catalog key → ISO code, so applying it to a requested `uz` finds nothing; the
    fix is to fold *both* sides while building the index, which files catalog `uzb` under `uz` and makes plain
    exact-matching resolve it, with no separate alias step. (b) §18's truncation example implied `zh` was a
    catalog fallback; **`zh` is not a catalog key at all**, which turns the example into a stronger argument —
    strip straight to the base language and Chinese resolves to *nothing*. (c) The test list still described a
    "normalised index" and a separate alias step. All three are corrected in §18 as it now stands.
12. **The unit tests run against the real `resources/languages.php`.** A fixture would have proved nothing about
    the file that ships: the irregular keys (`es_AR`, `uzb`, `zh-Hans`, `pt-BR`) *are* the difficulty, and
    inventing tidy ones would test the easy case only. `LocaleManager` and `LocaleMatcher` are real for the same
    reason they are real in `LocaleMatcherTest` — doubling them turns every assertion into a claim about which
    methods got called. The connection, by contrast, is an expectation-free Mockery double, which makes "this
    half of the class never queries" an assertion rather than a convention.

**Verification actually performed:** the PHP was **not executed** — there is still no PHP, composer, node or npm
in this environment, so `src/LanguageCatalog.php` and both test files are **unverified until CI runs**. Stated
plainly: **the aggregate query is unexercised until `tests/integration/MissingLanguagesTest.php` runs against a
real MySQL**, because the unit suite deliberately never issues it. That `SUM()`/`GROUP BY` over the seeded rows
returns what these tests claim, and that `SUM()` arrives as a *string* needing the `(int)` casts, are facts about
PDO and MySQL that no local reading confirms.

What was done instead: `Illuminate\Database\ConnectionInterface::table($table, $as = null)` re-read at the locked
version, and `selectRaw()` (`Query/Builder.php:287`) and `groupBy()` (line 1888) confirmed present on the builder
`table()` returns. Confirmed that `isset(SomeClass::CONST_ARRAY[$key])` is legal — `src/LocaleMatcher.php:213`
already does it and shipped through CI in Phase 3. All **87** catalog entries were checked by hand to match
`['name' => …, 'native' => …, 'package' => null|'flarum-lang/…']` in that exact key order, with no empty name or
native, which is precisely what `test_the_shipped_catalog_is_shaped_the_way_the_report_expects` asserts — so that
test should pass, and if it does not, the catalog changed. Structural checks as in every earlier phase: UTF-8
without BOM, LF endings, no tabs, no trailing whitespace, final newline, balanced braces/parens/brackets, `! `
spacing per `.styleci.yml`, alphabetical imports, every import used, longest line 107 columns.

**Phase 8 — Admin dashboard. ✅ DONE.** Work order in §19, followed with the deviations below. Shipped as
**two commits** as §19 required — `52d800f` backend, `d033594` frontend — because the backend half is gated by
CI's MySQL run and the frontend half is gated by nothing that can prove it works.
Built, backend: `src/Statistics.php`, `src/Api/AbstractController.php`, `src/Api/StatisticsController.php`,
`src/Api/MissingLanguagesController.php`, `src/Content/AdminPayload.php`, the two `Extend\Routes('api')`
registrations and the admin frontend's `->content()` in `extend.php`,
`tests/unit/StatisticsQueryTest.php` (20 tests), `tests/integration/ApiTest.php` (14 tests).
Built, frontend: ten modules under `js/src/admin/` — `index.ts`, `types.ts`, `format.ts`, and the components
`LanguageDetectionPage`, `StatsCards`, `LanguagesTable`, `CountriesTable`, `MissingLanguages`, `TrendChart`,
`SettingsTab` — plus the whole of `less/admin.less` (338 lines, previously empty) and 52 new `admin.dashboard.*`
keys in each of `locale/en.yml` and `locale/tr.yml`.

Decisions taken during Phase 8:

1. **Deviation — `Api\AbstractController` is a third backend file.** §19 sketched two controllers. Both need the
   same three lines of `RequestUtil::getActor($request)->assertAdmin()` and the same `days` whitelist, and the
   whitelist is the one place where a divergence would be invisible: two controllers that disagreed about what
   `days=45` means would render a page whose cards and tables described different windows. `WINDOWS` and
   `DEFAULT_WINDOW` are constants on it, and `js/src/admin/types.ts` mirrors them.
2. **Deviation — the summary has seven fields, not six.** `unstated` joins `requests`, `visitors`, `languages`,
   `countries`, `served` and `unserved`. §18 decision 7 left Phase 8 owing a display string for the requests
   that stated no language at all; counting them as unserved would put most forums at "80% of visitors
   unserved" and send an admin hunting a problem that does not exist. The three now add up to `requests`
   exactly, with nothing to explain away, and the languages table carries the same distinction as a `served`
   flag of true, false or **null**.
3. **Deviation — `report()` assembles, it does not query four times.** It reads the clock **once**, fetches the
   grouped languages and countries **once**, and derives the summary from those rows via `summaryFrom()`. Two
   consequences, both deliberate: a card can never disagree with the table printed under it, and the
   distinct-language count is a count of table rows rather than a `COUNT(DISTINCT locale)` — which SQL would
   inflate by one on every forum with traffic, because it counts `''` as a value. A request that crossed
   midnight between the first query and the last would otherwise have shipped a payload describing two
   different weeks.
4. **No row caps anywhere in the payload, deliberately.** Every dimension is naturally bounded (≈250 countries,
   distinct locales by what browsers actually send), and a silent top-N would make an admin's totals disagree
   with the table above them. Recorded in the class docblock: if a cap ever becomes necessary it has to arrive
   as a **visible field in the payload**, not as a `limit()` nobody notices.
5. **The IP payload has three states, and they say different things.** `null` (no dataset installed),
   `{date: '…'}` (installed and dated), `{date: null}` (installed, but the sidecar carried no date). Only the
   first means IP lookup is inactive; the third renders **nothing** rather than a notice, because a dataset
   that is working but undated has nothing untrue to say about itself.
6. **Integration-harness discovery, worth carrying forward:** `flarum/testing`'s `TestCase::request()` builds
   `new ServerRequest([], [], $path, $method)`, and Diactoros takes `$queryParams` as its **8th** constructor
   argument — so a query string written into the path (`/api/…/statistics?days=7`) **never reaches
   `getQueryParams()`**. Every windowed test therefore chains `->withQueryParams(['days' => 7])`. A test that
   relied on the path would have silently exercised the default window and passed for the wrong reason.
7. **Deviation — the frontend has two modules that are not components.** `types.ts` mirrors the PHP array keys
   verbatim (so the two halves of the payload cannot drift without something failing to compile) and
   `format.ts` holds the translation prefix and the arithmetic. §19's instruction was to keep logic out of the
   components "if you want any of it checkable at all"; with no frontend test harness in the repository, small
   exported functions are the most that can be done.
8. **Every translation key is a literal string** — including inside the `CARDS`, `TABS` and `WINDOW_LABELS`
   tables, and including the served/not-served labels, which were first written as `'dashboard.languages_' +
   state` and rewritten. Assembling a key from fragments defeats the *one* frontend claim that is checkable in
   this environment, and a mistyped key renders as the raw key in an admin's browser with nothing in the
   toolchain noticing.
9. **The statistics fetch has its own `refreshing` flag**, not `AdminPage.loading`. `submitButton()` reads
   `loading` for its spinner, so sharing it would have spun the save button every time somebody changed the
   window.
10. **Tabs are local component state, not routes.** Nothing on this page is worth a deep link, and a route
    would mean a resolver plus a second registration for no gain.
11. **`Intl.DisplayNames` is hand-declared.** `flarum-tsconfig` pins `lib` at es2019 and it was not typed until
    es2021, so there is no declaration to import; it is genuinely absent in older browsers too, which is why
    the constructor is read through a guarded cast, wrapped in try/catch, memoised as "instance or null", and
    every caller falls back to the bare two-letter code. **Country flag emoji were dropped** for the same
    class of reason: they render as two bare letters on Windows, which is what the admin is looking at.
12. **The chart is hand-written SVG `<rect>`s** — no chart library and no new palette, fills taken from
    Flarum's own CSS variables. Bars rather than a line, which also settles the one-day window a line chart
    cannot draw. Nothing inside the SVG is text, because `preserveAspectRatio="none"` would distort glyphs:
    the dates are HTML underneath and the per-day figures are `<title>` tooltips. `scale()` returns
    `Math.max(1, …)` so that an all-zero window — every forum on its first day — draws its tracks instead of
    dividing by zero.
13. **Deviation — the table grids are driven by `--grid-tail`, not §19's `--grid-cols`.** It is the count of
    columns *after* the name column (2 or 3), set by a `.CardList--cols-3` / `--cols-4` modifier class,
    because `repeat(calc(var(--total) - 1), 1fr)` is not valid CSS — `repeat()` takes an integer. The modifier
    class rather than an inline style, because **Mithril's inline style objects do not reliably set CSS custom
    properties**. Naming it for the total would have been the misleading half of the choice.
14. **The served/not-served pill colours are literals, and the LESS says why.** Every other colour on the page
    is `var(--flarum-thing, @flarum-thing)` per the house rule, but reading a core variable of unknown value
    and pairing it with a hand-picked text colour can produce an unreadable pair — a strong alert red behind
    dark red text. The four literals assume a light admin area, which is what core 1.x ships; a dark admin
    theme overrides two rules. Everything in `less/admin.less` is nested inside `.LanguageDetectionPage` so
    that the deliberately generic class names (`.CardList`, `.Button-badge`) cannot reach anything else.
15. **`missing_no_package` reads "No single language pack".** This is §18 decision 7's debt paid: the field is
    null both when Flarum publishes no pack and when it publishes several and picking one would be a guess. The
    report does not distinguish them, so the label must not either — "no package available" reads as "Flarum
    does not translate this" and would be wrong about half the time.
16. **The `''` language row keeps its own label and a blank status cell.** It is usually the largest row on the
    page; hiding it would leave the page-view total on the cards unaccounted for, and colouring it red would
    blame the extension for a visitor who asked for nothing. The countries table keeps its unplaceable row for
    the mirror-image reason: a forum whose traffic is mostly unplaceable has a dataset or an edge-header
    problem its admin should be able to see.
17. **The visitors figure is labelled honestly rather than renamed.** `SUM(unique_visitors)` over a window is
    daily visitors summed and is *not* a count of people; the card's help text says so in those words, in both
    languages. Inventing a second name for the same number would only move the confusion somewhere less
    visible.
18. **`analytics_disabled` compares the setting to `'1'`.** Settings are strings and `'0'` is truthy in
    JavaScript — the same trap §12 decision 4 recorded for Phase 2.

**Verification actually performed:** nothing was executed. There is no PHP **and no node, npm or yarn** in this
environment, so the two new test files are **unverified until CI runs** (the integration suite additionally needs
MySQL), and the frontend was neither type-checked nor format-checked nor built. Stated plainly, because it is the
whole of §13 risk 10: **CI cannot prove this phase works.** `js/dist/admin.js` is still the scaffolding's
635-byte bundle of an *empty* initializer — inspected, it literally reads
`initializers.add("huseyinfiliz-language-detection", function(){})` — `enable_bundlewatch` is `false` and there is
no build step in either workflow, so a green tick on `d033594` means the TypeScript compiles and is formatted and
means **nothing at all about whether the page appears**. Someone must run `yarn build` in `js/` and commit the
result.

What was checked by hand instead: all **72** translation keys the frontend references resolve in **both** locale
files, and the placeholder sets are identical in both (the only ordering difference is `cleanup.deleted`'s
`{count}`/`{days}`, which Turkish word order requires); every route path, query-parameter name and payload field
in the TSX matches the PHP (`/language-detection/statistics`, `/language-detection/missing`, `days`, the seven
summary fields, and the row shapes of `languagesFrom()`, `countriesFrom()`, `trendFrom()` and
`LanguageCatalog::missing()`); every Flarum typing this code leans on was read at the locked version in
`vendor/flarum/core/js/dist-typings` (`Translator.getLocale(): string | null`,
`TranslatorParameters = Record<string, unknown>`, `ComponentAttrs extends Mithril.Attributes`,
`ExtensionPageAttrs` exported, `content(): JSX.Element`, `CustomExtensionPage = new () => ExtensionPage<Attrs>`,
`setting(key, fallback?): Stream<string>`, `buildSettingComponent`, `submitButton`, `request<T>` returning a real
`Promise<T>`, and `params` reaching it through `Mithril.RequestOptions`); no line in `js/src` exceeds prettier's
150 columns (longest is 145); and the usual structural pass on the PHP — UTF-8 without BOM, LF endings, no tabs,
no trailing whitespace, final newline, balanced braces/parens/brackets, `! ` spacing per `.styleci.yml`,
alphabetical imports.

**Phase 9 — Cleanup. ✅ DONE.** `src/Cleanup.php` holds the retention policy; `Console\CleanupCommand`
(`language-detection:cleanup`, scheduled daily through `Extend\Console`) and `Api\CleanupController`
(`POST /api/language-detection/cleanup`, behind the same `assertAdmin()`) both call it and neither computes a
cutoff of its own. The boundary is `Statistics::span()`'s, so a retention of *n* days cannot delete a row the
*n*-day dashboard window still draws. `0`, an unsaved value, a non-numeric value and a negative all mean
never-delete, because the unsafe reading of any of them is unrecoverable. Frontend: a delete button below the
save button on the settings tab, reporting the three outcomes the endpoint distinguishes.
`tests/integration/CleanupTest.php` (8 tests) pins the boundary, the four never-delete cases and both endpoint
gates — **unverified until CI runs**, as ever.

**Open question for a later phase:** `zh-CN` and `zh-TW` resolve to nothing, in `LocaleMatcher` at detection
time as much as in `LanguageCatalog`, so Simplified- and Traditional-Chinese readers are neither served nor
correctly reported. Fixing it needs a region→script table and a change to §14's matcher.

**Phase 10 — Final review.** 1.x compatibility, security, performance, privacy, migrations, translations,
tests, README (privacy section is mandatory), CHANGELOG. **Do not begin the Flarum 2.x upgrade.**

---

## 13. Open risks

1. **`scripts/build-ip-data.php` is unverified** until run under real PHP; the committed `.dat` files were
   generated here with a throwaway perl port and verified as *data* (§12 Phase 5). Parity between the two
   implementations is unproven. Confirm byte-identical output before release.
2. **Dataset freshness** — RIR data drifts. Needs periodic regeneration and a release; `resources/ip-data.php`
   carries the build date and `admin.ip_data.notice` surfaces it (Phase 8).
3. **Nothing can be built or tested locally** (no PHP/Node). CI is the only gate.
4. **RIR precision** is registrant-level and coarser than commercial DBs. Acceptable for language selection;
   DB-IP Lite is the documented upgrade path if it proves insufficient.
5. **Cookie-based unique counts are approximate** by design — a deliberate privacy tradeoff to document.
6. ~~**`Translator::setLocale()` input validation is unverified.**~~ **Closed 2026-08-24 (Phase 3).** Read
   directly from `vendor/symfony/translation/Translator.php`: `setLocale()` calls `assertValidLocale()`, which
   is `preg_match('/^[a-z0-9@_\.\-]*$/i', $locale)`. Underscores, hyphens and mixed case are all accepted, so
   `es_MX` and `zh-Hans` pass. Phase 4 can call `setLocale()` with any key `getLocales()` returns without
   guarding it.
7. **`ip6.dat` keys on the top 64 bits only** (§3's design). An allocation of /64 or longer collapses to a
   single key, so two countries holding different halves of one /64 cannot be distinguished — the first wins.
   No registry publishes a prefix longer than /64 today, `rangeV6()` handles the case deliberately rather than
   by accident, and the failure mode is one UI language, so this is recorded rather than fixed.
8. **The IPv6 signed-integer trap is latent, not fixed-and-proven-in-production.** No shipped key reaches
   `8000::` and every address that high is filtered as private or reserved before the search, so the regression
   test rests on a synthetic fixture record (§12 Phase 5 decision 4). If a future dataset reaches that high, the
   test is already there — but nothing in the real data exercises it today.
9. **The dataset path has no end-to-end coverage.** Integration tests cannot set `REMOTE_ADDR` (§12 Phase 5
   decision 9), so "a real address resolves to a country through the middleware" is asserted only at the unit
   level. The edge-header path is covered end to end.
10. **`js/dist/admin.js` is a tracked 635-byte stub and cannot be rebuilt here.** *Verified:* `git ls-files
    js/dist` returns `admin.js` and `admin.js.map`, `.gitignore` does not cover them, and there is no
    node/npm/yarn in this environment. `.github/workflows/frontend.yml` runs the reusable frontend workflow with
    `enable_prettier: true` and `enable_typescript: true` — so CI **type-checks and format-checks** the source,
    and does **not** build a bundle or commit one back. The consequence for Phase 8 is concrete and is a release
    blocker rather than an inconvenience: once the TSX lands, CI can go green while the extension still ships
    the stub, so **the admin page will not appear in any browser until someone runs `yarn build` in `js/` and
    commits the result**. Two things follow. Phase 8 must say so in its own report rather than describing the
    page as done, and Phase 10 must check the committed bundle's size before release — 635 bytes means it was
    never rebuilt. The format gate is also strict: `@flarum/prettier-config@1.0.0` is
    `{"printWidth": 150, "singleQuote": true, "tabWidth": 2, "trailingComma": "es5"}` (*verified* — fetched from
    the resolved tarball in `js/yarn.lock`), and hand-written TSX that no `prettier --write` ever touched will
    very likely fail `format-check` on whitespace nobody can see.

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

## 15. Phase 4 work order — ✅ implemented, kept as the middleware's specification

> **Phase 4 is done** (§12 records what was built, the seven decisions taken, and exactly what was and was not
> verified). This section is retained because it is still the authoritative statement of the resolution chain
> and the middleware's branch-by-branch behaviour — Phase 10's review should check the code against it, and
> Phase 5 plugs a second detection source into the `LanguageDetector` described here without breaking these
> contracts. Do not re-implement it. Every API claim was read out of `vendor/flarum/core` or
> `vendor/flarum/testing` at 1.8.19 on 2026-08-24.

**Background, in this order:** §7 (middleware position, core's `SetLocale` source, **and the correction box** —
core never writes a `locale` cookie), §6 "Cookies", §10 (the five settings), §14 (the contracts of the two
Phase 3 classes this consumes).

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
    SettingsRepositoryInterface $settings,
    LocaleManager $locales           // as built — see step 3 and §12 Phase 4 decision 1
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

---

## 16. Phase 5 work order — ✅ implemented, kept as the IP lookup's specification

> **Phase 5 is done** (§12 records what was built, the eleven decisions taken, the dataset facts, and exactly
> what was and was not verified). This section is retained because it is still the authoritative statement of
> the lookup's resolution order, the binary-search trap and the generator's contract — Phase 10's review should
> check the code against it, and Phase 6 takes a country code from the class described here. Do not
> re-implement it. **Three things below were deviated from**, all recorded in §12: the `countryFor()` signature
> (decision 1), the fixture paths (decision 7), and where the integration suite boots (decision 8).

**Read first, in this order:** §3 in full (why `fof/geoip` is out, the resolution order, the binary dataset
design, the country→language map **and** the `en` correction box), §15 (the `LanguageDetector` seam this plugs
into and the middleware it feeds), §10 (`detection_order`), §2 (no PHP here; `curl`, `perl`, `awk`, `od`, `xxd`
are available), §13 risk 1 (the generator stays unverified until someone runs it under real PHP).

**Goal:** a second detection source. A guest with no useful `Accept-Language` — or one whose forum is
configured `ip_browser` — gets their country's language, resolved through the *same* `LocaleMatcher` as the
browser source. This is also the phase that makes `detection_order` observable for the first time.

### Scope discipline — what Phase 5 must NOT touch

- **No analytics.** Phase 6 owns `language_detection_stats`, the `country_code` column, `Analytics`,
  `BotDetector` and the `flarum_language_detection_day` cookie. Phase 5 produces a country code; it does not
  count anything.
- **No admin UI.** Phase 8. `locale/en.yml` already carries `admin.ip_data.notice` (with a `{date}`
  placeholder) and `admin.ip_data.notice_unavailable` — Phase 5 must make both *answerable* (see the metadata
  sidecar below) but must not build the notice, and must not add locale keys.
- **No external calls at runtime**, no API keys, no `fof/geoip`, no downloads outside
  `scripts/build-ip-data.php`. §3 is emphatic and the spec's "no expensive operations on every page request"
  still binds — detection already runs at most once per visitor (§6), and the lookup itself is ~18 `fseek`s.
- **Do not change `BrowserLanguageParser`, `LocaleMatcher`, or `DetectLanguage`.** §14 and §15 are their
  specifications. The only edit outside new files is the two-line seam in `LanguageDetector` described below.
  If IP detection seems to need a matcher change, that is a signal the logic belongs in `CountryLanguage`.
- **Never store the IP.** It is read from the request, used, and dropped — not logged, not cached, not written
  to any table. §1, and the privacy promise `enable_analytics_help` already makes to admins.

### Deliverables

```
src/IpCountryLookup.php
src/CountryLanguage.php
src/LanguageDetector.php               (edit: SOURCES gains SOURCE_IP; fromSource() gains one branch)
scripts/build-ip-data.php              (maintainer-facing, committed, never invoked at runtime)
resources/ip4.dat                      (~1.4 MB, generated)
resources/ip6.dat                      (~0.9 MB, generated)
resources/ip-data.php                  (generated sidecar: build date + record counts)
tests/unit/IpCountryLookupTest.php
tests/unit/CountryLanguageTest.php
tests/fixtures/ip4-test.dat            (a few hand-built records, not the shipped files)
tests/fixtures/ip6-test.dat
tests/integration/DetectionTest.php    (edit: the edge-header path, and the first real detection_order test)
CHANGELOG.md
```

### `IpCountryLookup`

```php
public function countryFor(?string $ip, ServerRequestInterface $request): ?string;   // 'TR', or null
```

Whether the request arrives as a second argument or the class reads the IP off it itself is a judgement call —
decide once and say so in the commit message. The pieces it needs:

- **The IP.** `$request->getAttribute('ipAddress')`, set by core's `Http\Middleware\ProcessIp` from
  `REMOTE_ADDR`, defaulting to `127.0.0.1` (*verified* — `vendor/flarum/core/src/Http/Middleware/ProcessIp.php`,
  registered in `InstalledApp`'s outer middleware per §9, so it runs long before the forum stack and the
  attribute is always present). **Flarum 1.x does not honour `X-Forwarded-For`** — `ProcessIp` reads
  `REMOTE_ADDR` and nothing else — so behind a reverse proxy the address may be the proxy's. That is exactly
  why the edge headers below come *first* rather than as a fallback, and it is worth a comment in the code.
- **Reject non-public addresses** before anything else:
  `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` covers empty,
  malformed, loopback, private and reserved in one call. `127.0.0.1` — ProcessIp's own default — falls out
  here, which is what turns a CLI or misconfigured request into a clean `null` instead of a lookup.
- **Trusted edge/server headers** (§3): `CF-IPCountry`, `CloudFront-Viewer-Country`, `X-Vercel-IP-Country`,
  `Fastly-Geo-Country`, `X-AppEngine-Country` via `getHeaderLine()`, plus `GEOIP_COUNTRY_CODE` and
  `MM_COUNTRY_CODE` from `getServerParams()`. Validate with `/^[A-Z]{2}$/` and treat `XX`, `T1`, `ZZ` as
  unknown. Free, and more accurate than any bundled DB. Spoofable, but the only consequence is which UI
  language is offered — a visitor can already send any `Accept-Language` they like — so document it and move on.
- **The bundled dataset**, last.

**Binary search — the one real trap.** IPv6 records key on the top 64 bits (§3), and PHP's integers are
*signed*, so `unpack('J', …)` on any address at or above `8000::` yields a negative number and the search
silently inverts. Do not convert to integers: compare the raw big-endian bytes with `strcmp()`. Big-endian byte
order makes byte-wise comparison identical to unsigned numeric comparison, so
`strcmp($candidateKey, substr(inet_pton($ip), 0, 8)) <= 0` is both correct and shorter than the arithmetic. The
same approach works unchanged on IPv4's 4-byte keys, which keeps one search routine instead of two.

Everything else follows §3: `fopen('rb')`, `filesize() / RECORD` for the count, binary search taking the
greatest start ≤ target, `fread(6)` / `fread(10)` per probe, nothing loaded into memory, and `\0\0` meaning
"unknown" (return `null`, never the literal). **A missing or truncated dataset must return `null`, not warn or
throw** — the extension has to keep working on browser detection alone, which is precisely the state
`admin.ip_data.notice_unavailable` describes. Close the handle in a `finally`.

### `resources/ip-data.php` — why a sidecar

`admin.ip_data.notice` promises a build date. Do not derive it from `filemtime()`: git sets mtimes to *checkout*
time, so the notice would report when the forum was installed and quietly lie to every admin who reads it. §3's
record layout has no header field by design, so the generator should also write a tiny `resources/ip-data.php`
returning something like
`['built' => '2026-08-24', 'ipv4_records' => …, 'ipv6_records' => …, 'source' => 'RIR delegated-extended']`.
`IpCountryLookup` exposes it (a `datasetInfo(): ?array`, `null` when the files are absent) so Phase 8 can render
either notice without touching the binary files.

### `CountryLanguage`

```php
/** @return string[] ordered candidate locale codes, or [] for an unknown country */
public function candidatesFor(string $countryCode): array;
```

It loads `resources/countries.php` (246 entries, committed in Phase 2, keys uppercase ISO 3166-1 alpha-2) and
returns the ordered list **unresolved**. It must not decide what is installed: that list goes straight into
`LocaleMatcher::match()`, exactly like browser tags, so IP candidates inherit truncation, `CODE_ALIASES` and the
unambiguous-sibling rule for free and there stays one place where "installed" is decided. Load the file once per
instance (`require` in the constructor, or a lazy static), not once per call.

### The seam in `LanguageDetector`

Phase 4 left this deliberately small (§12 Phase 4 decision 2). Add `self::SOURCE_IP` to `SOURCES`, add the
branch to `fromSource()`, and write `fromIp()`:

```php
$country = $this->ipLookup->countryFor($request->getAttribute('ipAddress'), $request);

return $country === null ? null : $this->matcher->match($this->countryLanguage->candidatesFor($country));
```

Nothing else changes: `detect()` already walks the sources in the configured order and falls through to
`default_locale`, and `DetectLanguage` already applies and remembers whatever comes back.

### Generating the `.dat` files

`scripts/build-ip-data.php` is the canonical generator and must be written as if it were the only one: fetch the
five RIR `delegated-*-extended-latest` files listed in §3, keep `ipv4`/`ipv6` allocation rows, expand the IPv4
`start + count` form into ranges (counts are **not** always powers of two — split them), sort, **fill every gap
with a `\0\0` record** so the "start only, no end" layout stays exhaustive, merge adjacent identical countries,
and write the two files. Since there is no PHP here, produce the initial `.dat` files with an equivalent
throwaway `perl` script and spot-check known pairs — `8.8.8.8` → `US`, a `212.156.x` → `TR`, an APNIC range →
`CN`, plus one IPv6 (`2606:4700::` → Cloudflare's registration) and both file edges. Record the spot-check
results in §12. §13 risk 1 stays open until the committed PHP generator has been run under real PHP and shown to
produce byte-identical files.

Committing ~2.3 MB of binary data is intended (§3, accepted tradeoff). Do not gzip — `fseek` needs it raw.

### Tests

**Unit.** Build `tests/fixtures/ip4-test.dat` and `ip6-test.dat` from a handful of hand-computed records so the
expected answers are obvious, and point the lookup at them (a constructor argument for the resources directory
is the cheapest way; whatever you choose, do not let tests depend on the 2.3 MB shipped files). Cover: an
address below the first record, an exact range start, an address inside a range, the last record, an address in a
`\0\0` gap, a private address, `127.0.0.1`, a malformed string, and each edge header — including the `XX`/`T1`
rejection and a header on a request whose IP is private (the header still wins, since it is the edge's own
verdict). For `CountryLanguage`: a known country, an unknown one, and the `CA => ['en', 'fr']` ordering rule from
§3, which exists so majority-English countries are not forced to a minority language.

Fixtures live in `tests/fixtures/` and must be opened by path — a *class* there would not autoload (§2; note
`tests/fixtures/.gitkeep` can go once real fixtures land).

**Integration** — two cases in `DetectionTest`, both cheap because neither needs the dataset:

1. A guest with `CF-IPCountry: TR` and no `Accept-Language` is served Turkish. The first end-to-end proof of the
   IP path.
2. The same request with **both** `CF-IPCountry: TR` and `Accept-Language: de`, run under
   `$this->setting('huseyinfiliz-language-detection.detection_order', 'ip_browser')` — Turkish wins — and again
   under `browser_ip` — German wins. `setting()` applies **before boot** (*verified* —
   `Flarum\Testing\integration\TestCase::setting()` feeds `SetSettingsBeforeBoot`), unlike
   `prepareDatabase(['settings' => …])`, so it must be called before anything touches `app()`. This is the test
   Phase 4 could not write, and it is worth writing carefully: it is the only assertion that `detection_order`
   does anything at all.

Reuse Phase 4's setup verbatim — `addLocale()` on the `LocaleManager` singleton, assertions on the rendered
`<html lang>` — and re-read §12 Phase 4 decision 6 before adding a second `send()` to any one test.

### Verification available in this environment

Unchanged and still binding: **there is no PHP here**, so nothing can be executed — no phpunit, no `php -l`. CI
is the only gate. **Never state or imply that the tests pass.** Report what was written and that it is
unverified until CI runs. Phase 5 does have one check the earlier phases lacked: the generated `.dat` files can
be inspected byte by byte with `od`/`xxd`, and the spot-checks above are real evidence about the *data* — say
plainly that they say nothing about the PHP.

Finish by adding a `CHANGELOG.md` line under `## [Unreleased] / ### Added`, then commit per the convention in
the status header above. Close Phase 5 out the way every phase closes: record what was built and decided in
§12, and write the Phase 6 work order as §17.

---

## 17. Phase 6 work order — ✅ implemented, kept as the analytics specification

> Read alongside §12's Phase 6 record, which lists the eleven decisions taken while building it — including the
> one deviation from the `record()` signature sketched below, and the two places this section's advice was
> narrowed (the over-long tag) or overruled (`AnalyticsTest` was written, not skipped).
>
> Self-contained on purpose: a fresh session should be able to build Phase 6 from this section plus the
> cross-references below, without re-doing discovery. API claims marked *verified* were read out of
> `vendor/` on 2026-08-25 at the locked versions (`flarum/core` 1.8.19, `illuminate/database` **v8.83.27**).
> Two of them **correct §6**, which was written before the vendor tree was readable — read the corrections
> before writing any SQL.

**Read first, in this order:** §6 in full (the table, the counting rules, the cookies, and the two corrections
below), §10 (`enable_analytics`, `ignore_bots`), §1's privacy constraints (which are the hard boundary of this
phase), §15 (the middleware Phase 6 restructures), §16 (the lookup Phase 6 takes a country code from).

**Goal:** the extension starts *reporting* as well as deciding. Every forum page view contributes to one
aggregated daily row, so Phase 7 can diff requested languages against installed ones and Phase 8 can draw the
dashboard. Nothing identifying is written anywhere: the visitor's IP, User-Agent and `Accept-Language` are all
read, used within the request, and dropped.

### Scope discipline — what Phase 6 must NOT touch

- **No admin UI, no API endpoints, no new `locale/*.yml` keys.** Phase 8 owns the dashboard and Phase 7 the
  missing-languages report. Phase 6 writes rows; it does not read them back for anybody. Resist adding a
  `Statistics` query class "while you are here" — that is §11's `src/Statistics.php` and it belongs to Phase 8,
  which knows what the dashboard actually needs.
- **No cleanup, no retention enforcement, no console command.** Phase 9. `retention_days` is a setting Phase 6
  reads *never*.
- **No second table, no Eloquent model.** §6 and spec §34: exactly one table, driven by the query builder.
- **Do not change `BrowserLanguageParser`, `LocaleMatcher`, `IpCountryLookup`, `CountryLanguage` or
  `LanguageDetector`.** §14, §15 and §16 are their specifications and Phases 7–8 depend on those contracts.
  Phase 6 *consumes* them. `Middleware/DetectLanguage` is the one existing file that changes, and §15 stays its
  specification for everything except where the counting hooks in.
- **Never store an identifier.** No visitor ID, no hashed IP, no "anonymised" IP, no UA string, no raw header,
  no URL, no referrer. The `unique_visitors` count comes from a cookie carrying a **bare date** and nothing
  else. If a design seems to need more than that, it is the wrong design.

### Deliverables

```
src/Analytics.php                      (the recorder: one public method, one atomic write)
src/BotDetector.php                    (User-Agent substring match, read and discarded)
src/Middleware/DetectLanguage.php      (edit: count on every page view, not only when detection runs)
tests/unit/BotDetectorTest.php
tests/unit/AnalyticsTest.php           (optional — see "Tests" for what is and is not worth unit-testing)
tests/integration/StatisticsTest.php
CHANGELOG.md
```

`extend.php` needs **no change**: `Illuminate\Database\ConnectionInterface` is registered as a container
**singleton** with aliases `db.connection` and `flarum.db` (*verified* —
`vendor/flarum/core/src/Database/DatabaseServiceProvider.php:51-58`), so plain constructor injection resolves it,
and the middleware is already registered. Do not add a service provider.

### Correction to §6 — the atomic upsert needs **no raw SQL string**

§6 says "the atomic upsert needs raw SQL regardless". That was written before `vendor/` was readable and is
wrong. `Illuminate\Database\Query\Builder::upsert(array $values, $uniqueBy, $update = null)` exists at
v8.83.27 (*verified* — `Query/Builder.php:3116`), and MySQL's grammar compiles it to
`insert … on duplicate key update …` (*verified* — `Query/Grammars/MySqlGrammar.php::compileUpsert`). The part
that makes an atomic **increment** possible:

```php
$columns = collect($update)->map(function ($value, $key) {
    return is_numeric($key)
        ? $this->wrap($value).' = values('.$this->wrap($value).')'
        : $this->wrap($key).' = '.$this->parameter($value);
})->implode(', ');
```

So a **string-keyed** `$update` entry emits `` `col` = ? `` — and `Grammar::parameter()` is
`isExpression($value) ? $this->getValue($value) : '?'`, while `Builder::cleanBindings()` rejects every
`Expression` from the binding list (*both verified*). Therefore:

```php
$db->table('language_detection_stats')->upsert(
    [
        'date'            => $date,
        'locale'          => $locale,
        'country_code'    => $country,     // '' when unknown
        'requests'        => 1,
        'unique_visitors' => $newVisitor,  // 1 or 0
        'created_at'      => $now,
        'updated_at'      => $now,
    ],
    ['date', 'locale', 'country_code'],
    [
        'requests'        => $db->raw('requests + 1'),
        'unique_visitors' => $db->raw('unique_visitors + '.(int) $newVisitor),
        'updated_at'      => $now,
    ]
);
```

compiles to one statement, increments in the database rather than in PHP, and is race-free under concurrency —
which read-then-write would not be. Four notes, each a real trap:

1. **`$uniqueBy` is ignored by the MySQL grammar** — `compileUpsert()` never references it, because
   `on duplicate key update` fires on whatever unique keys the table has. Pass the real columns anyway: they are
   the documentation of *which* index this relies on, and other grammars do use them.
2. **`(int)` cast, not string interpolation on trust.** An `Expression` is spliced into the SQL verbatim and
   cannot carry a binding, so `unique_visitors + ?` is not available here. The cast is what makes the
   interpolation provably safe; write it that way and say why in a comment, so a later reader does not "fix" it
   into an injection.
3. **Timestamps are not managed for you.** The query builder does not touch `created_at`/`updated_at` (that is
   Eloquent), and the migration declares both nullable with no default — so set them explicitly in both the
   insert values and the update map, or every row ships with nulls.
4. **Do not use the numeric-key form of `$update`.** It emits `values(col)`, which MySQL 8.0.20 deprecates. The
   string-keyed form above avoids `values()` entirely.

### Correction to §6 — what goes in the `locale` column, and the length trap

§6 is right that stats store the **requested** locale, not the resolved one — that is the whole mechanism behind
`fallback = SUM(requests) WHERE locale NOT IN (installed locales)` and behind Phase 7's report. So the value is
the visitor's **most-preferred parsed tag**: `$parser->parse($request->getHeaderLine('Accept-Language'))[0]`,
not what `LanguageDetector` resolved it to. Getting this backwards would make the missing-languages report
structurally incapable of ever finding anything.

Two things §6 does not say:

- **`locale` is `string(20)` NOT NULL** (*verified* — the migration). `BrowserLanguageParser`'s shape regex is
  `/^[A-Za-z]{1,8}([-_][A-Za-z0-9]{1,8})*$/` with no overall length bound (§14 rule 7), so a crafted header can
  yield a valid-shaped tag of 26+ characters. Under MySQL strict mode that is an error, not a truncation, and it
  would surface as a 500 on a page view. **Skip any tag longer than 20 bytes** rather than truncating it — a
  truncated tag is a fabricated language code that would then appear in the admin's missing-languages report.
- **Normalize for aggregation, or the dashboard is noise.** `tr`, `TR`, `tr-TR` and `tr_TR` are four rows
  otherwise. Fold to lowercase with `_` → `-` (the same rule `LocaleMatcher::normalize()` applies, which is why
  this is consistent rather than a second convention) — but **do not** reach into `LocaleMatcher` for it; §14
  forbids changing that class and its normalizer is not part of its public contract. A one-line
  `strtolower(str_replace('_', '-', $tag))` in `Analytics` is the honest duplication.
- **A visitor who requested nothing** (no `Accept-Language`, or every tag dropped) still viewed a page. Record
  them with `locale = ''`, matching `country_code`'s "`''` means unknown" convention, so totals stay truthful
  and the row is distinguishable from any real language. Note the consequence for Phase 7: `''` is not a missing
  language and must be excluded from that report explicitly.

### `Analytics`

```php
public function __construct(
    ConnectionInterface $db,
    BrowserLanguageParser $parser,
    IpCountryLookup $lookup,
    BotDetector $bots,
    SettingsRepositoryInterface $settings
) {}

/** @return bool whether this visitor was counted as new today (the caller writes the day cookie) */
public function record(ServerRequestInterface $request, bool $isNewVisitor): bool;
```

The exact shape is a judgement call — decide once and say so in the commit message — but two things are not:

1. **`enable_analytics` is checked first and short-circuits everything.** Off means no parse, no lookup, no
   query, and no day cookie. A forum with analytics disabled must issue exactly the same number of statements as
   one without this extension.
2. **`ignore_bots` is checked before the write, not after.** A bot's language is still detected (§10's help text
   promises exactly that: "Their language is still detected, it is just not counted"), so the bot check belongs
   here in the recorder and **must not** be added to `LanguageDetector`.

`BrowserLanguageParser` is injected rather than reusing whatever `LanguageDetector` parsed. Parsing twice is pure
string work with no I/O, and the alternative — widening `LanguageDetector`'s contract to hand back its
intermediate state — would break §15 for a saving of nothing.

**The country code costs a second lookup per page view.** Detection runs at most once per visitor (§6), but
counting runs on every view, so `IpCountryLookup::countryFor()` now runs on every view too. That is ~18 `fseek`s
against an OS-page-cached file, which is genuinely cheap — but it is no longer *zero*, so measure the claim
before repeating it. **Do not "optimise" it by caching the country in the day cookie:** the cookie is a bare
date on purpose (§1, and `enable_analytics_help`'s promise to admins), and a cookie carrying date + country is a
meaningfully larger privacy surface for a saving of a few file seeks.

### `BotDetector`

```php
public function isBot(?string $userAgent): bool;
```

Case-insensitive substring match against a static list (§6): `bot`, `crawler`, `spider`, `slurp`,
`bingpreview`, `facebookexternalhit`, `headlesschrome`, `curl`, `wget`, `python-requests`, and similar. The UA
comes from `$request->getHeaderLine('User-Agent')`, is tested, and is **never stored** — not in the database, not
in a log, not in a cookie.

**Known false positive, and why it is acceptable.** A bare `bot` substring matches real device names —
`CUBOT_NOTE_20` is a real Android phone that appears in real UAs — so some genuine visitors will go uncounted.
The consequence is one missing row increment in an approximate statistic that §6 already documents as
approximate, whereas the consequence of *missing* a bot is inflated numbers an admin might act on. Either keep
the bare token and document the false positive, or tighten it (`bot/`, `bot;`, `+http`, `-bot`) and document what
that lets through. **Do not** leave the tradeoff unstated — and write the test either way: an empty UA, a null
UA, a real Chrome UA, a real Googlebot UA, and the `CUBOT` case with whichever answer you chose asserted
deliberately.

### The middleware restructure

Today `DetectLanguage::process()` short-circuits: a user with a locale preference and a guest with a memo cookie
both return early, before any detection. **Counting must not sit behind either short-circuit** — §6 counts *page
views*, and the visitors who return early are precisely the repeat visitors who make up most of the traffic.
Counting only on first visits would undercount by roughly the whole returning population and quietly invert
every trend on the dashboard.

So `process()` becomes: GET guard → the existing detect/apply/remember branches, which return the response →
then the analytics step, which may add the day cookie to that response. Keep the two concerns visibly separate
(the existing `forUser()`/`forGuest()` are fine as they are; wrap rather than thread a flag through them), and
keep both cookie writes composing — `FigResponseCookies::set()` returns a new response, so the memo cookie and
the day cookie must be applied in sequence to the *same* response object, not to two.

**The day cookie** (§6): name `language_detection_day` through `CookieFactory`, so
`flarum_language_detection_day` on the wire, 1 year, holding today's date and nothing else. Read it with
`Arr::get($request->getCookieParams(), $this->cookies->getName(…))`; if the value is not today's date,
`unique_visitors` is incremented and the cookie is rewritten. **Validate it on read** the way Phase 4 validates
the locale memo (§12 Phase 4 decision 3) — an arbitrary cookie value must never reach a comparison that decides
a database write, and a tampered value should simply read as "not today".

Date handling: use one `Carbon::now()` for the whole request and derive both the `date` column and the cookie
value from it, so a request crossing midnight cannot write a row for one day and a cookie for another. The
dashboard groups by the same column, so whatever timezone PHP is configured with, the data is self-consistent.

### Tests

**`BotDetectorTest`** — as listed above. Pure, fast, and the one class here with no dependencies at all.

**`AnalyticsTest` is optional and should be honest about it.** `Analytics` exists to issue one SQL statement;
asserting that with a mocked `ConnectionInterface` tests the mock's expectations, not the SQL, and would pass
just as happily against a statement MySQL rejects. What *is* worth a unit test is the pure decision-making
around the write: `enable_analytics = '0'` issues nothing (assert the connection is never touched — Mockery is
available per §2), a bot is not counted when `ignore_bots = '1'` but is when it is `'0'`, an over-long tag is
skipped, `tr_TR` and `TR` both normalize to `tr-tr`/`tr`, and a request with no header records `''`. Write those;
skip the SQL assertions.

**`tests/integration/StatisticsTest.php`** — a new file, because `DetectionTest` is about what the visitor sees
and this is about what the database holds. Extend `Flarum\Testing\integration\TestCase`, and **copy Phase 5's
deferred-boot pattern verbatim** (§12 Phase 5 decision 8): `setting()` is a no-op after boot, and this suite
needs `enable_analytics`/`ignore_bots` set per test. Read rows back through `$this->database()->table(…)` rather
than through any code this extension ships, so the test cannot be satisfied by a bug shared with the writer.

Cases: one page view writes one row with `requests = 1`, `unique_visitors = 1`, the requested locale and `''`
country; **two views in one test increment `requests` to 2 and leave `unique_visitors` at 1** (the second request
must carry the first response's cookies — and re-read §12 Phase 4 decision 6 before asserting anything about
`lang` on a second `send()`, though row assertions are unaffected); a view with `CF-IPCountry: TR` writes
`country_code = 'TR'`; a view with `Accept-Language: ja` on a forum without Japanese still writes a `ja` row
(this is the row Phase 7's report is built on, and the single most valuable assertion in the file); a bot UA
writes nothing under `ignore_bots = '1'`; `enable_analytics = '0'` writes nothing at all; and a request with no
`Accept-Language` writes a `''` locale row rather than no row. Assert on `$this->database()->table(…)->count()`
as well as on values, so "wrote two rows instead of incrementing one" fails loudly — that is the exact failure
mode the unique index exists to prevent, and the one a `where()->first()` assertion would sail straight past.

### Verification available in this environment

Unchanged and still binding: **there is no PHP here**, so nothing can be executed — no phpunit, no `php -l`. CI
is the only gate. **Never state or imply that the tests pass.** Report what was written and that it is
unverified until CI runs. Phase 6 is additionally the first phase whose core behaviour is *database* behaviour,
so note plainly that the upsert's atomicity and the unique index's dedupe are unexercised until the integration
suite runs against real MySQL in CI — no amount of local reading proves them. Structural checks that *are*
possible, as in every earlier phase: UTF-8 without BOM, LF endings, no tabs, no trailing whitespace, final
newline, balanced braces/parens, `! ` spacing, alphabetical imports, and StyleCI conformance by eye against
`.styleci.yml`.

Finish by adding a `CHANGELOG.md` line under `## [Unreleased] / ### Added`, then commit per the convention in
the status header above. Close Phase 6 out the way every phase closes: record what was built and decided in
§12, and write the Phase 7 work order as §18.

---

## 18. Phase 7 work order — ✅ implemented, kept as the missing-languages specification

> Self-contained on purpose: a fresh session should be able to build Phase 7 from this section plus the
> cross-references below, without re-doing discovery. API claims marked *verified* were read out of `vendor/`
> and out of this repository on 2026-08-25 at the locked versions (`flarum/core` 1.8.19,
> `illuminate/database` v8.83.27).

**Read first, in this order:** §17's second correction (what the `locale` column actually holds, and why —
Phase 7 is the reason that decision was made), §14 (the specification of `LocaleMatcher`, which is what "can
this forum serve that language?" means in this codebase), §12 Phase 3 decision 1 (the macrolanguage map, added
*specifically* so this report would not lie about Norwegian), §6's "fallback requests need no extra column",
and the header of `resources/languages.php`.

**Goal:** answer one question for an admin, from data the forum already has — *which languages are my visitors
asking for that I cannot serve them?* — and hand each answer the Composer package that would fix it. This is
the payoff for §17's decision to record the **requested** locale rather than the resolved one, and it is the
first phase that reads the statistics table back.

Phase 7 is server-side only. It produces the report; §8's `Api/MissingLanguagesController` and the admin table
that renders it are Phase 8.

### Scope discipline — what Phase 7 must NOT touch

- **No admin UI, no API endpoint, no new `locale/*.yml` keys, no TypeScript, no LESS.** Phase 8 owns all of
  that, including the display strings for this report. Phase 7 returns plain arrays.
- **No `src/Statistics.php`.** Still Phase 8's, and still the file to resist creating early (§17 said the same).
  When Phase 8 arrives, `Api/MissingLanguagesController` must call `LanguageCatalog::missing()` — the query must
  **not** be reimplemented in `Statistics.php`. Say so in the Phase 8 work order.
- **Do not change `BrowserLanguageParser`, `LocaleMatcher`, `CountryLanguage`, `IpCountryLookup`,
  `LanguageDetector`, `Analytics`, `BotDetector` or `Middleware/DetectLanguage`.** §14, §15, §16 and §17 are
  their specifications. Phase 7 *consumes* them and adds nothing to the request path — no middleware change, no
  new work on a page view. Reading is an admin-side operation and must stay one.
- **Do not edit `resources/languages.php` to make the code simpler.** It is upstream data (§11, and its own
  header): `es_AR`/`es_MX` carry underscores, `uzb` is not `uz`, `zh-Hans`/`sr-Cyrl` are mixed case, and every
  one of those is deliberate because the keys must stay usable verbatim with `LocaleManager::hasLocale()`. If
  the code and the data disagree, the code is wrong — that is §12 Phase 5 decision 11, which was learned the
  hard way on `resources/countries.php`.
- **No retention, no cleanup, no writes of any kind.** Phase 9, and Phase 7 issues `SELECT` only.

### Deliverables

```
src/LanguageCatalog.php
tests/unit/LanguageCatalogTest.php
tests/integration/MissingLanguagesTest.php
CHANGELOG.md
```

`extend.php` needs **no change**, for the same two reasons Phase 5 and Phase 6 needed none: the constructor
takes `LocaleManager`, `LocaleMatcher`, `ConnectionInterface` and a nullable `?string $path = null`, and
Laravel's `resolvePrimitive()` fills an unresolvable primitive from its default rather than throwing (§12 Phase
5 decision 3). Nothing is registered until Phase 8 needs a route.

### `LanguageCatalog`

Mirror `CountryLanguage`'s shape for the data half — `?string $path = null` defaulting to
`dirname(__DIR__).'/resources/languages.php'`, memoised, loaded with `require` and **not** `require_once` (a
second `require_once` of the same file returns `true`, not the array — see `CountryLanguage::map()`, which
documents the trap). That nullable path is also the test seam.

```php
public function __construct(
    LocaleManager $locales,
    LocaleMatcher $matcher,
    ConnectionInterface $db,
    ?string $path = null
) {}

/** @return array<string, array{name: string, native: string, package: string|null}> the whole catalog */
public function all(): array;

/** @return array{name: string, native: string, package: string|null}|null the pack that would serve $code */
public function entryFor(string $code): ?array;

/** @param array<string, int[]> $volumes normalised requested tag => [requests, unique_visitors] */
public function missingFrom(array $volumes): array;

/** @param int|null $days window in days; null means all time */
public function missing(?int $days): array;
```

The split is the point: `all()`, `entryFor()` and `missingFrom()` are **pure** — no database, no clock — and
`missing()` is a thin wrapper that runs one aggregate query and hands the result to `missingFrom()`. That is
what makes the interesting half unit-testable without MySQL, which Phase 6 could not manage and paid for (§12
Phase 6, "Verification actually performed").

### The two questions are independent — do not collapse them

This is the design trap in Phase 7, and getting it wrong produces a report that is confidently wrong rather
than merely incomplete.

1. **Is this requested tag served by anything installed?** That is `$matcher->match([$tag])` returning `null`,
   and nothing else. `LocaleMatcher` is the one place in this extension that decides what "installed" means
   (§14), it is exactly what the middleware asked at detection time, and it already handles exact matching,
   progressive truncation, the `uzb`/`uz` alias and the unambiguous-sibling rule. A hand-rolled
   `in_array($tag, $locales)` would report `tr-tr` as missing on a forum with `tr` installed — a request that
   was served, listed as one that was not.
2. **Which pack would serve it?** That is the catalog, and it is a *different* question. `pt-br` resolves to
   catalog entry `pt-BR`; but on a forum with `pt` installed and `pt-BR` not, `match(['pt-br'])` returns `pt`,
   so the tag is **served** and must not appear in this report at all.

So: **filter to unserved tags first, then group the survivors by catalog entry.** In that order there is no
such thing as a group with some tags served and some not, and the `pt-br`/`pt` case above resolves correctly by
construction. Grouping first and filtering second creates exactly that mixed group and there is no honest way
out of it.

Two consequences worth stating rather than discovering:

- **`pt-BR` requested while `pt` is installed is not "missing".** The visitor got Portuguese. A separate
  "regional variants you could add" report is a defensible feature and it is not this one; listing a served
  request as missing is the "visibly wrong signal to an admin" §12 Phase 3 decision 1 was written to avoid.
- **`locale = ''` must be excluded explicitly** (§17). It is not a language, it is "this visitor stated no
  preference", and it is typically the largest bucket in the table — left in, it would top the report as a
  missing language named nothing.

### Resolving a requested tag to a catalog entry

The table holds tags already lowercased and hyphenated by `Analytics::requestedLocale()` (`pt-br`, `zh-hans-cn`,
`tr-tr`). Catalog keys are not (`pt-BR`, `zh-Hans`, `es_AR`, `uzb`). So build a normalised index once — folded
key => the verbatim key — and resolve against it:

1. Fold **both sides identically**, and fold them the way `LocaleMatcher::normalize()` does:
   `strtolower(str_replace('_', '-', $code))`, then `LocaleMatcher::CODE_ALIASES` applied to the **language
   subtag**. Mind the direction — the map is `['uzb' => 'uz']` (*verified* — `src/LocaleMatcher.php:45-47`,
   `public`, no visibility modifier), so it points **catalog key → ISO code**, not the other way round. Applying
   it while *building the index* files catalog `uzb` under `uz`; applying it to the requested tag too means a
   browser that sends `uzb` verbatim folds to the same place. That is precisely how
   `LocaleMatcher::normalize()` makes installed `uzb` answer a requested `uz` (*verified* —
   `src/LocaleMatcher.php:203-219`, and its docblock says why: browsers send the three-letter codes verbatim,
   and `uzb` is the only one of the catalog's seven with an ISO 639-1 equivalent). Do **not** flip the map, do
   **not** copy it, and do **not** call `normalize()` — it is `protected` and is not part of that class's
   contract (§14). Reproducing those three lines here is the same honest duplication §17 licensed for
   `Analytics`.
2. Exact match on the folded index. With step 1 done properly this is what resolves `pt-br` → `pt-BR`,
   `es-ar` → `es_AR` and `uz` → `uzb`; there is no separate alias step.
3. Progressive truncation, one subtag at a time: `zh-hans-cn` → `zh-hans`, which hits catalog `zh-Hans`. One
   subtag at a time and not a single strip to the base language, and here the catalog settles it rather than
   taste: **`zh` is not a catalog key at all** (*verified* — `resources/languages.php` has `zh-Hans` and
   `zh-Hant` and no `zh`; 87 keys in total). Strip straight to the base language and Chinese resolves to
   nothing, so a forum full of Simplified Chinese requests would be told no pack exists. `sr-Cyrl`/`sr-Latn`
   are the same shape.
4. Otherwise `null`.

One consequence of `Analytics::requestedLocale()` folding case and separators but **not** aliases: the table can
hold `uz` and `uzb` as two rows for one language. They are two distinct requested tags and storing them apart is
right — but they resolve to one catalog entry, so grouping by entry sums them, which is the answer an admin
wants. Same mechanism as `tr` and `tr-tr`.

**`entryFor()` returns `null` for two genuinely different reasons, and that is acceptable.** A tag Flarum has no
pack for at all (`sw`, `zu` — real languages, no `flarum-lang` package) and a macrolanguage with more than one
catalog member (`no`, whose members `nb` and `nn` are both catalog keys; `ku` → `ckb`/`kmr`) both come back
`null`. Deliberately: arbitrating between Bokmål and Nynorsk on an admin's behalf is not this report's job, and
"47 people asked for `no`" is a useful thing to show even with no single package to link. So a report row
carries a nullable name/native/package, Phase 8 renders those as "no package available", and both cases are
reported rather than silently dropped. **Do not** filter unresolvable tags out — a language with no pack is the
one piece of demand an admin can do nothing about, and hiding it makes the totals lie.

Also note: `en` is in the catalog with `'package' => null` on purpose (§12 Phase 2 decision 2, for display
names), and it is never missing in practice because core ships it. Do not special-case it; the null package
handles it if it ever appears.

### The query

```php
$this->db->table(Analytics::TABLE)
    ->selectRaw('locale, SUM(requests) AS requests, SUM(unique_visitors) AS visitors')
    ->where('locale', '!=', '')
    ->groupBy('locale')
    ->get();
```

with `->where('date', '>=', Carbon::now()->subDays($days)->toDateString())` when `$days` is not null. Notes:

- **Read the clock inside the method, not in the constructor.** Same hazard as Phase 6, different container
  entry: memoising "today" on a long-lived object freezes the window. There is no reason to memoise it, so do
  not (§12 Phase 6 decision 1 has the full argument).
- **`SUM()` comes back as a string through PDO.** Cast to `int` before sorting or returning, or a JSON response
  ships `"requests": "41"` and the dashboard sorts lexicographically.
- **Referencing `Analytics::TABLE` rather than a second literal** keeps one name for the table. Reading a
  constant is not "changing `Analytics`".
- The `WHERE date >= ?` range is a leftmost prefix of the unique index on `(date, locale, country_code)`
  (§12 Phase 2 decision 3), so the index is usable for both the range and the grouping.
- `locale` is in the `GROUP BY`, so `ONLY_FULL_GROUP_BY` is satisfied.

**Sort by `requests` descending, tie-broken by locale code ascending.** The tie-break is not cosmetic: without
it, MySQL's row order for equal sums is unspecified and the integration test becomes intermittent. Sort in PHP
after grouping by catalog entry rather than in SQL, because the grouping happens after the query.

### Tests

**`tests/unit/LanguageCatalogTest.php`** — the whole interesting half, with no database. Use a **real**
`LocaleManager` (`new LocaleManager(new Translator('en'))` plus `addLocale()` calls) and a real `LocaleMatcher`
over it, per §9 and §12 Phase 3 decision 5 — never a Mockery double for these, or the test asserts which
methods were called instead of which languages are missing. Pass an expectation-free
`Mockery::mock(ConnectionInterface::class)` as the connection: every test in this file must go through
`missingFrom()`, so any query at all is a bug, and the bare double makes that an assertion (§12 Phase 6
decision 6). Point the constructor at the real `resources/languages.php` — it is committed data, not a fixture,
and testing against a stub catalog would prove nothing about the file that ships.

Cases that each pin a decision above:

- `ja` requested, not installed → missing, name `Japanese`, package `flarum-lang/japanese`.
- `tr-tr` requested, `tr` installed → **absent** from the report (the `LocaleMatcher` question).
- `pt-br` requested, `pt` installed but not `pt-BR` → **absent** (the filter-before-group ordering).
- `pt-br` requested, neither installed → missing, and resolves to the `pt-BR` entry, not `pt`.
- `zh-hans-cn` → the `zh-Hans` entry (truncation, and the mixed-case key survives). Assert the entry, not just
  that something was found: this is the case a strip-to-base-language shortcut resolves to `null`.
- `uz` → the `uzb` entry, and `uzb` → the same entry (the alias, folded on both sides, in both directions).
- `es-ar` → the `es_AR` entry (the underscore key, reached through the folded index).
- `no` with `nb` installed → absent; `no` with neither installed → present with a null package.
- `sw` → present with a null name and package.
- `''` → never present, whatever its volume.
- `tr` and `tr-tr` both requested and neither installed → **one** row with the volumes summed.
- Equal volumes → deterministic order.

**`tests/integration/MissingLanguagesTest.php`** — the query, end to end. Seed rows with
`$this->database()->table('language_detection_stats')->insert(...)` rather than by sending requests: the report
is about aggregation across days and locales, and building that history through the middleware would take
dozens of requests and pin the test to today's date. Copy Phase 5's deferred-boot pattern (§12 Phase 5
decision 8) if any test needs `setting()`; register locales on the `LocaleManager` singleton as
`DetectionTest`/`StatisticsTest` do, and resolve `LanguageCatalog` from the container so the autowiring claim
above is actually exercised. Cases: rows across several dates aggregate; `$days` excludes rows outside the
window while keeping rows inside it; `null` includes everything; `''` is excluded; a locale that is installed
is excluded; and the sums are integers rather than numeric strings.

### Verification available in this environment

Unchanged and still binding: **there is no PHP here** — no phpunit, no `php -l`, no composer. CI is the only
gate. **Never state or imply that the tests pass.** Report what was written and that it is unverified until CI
runs, and say plainly that the aggregate query itself is unexercised until the integration suite runs against
real MySQL — the unit suite deliberately never issues it. What *can* be checked here, and should be: that every
catalog key the tests name really exists in `resources/languages.php` with the spelling asserted (grep it — this
is the one Phase 7 claim that is checkable locally, and `es_AR` versus `es-AR` is exactly the kind of thing that
fails in CI for no good reason), plus the usual structural pass — UTF-8 without BOM, LF endings, no tabs, no
trailing whitespace, final newline, balanced braces/parens/brackets, `! ` spacing per `.styleci.yml`,
alphabetical imports.

Finish by adding a `CHANGELOG.md` line under `## [Unreleased] / ### Added`, then commit per the convention in
the status header above. Close Phase 7 out the way every phase closes: record what was built and decided in
§12, and write the Phase 8 work order as §19 — and make sure that work order carries the two boundaries this
one depends on, namely that `Api/MissingLanguagesController` calls `LanguageCatalog::missing()` instead of
re-querying, and that the display strings for null names and null packages are Phase 8's to add.

---

## 19. Phase 8 work order — ✅ implemented, kept as the dashboard specification

> Self-contained on purpose: a fresh session should be able to build Phase 8 from this section plus the
> cross-references below, without re-doing discovery. API claims marked *verified* were read out of `vendor/`
> and out of this repository on 2026-08-26 at the locked versions (`flarum/core` 1.8.19,
> `illuminate/database` v8.83.27), except the one marked as fetched from the network.

**Read first, in this order:** §9's "Admin UI idiom to mirror" (the LESS vocabulary, and the instruction that
the trend chart is inline SVG with **no chart library**), §9's admin-API notes (the `RequestHandlerInterface` →
`assertAdmin()` → `JsonResponse` idiom, and the custom-payload idiom), §8 (why there is no permission and why
the controllers are named `…Controller`), §6 (the table, and why `country_code` is `''` rather than null), §18
(the missing-languages report, which Phase 8 renders and **must not re-query**), and §13 risk 10 (why this
phase cannot finish in this environment — read it before promising anyone a working page).

**Goal:** put everything the previous five phases collected in front of an admin, on one page, in the
`fof/badges` idiom — summary cards, a languages table, the missing-languages table, a countries table, a
7/30/90-day trend, and the five settings — and expose the two JSON endpoints that feed it.

### Scope discipline — what Phase 8 must NOT touch

- **No cleanup, no retention, no deletes.** Phase 9 owns the `language-detection:cleanup` command, its
  `Extend\Console` scheduling, and the manual "Delete old statistics" admin action with its
  `Api/CleanupController`. Phase 8 renders the `retention_days` *setting* (it is already declared in
  `extend.php`) and issues **no** `DELETE` of any kind. Note that §11 and §12's old Phase 8 stub said "the three
  API endpoints", counting `CleanupController` — **Phase 8 ships two endpoints**, and the third arrives with the
  command that shares its logic.
- **Do not re-query the missing-languages report.** `Api/MissingLanguagesController` calls
  `LanguageCatalog::missing($days)` and serialises what it returns. Re-deriving that aggregate in
  `src/Statistics.php` would fork the one non-obvious decision Phase 7 exists to make — filter by
  `LocaleMatcher::match()` *before* grouping by pack (§18, and the class docblock) — and the two copies would
  drift on the first bug fix.
- **Do not change any detection or counting class.** `BrowserLanguageParser`, `LocaleMatcher`, `CountryLanguage`,
  `IpCountryLookup`, `LanguageDetector`, `Analytics`, `BotDetector`, `Middleware/DetectLanguage` and
  `LanguageCatalog` are all specified by §14–§18 and all done. Phase 8 reads. In particular **do not add
  anything to the request path**: the admin page must cost a forum visitor nothing.
- **No migration.** Every figure on the page comes out of the existing three indexes. If a query seems to need a
  new column, it is the query that is wrong — see §6's "fallback requests need no extra column".
- **Do not touch `resources/languages.php`, `resources/countries.php` or the `.dat` files.**
- **Do not start Phase 10's review, and do not begin the Flarum 2.x upgrade.**

### Deliverables

```
src/Statistics.php
src/Api/StatisticsController.php
src/Api/MissingLanguagesController.php
extend.php                                    (routes + admin payload; first change since Phase 4)
locale/en.yml  locale/tr.yml                  (dashboard keys; settings/ip_data/cleanup keys exist already)
less/admin.less                               (currently 0 bytes)
js/src/admin/index.ts                         (currently a TODO stub)
js/src/admin/components/LanguageDetectionPage.tsx
js/src/admin/components/StatsCards.tsx
js/src/admin/components/LanguagesTable.tsx
js/src/admin/components/MissingLanguages.tsx
js/src/admin/components/CountriesTable.tsx
js/src/admin/components/TrendChart.tsx
js/src/admin/components/SettingsTab.tsx
tests/integration/ApiTest.php
tests/unit/StatisticsQueryTest.php
CHANGELOG.md
```

**Commit this phase in two commits, backend then frontend.** Not tidiness: the backend half is gated by CI's
MySQL run and the frontend half is gated by nothing that can prove it works (§13 risk 10). One commit would let
a single green tick stand for both.

**On that unit test's name.** `tests/integration/StatisticsTest.php` already exists (Phase 6's counting tests).
A second file named `StatisticsTest.php` under `tests/unit/` would work — the suites are separate and neither
directory is autoloadable (§12 Phase 6 decision 7) — but it is confusing to grep and to talk about, hence
`StatisticsQueryTest.php` above.

### `src/Statistics.php`

Constructor: `__construct(ConnectionInterface $db, LanguageCatalog $catalog, LocaleMatcher $matcher)`. No
`LocaleManager` — "can the forum serve this?" is `LocaleMatcher::match()` and nothing else, which is Phase 7
decision 1 and holds here for the same reason. Autowires with no registration.

Four methods, one aggregate each, all windowed by `$days` the same way `LanguageCatalog::missing()` is —
`Carbon::now()->subDays(max(1, $days) - 1)->toDateString()`, so seven days is today and the six before it.
**Copy that expression exactly**; a dashboard whose "last 7 days" card disagrees with its own seven-bar chart is
worse than no card at all.

- `summary(int $days): array` → `['requests', 'visitors', 'languages', 'countries', 'served', 'unserved']`.
  `languages` counts **distinct non-empty** locales and `countries` distinct **non-empty** country codes —
  `COUNT(DISTINCT locale)` counts `''` as a value, so without the guard every forum reports one language more
  than it has. `served`/`unserved` are request sums split by `LocaleMatcher::match()`, which is §6's fallback
  signal computed at query time; do that split in PHP over the grouped rows, not in SQL.
- `languages(int $days): array` → one row per requested locale: `['locale', 'name', 'native', 'served',
  'requests', 'visitors']`, ordered by requests desc then by locale (same tie-break reasoning as §18 — MySQL's
  order for equal sums is unspecified). `name`/`native` come from `LanguageCatalog::entryFor()` and are null for
  a tag no pack answers. `locale === ''` **stays** in this table as the "no preference stated" row: it is real
  traffic and often the largest bucket, and it is the missing-languages report, not this one, that has to
  exclude it. Give it its own translated label; do not render an empty cell.
- `countries(int $days): array` → `['country', 'requests', 'visitors']`, same ordering rule. `''` is "Unknown"
  (§6) and is a row like any other.
- `trend(int $days): array` → one entry per **calendar day in the window**: `['date', 'requests', 'visitors']`,
  oldest first. `GROUP BY date` returns only the days that have rows, so **zero-fill the gaps in PHP** or the
  chart silently compresses quiet days and draws the wrong shape.

Cast every `SUM()` and `COUNT()` to `int` — they arrive from PDO as strings (§12 Phase 7, and
`MissingLanguagesTest::test_the_totals_are_integers_and_not_the_strings_the_driver_returns` for why it matters).

**`SUM(unique_visitors)` is not a count of people, and the UI must not claim it is.** The column is incremented
once per visitor per day (§6), so a reader who visits on three days contributes three. Over a 30-day window the
figure is "daily visitors, summed" — a real and useful number, and not the same thing as 30-day uniques. Keep
the API field named `visitors`, for consistency with Phase 7's report, and put the honesty in the locale string:
label the card something like "Daily visitors" with help text saying a visitor is counted once per day. Do not
invent a second field name for the same column.

### The two endpoints

`Extend\Routes('api')` (*verified* — `vendor/flarum/core/src/Extend/Routes.php`, whose docblock states that a
handler "should implement \Psr\Http\Server\RequestHandlerInterface"):

```php
(new Extend\Routes('api'))
    ->get('/language-detection/statistics', 'huseyinfiliz-language-detection.statistics',
        Api\StatisticsController::class)
    ->get('/language-detection/missing', 'huseyinfiliz-language-detection.missing',
        Api\MissingLanguagesController::class),
```

Each controller: `RequestUtil::getActor($request)` → `$actor->assertAdmin()` → `new JsonResponse([...])`. All
three verified present at the locked version — `Flarum\Http\RequestUtil::getActor(): User`,
`User::assertAdmin()` (`src/User/User.php:659`), and `Laminas\Diactoros\Response\JsonResponse`. There is **no
permission to check** and none to declare: §8 settled that, because Flarum 1.x's admin frontend has no
non-admin subject to gate.

**`days` is a whitelist, not an integer.** Read `$request->getQueryParams()['days'] ?? null` and accept only
`7`, `30` and `90`; anything else — absent, non-numeric, `0`, negative, `10000` — becomes `30`. The UI offers
exactly those three, so anything else is either a typo or someone poking at the endpoint, and a whitelist makes
that a non-question instead of a clamping puzzle. Do **not** pass `null` through to
`LanguageCatalog::missing(null)` from the web: all-time is a real code path with real tests, and nothing in the
UI asks for it.

Payload shapes — flat, with field names identical to what `Statistics` and `LanguageCatalog` return, so there is
no translation layer to get wrong:

```
GET /api/language-detection/statistics?days=30
{ "days": 30, "summary": {…}, "languages": [...], "countries": [...], "trend": [...] }

GET /api/language-detection/missing?days=30
{ "days": 30, "missing": [ { locale, name, native, package, requests, visitors, tags } ] }
```

**No row caps, and say why in a comment.** Every dimension here is naturally bounded — countries by 250,
distinct locales by what browsers actually send — and a silent top-N would make an admin's totals disagree with
their own table. If a cap ever becomes necessary it has to be visible in the payload.

`extend.php` also gains the IP-dataset payload, so `admin.ip_data.notice` has a date to interpolate (idiom
*verified* in §9): `(new Extend\Frontend('admin'))->content(fn (Document $d) => $d->payload[…] = …)`, reading
`resources/ip-data.php`. Handle the file being absent — `admin.ip_data.notice_unavailable` already exists for
exactly that case.

### The admin page

Registration is one line in `js/src/admin/index.ts` (*verified* —
`vendor/flarum/core/js/dist-typings/admin/utils/ExtensionData.d.ts`):

```ts
app.extensionData.for('huseyinfiliz-language-detection').registerPage(LanguageDetectionPage);
```

`LanguageDetectionPage extends ExtensionPage` (*verified* — `ExtensionPage<Attrs extends ExtensionPageAttrs>
extends AdminPage<Attrs>`): override `content(vnode)`. `AdminPage` already supplies `settings`,
`setting(key, fallback?)`, `buildSettingComponent(entry)`, `dirty()`, `isChanged()`, `saveSettings(e)` and
`submitButton()`. Tabs are local component state, not routes — nothing needs a deep link, and a route would mean
a resolver plus a second registration for no gain.

Data loading: `app.request<T>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/language-detection/…',
params: { days } })`. `app.request` is typed `<ResponseType>(options: FlarumRequestOptions<ResponseType>):
Promise<ResponseType>`, and `FlarumRequestOptions` extends Mithril's `RequestOptions`, so `method` and `params`
type-check (*verified* — `common/Application.d.ts:20-34, 256`). Fire both endpoints together and render a
`LoadingIndicator` until both settle; changing the window refetches both, so the cards and the tables are always
from one instant.

Settings tab: `buildSettingComponent()` handles all five. `detection_order` and `retention_days` are `'select'`,
which **requires both an `options` map and a `default`** (*verified* — `SelectSettingComponentOptions` in
`admin/components/AdminPage.d.ts`); `enable_analytics` and `ignore_bots` are `'bool'`; `default_locale` is a
select whose options come from **`app.data.locales`**, a `Record<string, string>` of installed packs (*verified*
— `ApplicationData.locales`, `common/Application.d.ts:99`, populated by `Frontend\Content\CorePayload`, which
`flarum.frontend.factory` applies to *every* frontend including admin, so it is there without an extender). Its
first option is `''` → `admin.settings.default_locale_forum_default`, which `locale/en.yml` already has.

Two typing traps, worth knowing before `tsc` finds them in CI:

- **Settings are strings.** `AdminPage`'s `SettingValue = string` and `AdminApplicationData.settings` is
  `Record<string, string>` (*verified*). `enable_analytics` is `'1'`, and `'0'` is truthy in JS — compare against
  `'1'` explicitly, never `if (setting())`.
- **`app.data[…]` is `unknown`.** `ApplicationData` ends in `[key: string]: unknown`, so the IP-dataset payload
  needs an explicit type assertion at the read site. Assert once, in one place, not at every use.

### LESS and the trend chart

`less/admin.less` is empty; fill it from §9's idiom — the `.card-style()` mixin, 24px page padding, 1400px max
width, the pill tab bar with `.active`, `.Button-badge` count pills, the `repeat(auto-fit, minmax(150px, 1fr))`
stat grid, `.CardList` grids driven by `--grid-cols` collapsing to a column at 768px, and the 48px 0.4-opacity
empty state. **Every CSS custom property gets a LESS fallback** — house rule, not preference.

The chart is hand-written inline SVG using Flarum's CSS variables. No library, no new palette (spec §36). It has
to survive an all-zero window (a forum that just switched analytics on) without dividing by zero, and a
single-point window without drawing a line from a point to itself.

### Locale keys

`locale/en.yml` already carries `admin.settings.*`, `admin.ip_data.*` and `admin.cleanup.*`. Phase 8 adds the
dashboard: tab labels, the six summary cards with help text, column headers for all three tables, the "no
preference stated" label for `locale === ''`, "Unknown" for `country_code === ''`, the trend's window buttons,
an empty state per table, and a load-failure message.

**Two strings Phase 7 owes you.** `missing[].name` and `missing[].package` are **both nullable**, and null means
two different things (§18, Phase 7 decision 7): no pack exists at all (`sw`, `zu`), or several could and picking
one would be a guess (`no`, `ku`, `sr`, `zh`). The report deliberately does not distinguish them, so one honest
string has to cover both — something like "No single language pack" rather than "no package available", which
reads as "Flarum does not translate this" and is wrong half the time. Where `package` is non-null, link to
`https://packagist.org/packages/{package}` (the convention `README.md` already uses).

`locale/tr.yml` mirrors every key with **matching ICU placeholders**. §12's Phase 2 record notes both files were
verified key-for-key and placeholder-for-placeholder; Phase 8 roughly doubles them, so check it the same way.

### Tests

`tests/integration/ApiTest.php` is where Phase 8's real coverage lives, because it runs against MySQL in CI:

- A guest gets 403, a non-admin gets 403, the admin gets 200. `authenticatedAs => 1` is the admin the setup
  script creates, and `RetrievesAuthorizedUsers::normalUser()` is id 2 and **not** an admin (*verified* —
  `vendor/flarum/testing/src/integration/RetrievesAuthorizedUsers.php:14-23`). Assert the 403s first: they are
  the only security property this phase has, and a route registered without `assertAdmin()` would publish a
  forum's traffic to anyone who guessed the URL.
- Seeded rows in, expected JSON out, for both endpoints. Reuse `MissingLanguagesTest`'s `seed()` idiom —
  `country_code` is part of the unique key, so every row needs one.
- `days=7` excludes a row from day 10; `days=999` and `days=abc` both behave as `30`.
- The trend has exactly `$days` entries, oldest first, with a seeded gap zero-filled.
- `COUNT(DISTINCT locale)` does not count `''`: seed `''` plus two real locales, assert `languages === 2`.
- An empty table returns a well-formed payload of zeros — not nulls, not an error.

`tests/unit/StatisticsQueryTest.php` covers whatever pure shaping `Statistics` does — the zero-fill, the
served/unserved split, the ordering and the tie-break. Split those out of the query methods the way Phase 7
split `missingFrom()` out of `missing()`, and hand the connection an expectation-free
`Mockery::mock(ConnectionInterface::class)` so that "this half issues no query" is an assertion rather than a
convention (§12 Phase 6 decision 6).

There is **no frontend test harness** in this repository — `js/package.json` has build, format and type-check
scripts and nothing else (*verified*). The TSX is covered by `tsc` and `prettier` in CI and by nothing else.
Write it accordingly: keep logic out of the components and in small exported functions, if you want any of it
checkable at all.

### Verification available in this environment

Unchanged and still binding: **there is no PHP here** — no phpunit, no `php -l`, no composer. CI is the only
gate. **Never state or imply that the tests pass.**

Phase 8 adds a second, sharper limitation, and it must be reported rather than glossed: **there is no node, npm
or yarn either.** For the frontend half nothing can be checked here — not types, not formatting, and above all
not the bundle. Read §13 risk 10 in full before writing this phase's report. The short version:
`js/dist/admin.js` is a tracked 635-byte stub, CI type-checks and format-checks the source but does **not** build
or commit a bundle, and therefore **the admin page will not appear in any browser until someone runs `yarn build`
in `js/` and commits the result.** A green CI on this phase means the TypeScript compiles and is formatted. It
does not mean the page exists. Say that plainly, in those terms, and put the `yarn build` step in the commit
message so it cannot be lost before release.

What *can* be checked here, and should be: that every `app.translator.trans()` key the TSX names exists in
**both** `locale/en.yml` and `locale/tr.yml` with matching placeholders (grep it — this is the one frontend claim
that is checkable locally, and a typo'd key renders as the raw key in an admin's browser); that every route name,
query-param name and payload field used in the TSX matches the PHP exactly; and the usual structural pass on the
PHP — UTF-8 without BOM, LF endings, no tabs, no trailing whitespace, final newline, balanced
braces/parens/brackets, `! ` spacing per `.styleci.yml`, alphabetical imports. For the TSX, match
`@flarum/prettier-config@1.0.0` by hand: `{"printWidth": 150, "singleQuote": true, "tabWidth": 2,
"trailingComma": "es5"}` (*verified by fetching the resolved tarball named in `js/yarn.lock`* — not from
`vendor/`, since that package is not installed here).

Finish by adding a `CHANGELOG.md` line under `## [Unreleased] / ### Added`, then commit per the convention in the
status header above — backend and frontend as two commits, for the reason given above. Close Phase 8 out the way
every phase closes: record what was built and decided in §12, and write the Phase 9 work order as §20. Carry
these boundaries into it: the cleanup command and the manual admin action **share one implementation** (the
command must not re-derive the retention cutoff that `Api/CleanupController` uses, or the other way round);
`retention_days` is already declared in `extend.php` with a default of `'90'`, and `admin.cleanup.*` already has
its four locale strings, including `retention_disabled` for the never-delete case; and `Extend\Console`'s
`schedule(command, callback, args = [])` is the scheduling API (§9). Also raise, for a later phase to decide, the
open question Phase 7 recorded and deliberately did not answer: **`zh-CN` and `zh-TW` resolve to nothing**, in
`LocaleMatcher` at detection time as much as in `LanguageCatalog`, so Simplified- and Traditional-Chinese readers
are neither served nor correctly reported. Fixing it means a region→script table and a change to §14's matcher,
which is why Phase 7 pinned the behaviour in a test instead of guessing at it.
