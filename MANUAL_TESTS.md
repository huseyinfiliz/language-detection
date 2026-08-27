Manual sanity checks before tagging the initial release. Run these on the live Flarum instance (extension enabled, `locale/en.yml` present, other locale packs installed if available):

1. Translation keys load: visit `/` as a guest with `Accept-Language: de-DE`. Confirm no raw translation keys appear in the UI (before `5e92094`, the default locale's keys rendered raw). Switch browser language to Turkish (`tr`) and reload -- all keys should load.

2. Language applied to guests: with `Accept-Language: de-DE`, confirm `locale` cookie (`language_detection_locale`) is set and the page renders in German if the `de` or `de_DE` pack is installed. With `Accept-Language: xx-XX` (unknown), confirm no cookie is set and the forum falls back to its default.

3. Signed-in users keep their preference: sign in, set profile language to Turkish, reload with `Accept-Language: de-DE`. Confirm Turkish stays (no cookie override) and analytics still records the request.

4. Analytics on/off: disable analytics in settings. Confirm `language_detection_stats` table receives no new rows; re-enable and confirm counting resumes. Confirm bot requests with `User-Agent: Googlebot` are excluded from counts (`BotDetector`).

5. Admin dashboard (`/admin`): visit `Overview`, `Languages`, `Missing`, `Countries`, `Settings`. Confirm no errors, cards show numbers, tables render, window buttons (7/30/90) work, language/missing tables list real rows. Confirm `Cleanup` button deletes rows past `retention_days`.

6. CLI cleanup: `php flarum language-detection:cleanup`. Confirm output reports `deleted: N` or `retention_disabled` when set to `0`. Confirm the same `Cleanup` object is used by the button and the scheduler.

7. Middleware position: confirm detection runs before core `SetLocale`. Changing `detection_order` to `ip_browser` should prefer IP over browser; with no matching country, browser should still work.

Report results back -- especially any raw translation keys or empty dashboard cards.
