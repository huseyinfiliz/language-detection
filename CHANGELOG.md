# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial extension scaffolding: settings defaults, the `language_detection_stats`
  migration, the bundled language catalog and country-to-language map, and English and
  Turkish translations.
- Browser language detection: an `Accept-Language` parser that honours quality values,
  and a matcher that resolves the requested tags to an installed Flarum locale by exact
  match, progressive subtag truncation, or an unambiguous regional variant.
- Automatic language selection: a forum middleware that applies the detected locale to
  page views, remembering it in a cookie for guests and in the `locale` preference for
  signed-in members. A visitor who has already chosen a language is never overridden, and
  a language that is not installed is never applied.
- Country-based detection for visitors whose browser asks for nothing useful, from a
  bundled 2.2 MB dataset built from the five regional internet registries' published
  statistics, or from a country header set by Cloudflare, CloudFront, Vercel, Fastly, App
  Engine or a web server's GeoIP module. No external service is contacted, no API key is
  needed, and the visitor's address is read, used and dropped -- never stored or logged.
  The `detection_order` setting now decides which source wins when both have an answer.
- Analytics: every page view is counted against a `(date, requested_locale, country)` row,
  so an admin can see both what languages their visitors ask for and -- crucially -- which
  languages they ask for that the forum does not yet have. Unique visitors are counted with
  a date-only cookie that expires at midnight; nothing identifying is stored server-side.
  Bots are excluded by default (`ignore_bots` setting); analytics can be switched off
  entirely (`enable_analytics` setting).
- Missing-languages report: reads the collected statistics back and lists the languages
  visitors asked for that the forum could not serve, each with the Composer package that
  would fix it. A language the forum can already serve is never listed, however the visitor
  spelled their request, and requests for one language are rolled up into a single row --
  so `de`, `de-DE` and `de-AT` add up to one suggestion rather than three small ones.
  Languages Flarum publishes no single pack for are still reported, without a package,
  rather than being quietly dropped.
- Two administrator-only JSON endpoints, `GET /api/language-detection/statistics` and
  `GET /api/language-detection/missing`, both accepting `?days=7|30|90`. The first returns
  the totals, the per-language and per-country breakdowns and a day-by-day trend with quiet
  days filled in; the second returns the missing-languages report. Requests are split three
  ways -- served, unserved, and visitors who stated no language preference at all -- so the
  totals add up without the largest bucket on most forums being miscounted as unserved.
