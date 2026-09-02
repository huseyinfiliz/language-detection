/// <reference types="mithril" />
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import type { MissingPayload, StatisticsPayload } from '../types';
type Tab = 'overview' | 'languages' | 'missing' | 'countries' | 'settings';
interface TabSpec {
    id: Tab;
    label: string;
    icon: string;
}
/**
 * The statistics dashboard.
 *
 * Both endpoints are fetched together and re-fetched together when the window changes, so the cards,
 * the tables and the chart always describe one instant. Fetching per tab would be cheaper and would
 * let a stale card sit above a fresh table.
 */
export default class LanguageDetectionPage extends ExtensionPage<ExtensionPageAttrs> {
    /**
     * Which tab is open. Local state rather than a route: nothing here is worth a deep link.
     */
    tab: Tab;
    /** The window every figure on the page is drawn from. One of `WINDOWS`. */
    days: number;
    /**
     * Deliberately not `AdminPage.loading`, which `submitButton()` reads for its spinner: sharing it
     * would put the save button into a loading state every time somebody changed the window.
     */
    refreshing: boolean;
    failed: boolean;
    statistics: StatisticsPayload | null;
    missing: MissingPayload | null;
    oninit(vnode: Mithril.Vnode<ExtensionPageAttrs, this>): void;
    content(): JSX.Element;
    tabContent(): Mithril.Children;
    /**
     * Both endpoints, for the current window, as one operation.
     */
    refresh(): void;
    /**
     * Switches which window (7/30/90 days) every figure on the page is drawn from, re-fetching both
     * endpoints. Named apart from `selectTab()` because the two are unrelated axes -- a window change
     * re-fetches, a tab change does not -- and folding them into one overloaded `select()` was what
     * made the overview cards unable to just call "select a tab" without also risking a re-fetch.
     */
    select(days: number): void;
    /**
     * Switches which tab is open. Shared by `tabButton()` and by `StatsCards`'s clickable summary
     * cards, so a card click and a tab click behave identically -- including the count pill on the
     * tab immediately reflecting where the click landed.
     */
    selectTab(tab: Tab): void;
    tabButton(tab: TabSpec): Mithril.Children;
    windowButton(days: number): Mithril.Children;
    /**
     * How many rows a tab holds, for the count pill beside its name. Null while nothing is loaded, and
     * for the two tabs that are not a list of anything.
     *
     * `languages` excludes the `''` row the same way `LanguagesTable` does: that row is filtered from
     * the table itself (see `LanguagesTable`'s docblock), and a badge reading 20 beside a table showing
     * 19 rows would look like a bug rather than the deliberate omission it is.
     */
    badge(tab: Tab): number | null;
    /**
     * Why the page is empty when collection has been switched off.
     *
     * Settings are strings and `'0'` is truthy in JavaScript, so this compares against `'1'` rather
     * than testing the value for truth.
     */
    analyticsNotice(): Mithril.Children;
    failure(): Mithril.Children;
}
export {};
