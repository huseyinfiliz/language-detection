import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import icon from 'flarum/common/helpers/icon';
import type { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';

import CountriesTable from './CountriesTable';
import LanguagesTable from './LanguagesTable';
import MissingLanguages from './MissingLanguages';
import SettingsTab from './SettingsTab';
import StatsCards from './StatsCards';
import TrendChart from './TrendChart';
import { trans } from '../format';
import { WINDOWS } from '../types';
import type { MissingPayload, StatisticsPayload } from '../types';

type Tab = 'overview' | 'languages' | 'missing' | 'countries' | 'settings';

interface TabSpec {
  id: Tab;
  label: string;
  icon: string;
}

const TABS: TabSpec[] = [
  { id: 'overview', label: 'dashboard.tab_overview', icon: 'fas fa-chart-line' },
  { id: 'languages', label: 'dashboard.tab_languages', icon: 'fas fa-language' },
  { id: 'missing', label: 'dashboard.tab_missing', icon: 'fas fa-download' },
  { id: 'countries', label: 'dashboard.tab_countries', icon: 'fas fa-globe' },
  { id: 'settings', label: 'dashboard.tab_settings', icon: 'fas fa-cog' },
];

/**
 * A key per window, so that the keys stay literal strings that can be grepped against both locale
 * files. `WINDOWS` is the list the buttons are drawn from and mirrors the API's whitelist; adding a
 * window there without adding its label here would render `undefined` on a button.
 */
const WINDOW_LABELS: Record<number, string> = {
  7: 'dashboard.window_7',
  30: 'dashboard.window_30',
  90: 'dashboard.window_90',
};

/**
 * Everything the previous phases collected, on one page.
 *
 * Both endpoints are fetched together and re-fetched together when the window changes, so the cards,
 * the tables and the chart are always describing one instant. Fetching per tab would be cheaper and
 * would let a stale card sit above a fresh table.
 */
export default class LanguageDetectionPage extends ExtensionPage<ExtensionPageAttrs> {
  /**
   * Which tab is open. Local state rather than a route: nothing here is worth a deep link, and a
   * route would mean a resolver plus a second registration for no gain.
   */
  tab: Tab = 'overview';

  /** The window every figure on the page is drawn from. One of `WINDOWS`. */
  days = 30;

  /**
   * Deliberately not `AdminPage.loading`, which `submitButton()` reads for its spinner: sharing it
   * would put the save button into a loading state every time somebody changed the window.
   */
  refreshing = true;

  failed = false;

  statistics: StatisticsPayload | null = null;

  missing: MissingPayload | null = null;

  oninit(vnode: Mithril.Vnode<ExtensionPageAttrs, this>) {
    super.oninit(vnode);

    this.refresh();
  }

  content() {
    return (
      <div className="ExtensionPage-settings LanguageDetectionPage">
        <div className="container">
          <div className="LanguageDetection-bar">
            <div className="LanguageDetection-tabs">{TABS.map((tab) => this.tabButton(tab))}</div>
            {this.tab === 'settings' ? null : <div className="LanguageDetection-windows">{WINDOWS.map((days) => this.windowButton(days))}</div>}
          </div>
          <div className="LanguageDetection-content">{this.tabContent()}</div>
        </div>
      </div>
    );
  }

  tabContent(): Mithril.Children {
    // The settings do not come from the API, so they are readable while it is loading and after it
    // has failed -- which is the state a forum is in when the endpoints are unreachable.
    if (this.tab === 'settings') return <SettingsTab page={this} />;

    if (this.failed) return this.failure();

    // Bound to locals so that the null checks below narrow something TypeScript cannot lose track
    // of: the overview branch calls a method between the check and the reads.
    const statistics = this.statistics;
    const missing = this.missing;

    if (this.refreshing || statistics === null || missing === null) return <LoadingIndicator />;

    switch (this.tab) {
      case 'languages':
        return <LanguagesTable languages={statistics.languages} />;

      case 'missing':
        return <MissingLanguages missing={missing.missing} />;

      case 'countries':
        return <CountriesTable countries={statistics.countries} />;

      default:
        return [this.analyticsNotice(), <StatsCards summary={statistics.summary} />, <TrendChart trend={statistics.trend} />];
    }
  }

  /**
   * Both endpoints, for the current window, as one operation.
   */
  refresh() {
    this.refreshing = true;
    this.failed = false;

    const url = app.forum.attribute<string>('apiUrl') + '/language-detection/';
    const params = { days: this.days };

    Promise.all([
      app.request<StatisticsPayload>({ method: 'GET', url: url + 'statistics', params }),
      app.request<MissingPayload>({ method: 'GET', url: url + 'missing', params }),
    ])
      .then(([statistics, missing]) => {
        this.statistics = statistics;
        this.missing = missing;
        this.refreshing = false;
        m.redraw();
      })
      .catch(() => {
        // `app.request` has already reported the error itself; this is what stops the page sitting
        // on a spinner for ever and says so where the numbers would have been.
        this.failed = true;
        this.refreshing = false;
        m.redraw();
      });
  }

  select(days: number) {
    if (days === this.days) return;

    this.days = days;
    this.refresh();
  }

  tabButton(tab: TabSpec): Mithril.Children {
    const badge = this.badge(tab.id);

    return (
      <Button
        className={'Button' + (this.tab === tab.id ? ' active' : '')}
        icon={tab.icon}
        onclick={() => {
          this.tab = tab.id;
        }}
      >
        {trans(tab.label)}
        {badge === null ? null : <span className="Button-badge">{badge}</span>}
      </Button>
    );
  }

  windowButton(days: number): Mithril.Children {
    return (
      <Button
        className={'Button' + (this.days === days ? ' active' : '')}
        disabled={this.refreshing}
        onclick={() => {
          this.select(days);
        }}
      >
        {trans(WINDOW_LABELS[days])}
      </Button>
    );
  }

  /**
   * How many rows a tab holds, for the count pill beside its name. Null while nothing is loaded, and
   * for the two tabs that are not a list of anything.
   */
  badge(tab: Tab): number | null {
    if (this.statistics === null || this.missing === null) return null;

    if (tab === 'languages') return this.statistics.languages.length;
    if (tab === 'missing') return this.missing.missing.length;
    if (tab === 'countries') return this.statistics.countries.length;

    return null;
  }

  /**
   * Why the page is empty when collection has been switched off.
   *
   * Settings are strings and `'0'` is truthy in JavaScript, so this compares against `'1'` rather
   * than testing the value for truth.
   */
  analyticsNotice(): Mithril.Children {
    if (this.setting('huseyinfiliz-language-detection.enable_analytics', '1')() === '1') return null;

    return <p className="LanguageDetection-notice">{trans('dashboard.analytics_disabled')}</p>;
  }

  failure(): Mithril.Children {
    return (
      <div className="LanguageDetection-empty">
        {icon('fas fa-exclamation-triangle')}
        <p>{trans('dashboard.load_failed')}</p>
      </div>
    );
  }
}
