import Component from 'flarum/common/Component';
import icon from 'flarum/common/helpers/icon';
import type { ComponentAttrs } from 'flarum/common/Component';

import { count, countryName, trans } from '../format';
import type { CountryRow } from '../types';

export interface CountriesTableAttrs extends ComponentAttrs {
  countries: CountryRow[];
}

/**
 * Where visitors came from, busiest first.
 *
 * `''` is an address the lookup could not place, and it stays in the table for the same reason the
 * blank language row does: a forum whose traffic is mostly unplaceable is a forum whose IP dataset
 * or edge headers are not doing what its admin thinks they are, and that is worth being able to see.
 */
export default class CountriesTable extends Component<CountriesTableAttrs> {
  view() {
    const countries = this.attrs.countries;

    return (
      <div className="LanguageDetection-section">
        <h3 className="LanguageDetection-section-title">{trans('dashboard.countries_title')}</h3>
        {countries.length === 0 ? this.empty() : this.table(countries)}
      </div>
    );
  }

  table(countries: CountryRow[]): Mithril.Children {
    return (
      <div className="LanguageDetection-table CardList CardList--cols-3">
        <div className="CardList-header">
          <span>{trans('dashboard.countries_column_country')}</span>
          <span className="CardList-number">{trans('dashboard.countries_column_requests')}</span>
          <span className="CardList-number">{trans('dashboard.countries_column_visitors')}</span>
        </div>
        {countries.map((row) => this.row(row))}
      </div>
    );
  }

  row(row: CountryRow): Mithril.Children {
    return (
      <div className="CardList-row">
        <span className="LanguageDetection-name">
          <strong>{row.country === '' ? trans('dashboard.countries_unknown') : countryName(row.country)}</strong>
          {row.country === '' ? null : <code className="LanguageDetection-code">{row.country}</code>}
        </span>
        {/*
          `data-label` mirrors the header text so the mobile stylesheet can prefix each value with
          the column it belongs to once the header row itself is hidden below the breakpoint.
        */}
        <span className="CardList-number" data-label={trans('dashboard.countries_column_requests')}>
          {count(row.requests)}
        </span>
        <span className="CardList-number" data-label={trans('dashboard.countries_column_visitors')}>
          {count(row.visitors)}
        </span>
      </div>
    );
  }

  empty(): Mithril.Children {
    return (
      <div className="LanguageDetection-empty">
        {icon('fas fa-globe')}
        <p>{trans('dashboard.countries_empty')}</p>
      </div>
    );
  }
}