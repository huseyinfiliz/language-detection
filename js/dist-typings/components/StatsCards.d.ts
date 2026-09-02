/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
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
    view(): JSX.Element;
    card(card: CardSpec, summary: Summary): Mithril.Children;
}
export {};
