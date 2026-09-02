/// <reference types="mithril" />
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type { TrendPoint } from '../types';
export interface TrendChartAttrs extends ComponentAttrs {
  trend: TrendPoint[];
}
/**
 * Page views per day, as hand-written inline SVG.
 *
 * No chart library and no new palette: the bars are `<rect>`s coloured by LESS from Flarum's own CSS
 * variables. Bars rather than a line, which also settles the single-day window -- a one-day window is
 * one bar, where a line chart would have to draw a segment from a point to itself.
 *
 * The geometry is fixed and the SVG is stretched to whatever width it is given, which is why nothing
 * here is text: `preserveAspectRatio="none"` would distort glyphs and stroke widths. The dates are
 * HTML underneath, and the per-day figures are `<title>` tooltips.
 *
 * An all-zero window -- a forum that switched analytics on this morning -- draws its tracks and says
 * so, rather than dividing by a maximum of zero. See `scale()`.
 */
export default class TrendChart extends Component<TrendChartAttrs> {
  view(): JSX.Element;
  chart(points: TrendPoint[]): Mithril.Children;
  /**
   * A day: a faint full-height track so that quiet days are still visible, and the bar itself.
   */
  column(point: TrendPoint, index: number, max: number): Mithril.Children;
}
