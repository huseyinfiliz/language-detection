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

- Flarum `1.x`
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

## Links

- [Discuss](https://discuss.flarum.org/d/39779-language-detection-auto-switching-by-ip-system)
- [Packagist](https://packagist.org/packages/huseyinfiliz/language-detection)
- [GitHub](https://github.com/huseyinfiliz/language-detection)
- [Issues](https://github.com/huseyinfiliz/language-detection/issues)

## License

MIT.
