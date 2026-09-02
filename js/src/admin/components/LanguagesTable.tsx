import Component from 'flarum/common/Component';
import icon from 'flarum/common/helpers/icon';
import type { ComponentAttrs } from 'flarum/common/Component';

import { count, languageLabel, trans } from '../format';
import type { LanguageRow } from '../types';

export interface LanguagesTableAttrs extends ComponentAttrs {
  languages: LanguageRow[];
}

/**
 * Every language visitors asked for, busiest first.
 *
 * The `''` row -- visitors whose browser named nothing usable -- is dropped rather than shown under
 * a label of its own. It carries no `locale` an admin could act on (there is no pack to install for
 * "nothing stated"), it is usually the single largest row in the table, and sitting at the top of a
 * list titled "Languages visitors asked for" it reads as a language rather than the absence of one,
 * which is confusing rather than informative. The figure is not lost: it still feeds the page-view
 * total on the summary cards above, via `unstated` in `Summary`, which is what that card is for.
 */
export default class LanguagesTable extends Component<LanguagesTableAttrs> {
  view() {
    const languages = this.attrs.languages.filter((row) => row.locale !== '');

    return (
      <div className="LanguageDetection-section">
        <h3 className="LanguageDetection-section-title">{trans('dashboard.languages_title')}</h3>
        {languages.length === 0 ? this.empty() : this.table(languages)}
      </div>
    );
  }

  table(languages: LanguageRow[]): Mithril.Children {
    return (
      <div className="LanguageDetection-table CardList CardList--cols-4">
        <div className="CardList-header">
          <span>{trans('dashboard.languages_column_language')}</span>
          <span className="CardList-number">{trans('dashboard.languages_column_requests')}</span>
          <span className="CardList-number">{trans('dashboard.languages_column_visitors')}</span>
          <span>{trans('dashboard.languages_column_status')}</span>
        </div>
        {languages.map((row) => this.row(row))}
      </div>
    );
  }

  row(row: LanguageRow): Mithril.Children {
    return (
      <div className="CardList-row">
        <span className="LanguageDetection-name">
          <strong>{languageLabel(row.locale, row.name)}</strong>
          {row.native === null || row.native === row.name ? null : <span className="LanguageDetection-native">{row.native}</span>}
          <code className="LanguageDetection-code">{row.locale}</code>
          {this.variants(row.tags)}
        </span>
        {/*
          `data-label` is read by the mobile stylesheet to prefix each value with the column header
          it belongs to, since the header row itself is hidden below the breakpoint (there is no
          room to keep four columns side by side, and a single stacked column with no labels reads
          as a wall of unexplained numbers). The label strings are the same trans keys the header
          already uses, so the two can never drift apart.
        */}
        <span className="CardList-number" data-label={trans('dashboard.languages_column_requests')}>
          {count(row.requests)}
        </span>
        <span className="CardList-number" data-label={trans('dashboard.languages_column_visitors')}>
          {count(row.visitors)}
        </span>
        <span data-label={trans('dashboard.languages_column_status')}>{this.status(row.served)}</span>
      </div>
    );
  }

  /**
   * The other spellings that rolled up into this row, when there was more than one.
   *
   * A row's `locale` is the installed (or catalog) spelling that answers it -- `tr`, `zh-Hans` -- not
   * necessarily what any single visitor typed into their browser. Where several raw tags collapsed
   * onto one row (`tr-TR` and `TR` onto `tr`; `zh-CN` onto `zh-Hans`), this is what shows a visitor's
   * own tag still exists in the data, instead of just vanishing into a bigger number under a spelling
   * they never sent.
   */
  variants(tags: string[]): Mithril.Children {
    if (tags.length < 2) return null;

    return <span className="LanguageDetection-variants">{trans('dashboard.languages_variants', { tags: tags.join(', ') })}</span>;
  }

  /**
   * Served, not served, or nothing at all -- the three states the API distinguishes.
   */
  status(served: boolean | null): Mithril.Children {
    if (served === null) return null;

    const state = served ? 'served' : 'unserved';
    const label = served ? trans('dashboard.languages_served') : trans('dashboard.languages_unserved');

    return <span className={'LanguageDetection-status LanguageDetection-status--' + state}>{label}</span>;
  }

  empty(): Mithril.Children {
    return (
      <div className="LanguageDetection-empty">
        {icon('fas fa-language')}
        <p>{trans('dashboard.languages_empty')}</p>
      </div>
    );
  }
}