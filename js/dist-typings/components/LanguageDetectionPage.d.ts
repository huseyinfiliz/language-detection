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
    select(days: number): void;
    tabButton(tab: TabSpec): Mithril.Children;
    windowButton(days: number): Mithril.Children;
    /**
     * How many rows a tab holds, for the count pill beside its name. Null while nothing is loaded, and
     * for the two tabs that are not a list of anything.
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
