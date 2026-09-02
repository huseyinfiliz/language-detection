/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type { MissingRow } from '../types';
export interface MissingLanguagesAttrs extends ComponentAttrs {
  missing: MissingRow[];
}
/**
 * The languages visitors asked for that this forum cannot show, with the package that would fix each.
 *
 * Rows come straight from `LanguageCatalog::missing()` -- rolled up by pack before they arrive, so
 * `de`, `de-DE` and `de-AT` are one suggestion, and the tags that rolled up are shown underneath.
 */
export default class MissingLanguages extends Component<MissingLanguagesAttrs> {
  view(): JSX.Element;
  table(missing: MissingRow[]): Mithril.Children;
  row(row: MissingRow): Mithril.Children;
  /**
   * The other spellings that rolled up into this row, when there were any.
   */
  variants(tags: string[]): Mithril.Children;
  /**
   * The package to install, or the one honest label for both ways of having none.
   *
   * A null package means either that Flarum publishes no pack for the language or that it publishes
   * several and picking one would be a guess. The report does not distinguish them, so this must not
   * say "no package available" -- which reads as "Flarum does not translate this" and is wrong about
   * half the time.
   */
  pack(name: string | null): Mithril.Children;
  empty(): Mithril.Children;
}
