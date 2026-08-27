# Language Detection

A [Flarum](https://flarum.org) 1.x extension that detects each visitor's preferred language and serves them the forum in it.

Features:

- **Browser detection** -- reads the `Accept-Language` header and matches it against installed language packs (`LocaleMatcher`).
- **IP country detection** -- bundled binary dataset (`resources/*.dat`), read at fixed offsets; works behind reverse proxies via CDN headers (`CF-IPCountry`, etc.).
- **Analytics** -- daily counts of page views, unique visitors, served/unserved languages, countries and a 7/30/90-day trend chart; nothing identifying (no user agent, no URL, no hashed address).
- **Missing languages report** -- which tags visitors ask for that the forum has no pack for, with links to the matching pack.
- **Cleanup** -- scheduled daily (`language-detection:cleanup`) plus an admin-page button; deletes rows past the configured retention.
- **Bot exclusion** -- crawlers excluded from counts by `BotDetector` (not from detection; a bot still gets the right language).

## Requirements

- PHP `^8.0` (`str_contains()` and PHP 8's stable sort are relied on).
- Flarum `^1.0` (tested against `1.x`).
- A language pack installed for each locale you want to serve (`flarum-lang/*`). English ships with core; others come from the community packs.

## Installation

```bash
composer require huseyinfiliz/language-detection
```

Then visit the admin dashboard (`/admin`) and enable the extension.

## Updating

```bash
composer update huseyinfiliz/language-detection
php flarum migrate
php flarum cache:clear
```

The bundled `js/dist/admin.js` is rebuilt by `flarum/action-build` on pushes to `1.x`; you do not need to run any frontend build commands locally.

## Settings (`Administrator / Extension Settings`)

| Setting | Default | Description |
|---|---|---|
| Detection order | `browser_ip` | `browser_ip` = browser first, then IP; `ip_browser` = reverse |
| Default locale | `''` | Fallback when nothing is detected; empty = forum default |
| Enable analytics | `1` | Turn the statistics table on or off |
| Ignore bots | `1` | Exclude automated traffic from counts |
| Retention days | `90` | Rows older than this are deleted; `0` = never delete |

## CLI Commands

```bash
# Manual cleanup (same logic as the scheduled daily run)
php flarum language-detection:cleanup
```

The command reads the `retention_days` setting, deletes everything older, and reports how many rows were deleted. It reports `retention_disabled` when the setting is `0` rather than reporting `deleted: 0`, because the two mean different things.

## Admin Dashboard

Five tabs (`overview / languages / missing / countries / settings`) over windows of 7, 30 or 90 days. The overview shows four cards (`requests`, `visitors`, `languages`, `countries`), the `served`/`unserved`/`unstated` split, and a trend chart. The missing-languages tab links directly to the matching `flarum-lang/*` package.

Only `locale/en.yml` is tracked in this repository. Other translations belong in the community language packs (`flarum-lang/*`).

## License

MIT.
