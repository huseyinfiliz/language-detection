/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type AdminPage from 'flarum/admin/components/AdminPage';
import type { ComponentAttrs } from 'flarum/common/Component';
import type { CleanupResult, IpDataset } from '../types';
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
 * stream `AdminPage.setting()` owns, which is what `submitButton()` submits.
 */
export default class SettingsTab extends Component<SettingsTabAttrs> {
  /** True while the cleanup request is in flight, so the button cannot be pressed twice. */
  deleting: boolean;
  /** The last cleanup's outcome, or false when it failed. Null before anything has been pressed. */
  outcome: CleanupResult | false | null;
  view(): JSX.Element;
  /**
   * Deleting the old rows now, rather than waiting for the scheduled command.
   *
   * Below the save button on purpose: it is not a setting, it takes effect immediately, and it cannot
   * be undone. It deletes by the *saved* retention period, not by whatever the select above is
   * showing, which is why the help text says so.
   */
  cleanup(): Mithril.Children;
  /**
   * What the last run did, in the three outcomes `Api\CleanupController` distinguishes plus the
   * failure. Retention being switched off is reported rather than shown as "nothing was deleted".
   */
  outcomeMessage(): Mithril.Children;
  delete(): void;
  /**
   * The installed language packs, with the forum default first.
   *
   * `app.data.locales` is populated by core's own frontend payload, so there is no extender behind it.
   * The code is shown alongside the name because two packs can carry the same title.
   */
  localeOptions(): Record<string, Mithril.Children>;
  /**
   * Where the IP country data came from, in the three states `Content\AdminPayload` distinguishes.
   *
   * Only a missing dataset means the lookup is inactive. A dataset that recorded no build date is
   * installed and working, so saying otherwise would be untrue -- there is just nothing to date it
   * with, and so nothing is said.
   */
  ipNotice(): Mithril.Children;
  /**
   * `ApplicationData` ends in an `unknown` index signature, so the payload is asserted here and
   * nowhere else.
   */
  dataset(): IpDataset | null;
}
