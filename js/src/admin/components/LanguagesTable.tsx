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
 * The `''` row stays in this table under its own label. It is the visitors whose browser named
 * nothing usable, it is usually the largest row here, and hiding it would leave the page-view total
 * on the cards above unaccounted for. Its status cell is deliberately blank rather than red: a
 * visitor who asked for nothing was not failed.
 */
export default class LanguagesTable extends Component<LanguagesTableAttrs> {
  view() {
    const languages = this.attrs.languages;

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
          {row.locale === '' ? null : <code className="LanguageDetection-code">{row.locale}</code>}
        </span>
        <span className="CardList-number">{count(row.requests)}</span>
        <span className="CardList-number">{count(row.visitors)}</span>
        <span>{this.status(row.served)}</span>
      </div>
    );
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
