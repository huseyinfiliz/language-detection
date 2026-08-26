import app from 'flarum/admin/app';

/**
 * The small helpers the dashboard's components share: the translation prefix, and the arithmetic
 * that is easier to get right in one place than in six.
 *
 * There is no frontend test harness in this repository, so nothing here is covered by anything but
 * `tsc`. Keeping it in plain exported functions at least means the parts that could be wrong are
 * the parts that are readable on their own.
 */

/**
 * Every key this extension's admin UI uses lives under here.
 *
 * Spelled out at the call sites rather than assembled from fragments, deliberately: a mistyped key
 * renders as the raw key in an admin's browser and nothing else notices, so the keys have to stay
 * greppable against `locale/en.yml` and `locale/tr.yml`.
 */
const PREFIX = 'huseyinfiliz-language-detection.admin.';

export function trans(key: string, parameters?: Record<string, unknown>) {
  return app.translator.trans(PREFIX + key, parameters);
}

/**
 * Just enough of `Intl.DisplayNames` to use it, described here rather than imported.
 *
 * `flarum-tsconfig` pins `lib` at es2019 and `Intl.DisplayNames` was not typed until es2021, so
 * there is no declaration to reach for. It is genuinely missing in older browsers too, which is why
 * every caller falls back to the bare country code rather than assuming a name.
 */
interface RegionNames {
  of(code: string): string | undefined;
}

type RegionNamesConstructor = new (locales: string[], options: { type: 'region' }) => RegionNames;

/**
 * Undefined until first asked for, then either an instance or a null meaning "this browser cannot".
 */
let regions: RegionNames | null | undefined;

function regionNames(): RegionNames | null {
  if (regions !== undefined) return regions;

  const constructor = (Intl as unknown as { DisplayNames?: RegionNamesConstructor }).DisplayNames;

  try {
    regions = constructor ? new constructor([app.translator.getLocale() || 'en'], { type: 'region' }) : null;
  } catch (e) {
    regions = null;
  }

  return regions;
}

/**
 * A country's name in the administrator's own language, falling back to its code.
 *
 * `''` is the caller's problem and not this function's: it is not a country, and "Unknown" is a
 * translated string, which this has no business producing.
 */
export function countryName(code: string): string {
  if (!/^[A-Za-z]{2}$/.test(code)) return code;

  const upper = code.toUpperCase();
  const names = regionNames();

  if (names === null) return upper;

  try {
    return names.of(upper) || upper;
  } catch (e) {
    return upper;
  }
}

/**
 * How a requested language is named in a table cell.
 *
 * Two cases have no name to print and they are not the same case. `locale === ''` is a visitor whose
 * browser stated no preference, which is real traffic and often the biggest row on the page; a null
 * `name` is a tag no bundled language pack answers, which is a language Flarum simply does not know
 * about. Neither may render as an empty cell, and neither may borrow the other's label.
 */
export function languageLabel(locale: string, name: string | null) {
  if (locale === '') return trans('dashboard.languages_no_preference');

  return name === null ? trans('dashboard.languages_unnamed') : name;
}

/**
 * A count, grouped for readability in the administrator's own language.
 *
 * Five-figure page-view totals are the normal case on a forum with any traffic, and `41230` is
 * genuinely harder to read at a glance than `41,230`.
 */
export function count(value: number): string {
  try {
    return value.toLocaleString(app.translator.getLocale() || undefined);
  } catch (e) {
    return String(value);
  }
}

/**
 * `part` as a whole-number percentage of `whole`, and zero rather than `NaN` when `whole` is.
 *
 * The zero case is a forum that switched analytics on this morning, which is every forum on its
 * first day -- and `0 / 0` renders on a card as "NaN%".
 */
export function percentage(part: number, whole: number): number {
  if (whole <= 0) return 0;

  return Math.round((part / whole) * 100);
}

/**
 * The value the tallest bar in a chart stands for.
 *
 * Never zero, and that is the whole point: an all-zero window is a forum that has just switched
 * analytics on, and scaling by its own maximum is how a chart turns that into a division by zero
 * instead of a flat axis. An empty array gives 1 for the same reason.
 */
export function scale(values: number[]): number {
  return Math.max(1, ...values);
}
