/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
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
 * The summary, one card per figure.
 *
 * Served, not served and no preference each carry their share of the total as well as their count.
 * That is not decoration: the question an admin brings to this page is "how much of my traffic is in
 * the wrong language", and a bare count of 4,000 only answers it if the total is also in view, which
 * it is not once you are four cards along.
 */
export default class StatsCards extends Component<StatsCardsAttrs> {
    view(): JSX.Element;
    card(card: CardSpec, summary: Summary): Mithril.Children;
}
export {};
