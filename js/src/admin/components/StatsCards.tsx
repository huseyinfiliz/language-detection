import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';

import { count, percentage, trans } from '../format';
import type { Summary } from '../types';

/** Kept local rather than imported from the page, so this component does not depend on it. */
type Tab = 'overview' | 'languages' | 'missing' | 'countries' | 'settings';

export interface StatsCardsAttrs extends ComponentAttrs {
  summary: Summary;
  /**
   * Switches the page to the given tab. Omitted in contexts with nowhere to send a click -- cards
   * then render as plain, unclickable figures instead of silently doing nothing on click.
   */
  onSelect?: (tab: Tab) => void;
}

interface CardSpec {
  field: keyof Summary;
  label: string;
  help: string;
  /** Whether this figure is a slice of the page-view total, and so worth a percentage. */
  share: boolean;
  /**
   * Which tab this card's figure is drawn from, if any. `requests`, `visitors` and `unstated` have
   * no tab of their own -- `unstated` in particular is deliberately not a click into `languages`,
   * because that table no longer carries an "unstated" row for it to land on (see `LanguagesTable`).
   */
  tab: Tab | null;
}

/**
 * The seven summary figures, and the keys that label them.
 *
 * The keys are spelled out rather than assembled from the field name so that every one of them is a
 * literal string that can be grepped against both locale files -- a mistyped key renders as the raw
 * key in an admin's browser, and nothing else in the toolchain notices.
 */
const CARDS: CardSpec[] = [
  { field: 'requests', label: 'dashboard.card_requests_label', help: 'dashboard.card_requests_help', share: false, tab: null },
  { field: 'visitors', label: 'dashboard.card_visitors_label', help: 'dashboard.card_visitors_help', share: false, tab: null },
  { field: 'languages', label: 'dashboard.card_languages_label', help: 'dashboard.card_languages_help', share: false, tab: 'languages' },
  { field: 'countries', label: 'dashboard.card_countries_label', help: 'dashboard.card_countries_help', share: false, tab: 'countries' },
  { field: 'served', label: 'dashboard.card_served_label', help: 'dashboard.card_served_help', share: true, tab: 'languages' },
  { field: 'unserved', label: 'dashboard.card_unserved_label', help: 'dashboard.card_unserved_help', share: true, tab: 'missing' },
  { field: 'unstated', label: 'dashboard.card_unstated_label', help: 'dashboard.card_unstated_help', share: true, tab: null },
];

/**
 * The summary, one card per figure.
 *
 * Served, not served and no preference each carry their share of the total as well as their count.
 * That is not decoration: the question an admin brings to this page is "how much of my traffic is in
 * the wrong language", and a bare count of 4,000 only answers it if the total is also in view, which
 * it is not once you are four cards along.
 *
 * A card whose figure comes from a table elsewhere on the page (languages, countries, served,
 * unserved) is also a button into that table: an admin who has just read "12 not served" reaches
 * for the list of what those are, and asking them to find the missing tab themselves is a needless
 * extra step. `requests`, `visitors` and `unstated` stay plain -- there is no dedicated tab for a
 * raw total or for a figure a table no longer breaks out (see `unstated` above).
 */
export default class StatsCards extends Component<StatsCardsAttrs> {
  view() {
    const summary = this.attrs.summary;

    return <div className="LanguageDetection-cards">{CARDS.map((card) => this.card(card, summary))}</div>;
  }

  card(card: CardSpec, summary: Summary): Mithril.Children {
    const value = summary[card.field];
    const onSelect = this.attrs.onSelect;
    const clickable = card.tab !== null && onSelect !== undefined;

    const body = (
      <>
        <div className="LanguageDetection-card-value">
          {count(value)}
          {card.share ? <span className="LanguageDetection-card-share">{percentage(value, summary.requests)}%</span> : null}
        </div>
        <div className="LanguageDetection-card-label">{trans(card.label)}</div>
        <div className="LanguageDetection-card-help">{trans(card.help)}</div>
      </>
    );

    const className =
      'LanguageDetection-card LanguageDetection-card--' + card.field + (clickable ? ' LanguageDetection-card--clickable' : '');

    if (!clickable) {
      return <div className={className}>{body}</div>;
    }

    // A `button`, not a `div` with an onclick: the card becomes a real interactive control, reachable
    // by keyboard and announced as one, rather than a click target only a mouse can find.
    return (
      <button type="button" className={className} onclick={() => onSelect!(card.tab as Tab)}>
        {body}
      </button>
    );
  }
}