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
 * The `''` row -- visitors whose browser named nothing usable -- is dropped rather than shown under
 * a label of its own. It carries no `locale` an admin could act on (there is no pack to install for
 * "nothing stated"), it is usually the single largest row in the table, and sitting at the top of a
 * list titled "Languages visitors asked for" it reads as a language rather than the absence of one,
 * which is confusing rather than informative. The figure is not lost: it still feeds the page-view
 * total on the summary cards above, via `unstated` in `Summary`, which is what that card is for.
 */
export default class LanguagesTable extends Component<LanguagesTableAttrs> {
    view(): JSX.Element;
    table(languages: LanguageRow[]): Mithril.Children;
    row(row: LanguageRow): Mithril.Children;
    /**
     * The other spellings that rolled up into this row, when there was more than one.
     *
     * A row's `locale` is the installed (or catalog) spelling that answers it -- `tr`, `zh-Hans` -- not
     * necessarily what any single visitor typed into their browser. Where several raw tags collapsed
     * onto one row (`tr-TR` and `TR` onto `tr`; `zh-CN` onto `zh-Hans`), this is what shows a visitor's
     * own tag still exists in the data, instead of just vanishing into a bigger number under a spelling
     * they never sent.
     */
    variants(tags: string[]): Mithril.Children;
    /**
     * Served, not served, or nothing at all -- the three states the API distinguishes.
     */
    status(served: boolean | null): Mithril.Children;
    empty(): Mithril.Children;
}
