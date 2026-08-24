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
