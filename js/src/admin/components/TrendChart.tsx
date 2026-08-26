import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';

import { count, scale, trans } from '../format';
import type { TrendPoint } from '../types';

export interface TrendChartAttrs extends ComponentAttrs {
  trend: TrendPoint[];
}

/** One column per day, in the SVG's own coordinate space. */
const STEP = 10;

/** The bar inside that column, leaving a 2-unit gutter. */
const BAR = 8;

/** Everything is drawn against a height of 100 and stretched by `preserveAspectRatio="none"`. */
const HEIGHT = 100;

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
  view() {
    const points = this.attrs.trend;

    return (
      <div className="LanguageDetection-section">
        <h3 className="LanguageDetection-section-title">{trans('dashboard.trend_title')}</h3>
        {points.length === 0 ? null : this.chart(points)}
        {points.some((point) => point.requests > 0) ? null : <p className="helpText">{trans('dashboard.trend_empty')}</p>}
      </div>
    );
  }

  chart(points: TrendPoint[]): Mithril.Children {
    const max = scale(points.map((point) => point.requests));
    const width = points.length * STEP;

    return (
      <div className="LanguageDetection-chart">
        <svg className="LanguageDetection-chart-svg" viewBox={`0 0 ${width} ${HEIGHT}`} preserveAspectRatio="none">
          {points.map((point, index) => this.column(point, index, max))}
        </svg>
        <div className="LanguageDetection-chart-axis">
          <span>{points[0].date}</span>
          <span>{points[points.length - 1].date}</span>
        </div>
      </div>
    );
  }

  /**
   * A day: a faint full-height track so that quiet days are still visible, and the bar itself.
   */
  column(point: TrendPoint, index: number, max: number): Mithril.Children {
    const height = (point.requests / max) * HEIGHT;
    const x = index * STEP + (STEP - BAR) / 2;

    return (
      <g className="LanguageDetection-chart-column">
        <title>{trans('dashboard.trend_tooltip', { date: point.date, requests: count(point.requests), visitors: count(point.visitors) })}</title>
        <rect className="LanguageDetection-chart-track" x={x} y={0} width={BAR} height={HEIGHT} />
        <rect className="LanguageDetection-chart-bar" x={x} y={HEIGHT - height} width={BAR} height={height} />
      </g>
    );
  }
}
