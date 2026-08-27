/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
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
    view(): JSX.Element;
    table(languages: LanguageRow[]): Mithril.Children;
    row(row: LanguageRow): Mithril.Children;
    /**
     * Served, not served, or nothing at all -- the three states the API distinguishes.
     */
    status(served: boolean | null): Mithril.Children;
    empty(): Mithril.Children;
}
