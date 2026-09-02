/**
 * The shapes the two admin endpoints return.
 *
 * Every field name is the PHP array key verbatim -- `src/Statistics.php` for the statistics payload,
 * `LanguageCatalog::missing()` for the missing one -- so there is no translation layer between the two
 * halves to fall out of step.
 */

/**
 * The windows the dashboard offers, and the only ones the API accepts.
 * `Api\AbstractController::WINDOWS` is the same three numbers.
 */
export const WINDOWS = [7, 30, 90];

export interface Summary {
  requests: number;
  /**
   * Daily visitors, summed. The column is incremented once per visitor per day, so somebody who reads
   * on three days contributes three -- which is why the card that shows this says so.
   */
  visitors: number;
  /** Distinct languages that were actually named. The `''` bucket is not one of them. */
  languages: number;
  /** Distinct countries that were actually placed. `''` is not one of them. */
  countries: number;
  served: number;
  unserved: number;
  /**
   * Page views whose browser named no language this extension could read. Neither served nor
   * unserved, and on most forums the largest of the three: see `Statistics`' class docblock.
   */
  unstated: number;
}

export interface LanguageRow {
  /**
   * `''` is the "no preference stated" row, and it is real traffic rather than a blank. Every other
   * value is the *resolved* locale that answers the row -- the installed pack's own spelling when
   * served, or the catalog key that would serve it otherwise -- not necessarily a tag any single
   * visitor typed. `Statistics::languagesFrom()` groups spelling variants (`tr-TR`, `TR` onto `tr`;
   * `zh-CN` onto `zh-Hans`) onto one row rather than showing the same language as several, and `tags`
   * is where the raw variants that were merged are still visible.
   */
  locale: string;
  name: string | null;
  native: string | null;
  /** Null for the `''` row, which asked for nothing and so can be neither. */
  served: boolean | null;
  requests: number;
  visitors: number;
  /**
   * Every raw tag that rolled up into this row, sorted -- e.g. `['tr', 'tr-TR']`. Length 1 for a row
   * with no variants to show; the `''` row's single entry is `['']`.
   */
  tags: string[];
}

export interface CountryRow {
  /** A two-letter code, or `''` for an address the lookup could not place. */
  country: string;
  requests: number;
  visitors: number;
}

export interface TrendPoint {
  /** `YYYY-MM-DD`. Every day in the window is here, including the quiet ones. */
  date: string;
  requests: number;
  visitors: number;
}

export interface StatisticsPayload {
  days: number;
  summary: Summary;
  languages: LanguageRow[];
  countries: CountryRow[];
  trend: TrendPoint[];
}

export interface MissingRow {
  locale: string;
  name: string | null;
  native: string | null;
  /**
   * Null when Flarum publishes no single pack for the language -- either none exists, or several do
   * and picking one would be a guess. The report does not distinguish the two, so nor may the label.
   */
  package: string | null;
  requests: number;
  visitors: number;
  /** Every tag that rolled up into this row, sorted -- e.g. `['de', 'de-AT', 'de-DE']`. */
  tags: string[];
}

export interface MissingPayload {
  days: number;
  missing: MissingRow[];
}

/**
 * `Content\AdminPayload`, read out of `app.data`.
 *
 * Three states: the whole value is null when no dataset is installed, and `date` is null when one is
 * installed but recorded no build date. Only the first means IP lookup is inactive.
 */
export interface IpDataset {
  date: string | null;
}

/**
 * What `POST /api/language-detection/cleanup` answers -- `Api\CleanupController`.
 *
 * `days` is null when retention is switched off, which is a different outcome from a run that found
 * nothing old enough.
 */
export interface CleanupResult {
  days: number | null;
  deleted: number;
}
