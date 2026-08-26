import Component from 'flarum/common/Component';
import icon from 'flarum/common/helpers/icon';
import type { ComponentAttrs } from 'flarum/common/Component';

import { count, languageLabel, trans } from '../format';
import type { MissingRow } from '../types';

export interface MissingLanguagesAttrs extends ComponentAttrs {
  missing: MissingRow[];
}

/**
 * The languages visitors asked for that this forum cannot show, and what would fix each one.
 *
 * The rows come straight from `LanguageCatalog::missing()` -- this component decides nothing about
 * what belongs here. In particular a language is already rolled up by pack before it arrives, so
 * `de`, `de-DE` and `de-AT` are one suggestion and not three, and the tags that rolled up are shown
 * underneath rather than being dropped.
 */
export default class MissingLanguages extends Component<MissingLanguagesAttrs> {
  view() {
    const missing = this.attrs.missing;

    return (
      <div className="LanguageDetection-section">
        <h3 className="LanguageDetection-section-title">{trans('dashboard.missing_title')}</h3>
        <p className="helpText">{trans('dashboard.missing_description')}</p>
        {missing.length === 0 ? this.empty() : this.table(missing)}
      </div>
    );
  }

  table(missing: MissingRow[]): Mithril.Children {
    return (
      <div className="LanguageDetection-table CardList CardList--cols-4">
        <div className="CardList-header">
          <span>{trans('dashboard.missing_column_language')}</span>
          <span className="CardList-number">{trans('dashboard.missing_column_requests')}</span>
          <span className="CardList-number">{trans('dashboard.missing_column_visitors')}</span>
          <span>{trans('dashboard.missing_column_package')}</span>
        </div>
        {missing.map((row) => this.row(row))}
      </div>
    );
  }

  row(row: MissingRow): Mithril.Children {
    return (
      <div className="CardList-row">
        <span className="LanguageDetection-name">
          <strong>{languageLabel(row.locale, row.name)}</strong>
          {row.native === null || row.native === row.name ? null : <span className="LanguageDetection-native">{row.native}</span>}
          <code className="LanguageDetection-code">{row.locale}</code>
          {this.variants(row.tags)}
        </span>
        <span className="CardList-number">{count(row.requests)}</span>
        <span className="CardList-number">{count(row.visitors)}</span>
        <span>{this.pack(row.package)}</span>
      </div>
    );
  }

  /**
   * The other spellings that rolled up into this row, when there were any.
   */
  variants(tags: string[]): Mithril.Children {
    if (tags.length < 2) return null;

    return <span className="LanguageDetection-variants">{trans('dashboard.missing_variants', { tags: tags.join(', ') })}</span>;
  }

  /**
   * The package to install, or the one honest label for both ways of having none.
   *
   * A null package means either that Flarum publishes no pack for the language or that it publishes
   * several and picking one would be a guess. The report does not distinguish them, so this must not
   * say "no package available" -- which reads as "Flarum does not translate this" and is wrong about
   * half the time.
   */
  pack(name: string | null): Mithril.Children {
    if (name === null) return <span className="LanguageDetection-noPack">{trans('dashboard.missing_no_package')}</span>;

    return (
      <a className="LanguageDetection-pack" href={'https://packagist.org/packages/' + name} target="_blank" rel="noopener noreferrer">
        <code>{name}</code>
      </a>
    );
  }

  empty(): Mithril.Children {
    return (
      <div className="LanguageDetection-empty">
        {icon('fas fa-check-circle')}
        <p>{trans('dashboard.missing_empty')}</p>
      </div>
    );
  }
}
