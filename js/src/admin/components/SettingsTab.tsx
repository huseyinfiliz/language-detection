import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import type AdminPage from 'flarum/admin/components/AdminPage';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';

import { trans } from '../format';
import type { IpDataset } from '../types';

/** Every setting key is prefixed with the extension id, as `Extend\Settings` declares them. */
const SETTING = 'huseyinfiliz-language-detection.';

export interface SettingsTabAttrs extends ComponentAttrs {
  /**
   * The page these fields belong to. `buildSettingComponent()`, `submitButton()` and the streams
   * behind them all live on `AdminPage`, and a tab that built its own would not be saved by the
   * page's save button.
   */
  page: AdminPage;
}

/**
 * The five settings, and the note about where IP country lookup gets its data.
 *
 * Nothing here reads or writes a setting directly: `buildSettingComponent()` binds each field to the
 * stream `AdminPage.setting()` owns, which is what `submitButton()` submits and `isChanged()` counts.
 */
export default class SettingsTab extends Component<SettingsTabAttrs> {
  view() {
    const page = this.attrs.page;

    return (
      <div className="LanguageDetection-settings Form">
        {page.buildSettingComponent({
          type: 'select',
          setting: SETTING + 'detection_order',
          label: trans('settings.detection_order_label'),
          help: trans('settings.detection_order_help'),
          options: {
            browser_ip: trans('settings.detection_order_browser_ip'),
            ip_browser: trans('settings.detection_order_ip_browser'),
          },
          default: 'browser_ip',
        })}
        {page.buildSettingComponent({
          type: 'select',
          setting: SETTING + 'default_locale',
          label: trans('settings.default_locale_label'),
          help: trans('settings.default_locale_help'),
          options: this.localeOptions(),
          default: '',
        })}
        {this.ipNotice()}
        {page.buildSettingComponent({
          type: 'bool',
          setting: SETTING + 'enable_analytics',
          label: trans('settings.enable_analytics_label'),
          help: trans('settings.enable_analytics_help'),
        })}
        {page.buildSettingComponent({
          type: 'bool',
          setting: SETTING + 'ignore_bots',
          label: trans('settings.ignore_bots_label'),
          help: trans('settings.ignore_bots_help'),
        })}
        {page.buildSettingComponent({
          type: 'select',
          setting: SETTING + 'retention_days',
          label: trans('settings.retention_days_label'),
          help: trans('settings.retention_days_help'),
          options: {
            0: trans('settings.retention_days_never'),
            30: trans('settings.retention_days_30'),
            90: trans('settings.retention_days_90'),
            180: trans('settings.retention_days_180'),
            365: trans('settings.retention_days_365'),
          },
          default: '90',
        })}
        <div className="Form-group">{page.submitButton()}</div>
      </div>
    );
  }

  /**
   * The installed language packs, with the forum default first.
   *
   * `app.data.locales` is populated by core's own frontend payload on every frontend including this
   * one, so there is no extender behind it. The code is shown alongside the name because two packs
   * can carry the same title.
   */
  localeOptions(): Record<string, Mithril.Children> {
    const options: Record<string, Mithril.Children> = {
      '': trans('settings.default_locale_forum_default'),
    };

    Object.keys(app.data.locales).forEach((code) => {
      options[code] = app.data.locales[code] + ' (' + code + ')';
    });

    return options;
  }

  /**
   * Where the IP country data came from, in the three states `Content\AdminPayload` distinguishes.
   *
   * Only a missing dataset means the lookup is inactive. A dataset that recorded no build date is
   * installed and working, so saying otherwise would be untrue -- there is just nothing to date it
   * with, and so nothing is said.
   */
  ipNotice(): Mithril.Children {
    const dataset = this.dataset();

    if (dataset === null) return <p className="helpText">{trans('ip_data.notice_unavailable')}</p>;

    if (dataset.date === null) return null;

    return <p className="helpText">{trans('ip_data.notice', { date: dataset.date })}</p>;
  }

  /**
   * `ApplicationData` ends in an `unknown` index signature, so the payload is asserted here and
   * nowhere else.
   */
  dataset(): IpDataset | null {
    const payload = app.data[SETTING + 'ipData'] as IpDataset | null | undefined;

    return payload ?? null;
  }
}
