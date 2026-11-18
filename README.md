# Language Detection

A [Flarum](https://flarum.org) extension that automatically detects a visitor's preferred language and selects the most appropriate installed locale.

## Features

- **Browser language detection** — Uses the visitor's browser language preferences to determine their preferred locale.
- **IP-based country detection** — Detects the visitor's country locally using bundled IP range data, with support for IPv4 and IPv6.
- **Automatic locale selection** — Combines browser language and country information to select the best available Flarum language.
- **Country-to-language mapping** — Configure which languages should be preferred for specific countries.
- **Smart language matching** — Handles locale variants and ambiguous language codes such as `zh`, `sr`, `no`, and `ku`.
- **Native Flarum integration** — Resolves the locale before Flarum's `SetLocale` middleware instead of switching the language after the page has loaded.
- **Visitor statistics** — Optionally collect statistics about visitor countries and language requests.
- **Missing language tracking** — See which languages visitors request that are not currently installed.
- **Configurable fallback** — Define how the extension should behave when no suitable language can be detected.

## Requirements

- Flarum `^2.0.0`
- PHP `^8.3`
- A Flarum language pack for each locale you want to serve

## Installation

```bash
composer require huseyinfiliz/language-detection
```

Then enable **Language Detection** from the Flarum administration panel.

## Configuration

The extension provides settings for:

- Detection behavior and priority
- Country-to-language mappings
- Fallback locale
- Visitor and language statistics
- Missing language tracking

## Privacy

IP-based country detection is performed locally by the extension. A third-party IP geolocation API is not required.

Statistics collection is configurable and can be disabled by administrators.

## Translations

The extension ships with English translations.

Community translations are welcome.

## Development

Frontend development requires Node.js 24 LTS and Yarn Classic 1.22.22, matching CI.
Install PHP dependencies with Composer and JavaScript dependencies with Yarn:

```bash
composer install
yarn --cwd js install --frozen-lockfile
```

Run the checks and build the admin bundle:

```bash
composer test:unit
composer test:setup
composer test:integration
yarn --cwd js format-check
yarn --cwd js check-typings
yarn --cwd js check-typings-coverage
yarn --cwd js build
```

Integration tests require a dedicated test database. Configure the `DB_*` environment variables
supported by `flarum/testing` before running setup; do not point the test suite at a forum database.

Dependabot checks Composer, Yarn, and GitHub Actions dependencies weekly. Minor and patch updates
are grouped, while Flarum major-version updates require a deliberate compatibility review.
Node typings stay on the Node 24 major version until the build runtime is upgraded.
TypeScript major upgrades are coordinated with Flarum's shared tsconfig rather than applied automatically.

The scoped `type-coverage-core/minimatch` resolution selects the supported, patched 6.x line.
The upstream range also permits 7.x–10.x, which are affected by GHSA-g3hv-75wg-m249. Keep this
resolution until the coverage tool supports an unaffected newer major, then recheck coverage
generation before removing it.

## Links

- [Discuss](https://discuss.flarum.org/d/39779-language-detection-auto-switching-by-ip-system)
- [Packagist](https://packagist.org/packages/huseyinfiliz/language-detection)
- [GitHub](https://github.com/huseyinfiliz/language-detection)
- [Issues](https://github.com/huseyinfiliz/language-detection/issues)

## License

MIT.
