import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';

import { count, percentage, trans } from '../format';
import type { Summary } from '../types';

export interface StatsCardsAttrs extends ComponentAttrs {
  summary: Summary;
}

interface CardSpec {
  field: keyof Summary;
  label: string;
  help: string;
  /** Whether this figure is a slice of the page-view total, and so worth a percentage. */
  share: boolean;
}

/**
 * The seven summary figures, and the keys that label them.
 *
 * The keys are spelled out rather than assembled from the field name so that every one of them is a
 * literal string that can be grepped against both locale files -- a mistyped key renders as the raw
 * key in an admin's browser, and nothing else in the toolchain notices.
 */
const CARDS: CardSpec[] = [
  { field: 'requests', label: 'dashboard.card_requests_label', help: 'dashboard.card_requests_help', share: false },
  { field: 'visitors', label: 'dashboard.card_visitors_label', help: 'dashboard.card_visitors_help', share: false },
  { field: 'languages', label: 'dashboard.card_languages_label', help: 'dashboard.card_languages_help', share: false },
  { field: 'countries', label: 'dashboard.card_countries_label', help: 'dashboard.card_countries_help', share: false },
  { field: 'served', label: 'dashboard.card_served_label', help: 'dashboard.card_served_help', share: true },
  { field: 'unserved', label: 'dashboard.card_unserved_label', help: 'dashboard.card_unserved_help', share: true },
  { field: 'unstated', label: 'dashboard.card_unstated_label', help: 'dashboard.card_unstated_help', share: true },
];

/**
 * The summary, one card per figure.
 *
 * Served, not served and no preference each carry their share of the total as well as their count.
 * That is not decoration: the question an admin brings to this page is "how much of my traffic is in
 * the wrong language", and a bare count of 4,000 only answers it if the total is also in view, which
 * it is not once you are four cards along.
 */
export default class StatsCards extends Component<StatsCardsAttrs> {
  view() {
    const summary = this.attrs.summary;

    return <div className="LanguageDetection-cards">{CARDS.map((card) => this.card(card, summary))}</div>;
  }

  card(card: CardSpec, summary: Summary): Mithril.Children {
    const value = summary[card.field];

    return (
      <div className={'LanguageDetection-card LanguageDetection-card--' + card.field}>
        <div className="LanguageDetection-card-value">
          {count(value)}
          {card.share ? <span className="LanguageDetection-card-share">{percentage(value, summary.requests)}%</span> : null}
        </div>
        <div className="LanguageDetection-card-label">{trans(card.label)}</div>
        <div className="LanguageDetection-card-help">{trans(card.help)}</div>
      </div>
    );
  }
}
