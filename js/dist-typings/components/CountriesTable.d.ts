/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
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
    view(): JSX.Element;
    table(countries: CountryRow[]): Mithril.Children;
    row(row: CountryRow): Mithril.Children;
    empty(): Mithril.Children;
}
