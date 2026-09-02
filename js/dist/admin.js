(() => {
  const e = {
    n: (a) => {
      const t = a && a.__esModule ? () => a.default : () => a;
      return (e.d(t, { a: t }), t);
    },
    d: (a, t) => {
      if (Array.isArray(t))
        for (var n = 0; n < t.length;) {
          var s = t[n++],
            i = t[n++];
          e.o(a, s)
            ? 0 === i && n++
            : 0 === i
              ? Object.defineProperty(a, s, { enumerable: !0, value: t[n++] })
              : Object.defineProperty(a, s, { enumerable: !0, get: i });
        }
      else for (var s in t) e.o(t, s) && !e.o(a, s) && Object.defineProperty(a, s, { enumerable: !0, get: t[s] });
    },
    o: (e, a) => Object.prototype.hasOwnProperty.call(e, a),
  };
  ((() => {
    'use strict';
    const a = flarum.core.compat['admin/app'];
    var t = e.n(a);
    function n(e, a) {
      return (
        (n = Object.setPrototypeOf
          ? Object.setPrototypeOf.bind()
          : function (e, a) {
              return ((e.__proto__ = a), e);
            }),
        n(e, a)
      );
    }
    function s(e, a) {
      ((e.prototype = Object.create(a.prototype)), (e.prototype.constructor = e), n(e, a));
    }
    const i = flarum.core.compat['admin/components/ExtensionPage'];
    var r = e.n(i);
    const l = flarum.core.compat['common/components/Button'];
    var o = e.n(l);
    const c = flarum.core.compat['common/components/LoadingIndicator'];
    var u = e.n(c);
    const d = flarum.core.compat['common/helpers/icon'];
    var g = e.n(d);
    const h = flarum.core.compat['common/Component'];
    var p,
      b = e.n(h);
    function f(e, a) {
      return t().translator.trans('huseyinfiliz-language-detection.admin.' + e, a);
    }
    function _(e, a) {
      return '' === e ? f('dashboard.languages_no_preference') : null === a ? f('dashboard.languages_unnamed') : a;
    }
    function v(e) {
      try {
        return e.toLocaleString(t().translator.getLocale() || void 0);
      } catch (a) {
        return String(e);
      }
    }
    var y = (function (e) {
        function a() {
          return e.apply(this, arguments) || this;
        }
        s(a, e);
        var n = a.prototype;
        return (
          (n.view = function () {
            var e = this.attrs.countries;
            return m(
              'div',
              { className: 'LanguageDetection-section' },
              m('h3', { className: 'LanguageDetection-section-title' }, f('dashboard.countries_title')),
              0 === e.length ? this.empty() : this.table(e)
            );
          }),
          (n.table = function (e) {
            var a = this;
            return m(
              'div',
              { className: 'LanguageDetection-table CardList CardList--cols-3' },
              m(
                'div',
                { className: 'CardList-header' },
                m('span', null, f('dashboard.countries_column_country')),
                m('span', { className: 'CardList-number' }, f('dashboard.countries_column_requests')),
                m('span', { className: 'CardList-number' }, f('dashboard.countries_column_visitors'))
              ),
              e.map(function (e) {
                return a.row(e);
              })
            );
          }),
          (n.row = function (e) {
            return m(
              'div',
              { className: 'CardList-row' },
              m(
                'span',
                { className: 'LanguageDetection-name' },
                m(
                  'strong',
                  null,
                  '' === e.country
                    ? f('dashboard.countries_unknown')
                    : (function (e) {
                        if (!/^[A-Za-z]{2}$/.test(e)) return e;
                        var a = e.toUpperCase(),
                          n = (function () {
                            if (void 0 !== p) return p;
                            var e = Intl.DisplayNames;
                            try {
                              p = e ? new e([t().translator.getLocale() || 'en'], { type: 'region' }) : null;
                            } catch (e) {
                              p = null;
                            }
                            return p;
                          })();
                        if (null === n) return a;
                        try {
                          return n.of(a) || a;
                        } catch (e) {
                          return a;
                        }
                      })(e.country)
                ),
                '' === e.country ? null : m('code', { className: 'LanguageDetection-code' }, e.country)
              ),
              m('span', { className: 'CardList-number', 'data-label': f('dashboard.countries_column_requests') }, v(e.requests)),
              m('span', { className: 'CardList-number', 'data-label': f('dashboard.countries_column_visitors') }, v(e.visitors))
            );
          }),
          (n.empty = function () {
            return m('div', { className: 'LanguageDetection-empty' }, g()('fas fa-globe'), m('p', null, f('dashboard.countries_empty')));
          }),
          a
        );
      })(b()),
      N = (function (e) {
        function a() {
          return e.apply(this, arguments) || this;
        }
        s(a, e);
        var t = a.prototype;
        return (
          (t.view = function () {
            var e = this.attrs.languages.filter(function (e) {
              return '' !== e.locale;
            });
            return m(
              'div',
              { className: 'LanguageDetection-section' },
              m('h3', { className: 'LanguageDetection-section-title' }, f('dashboard.languages_title')),
              0 === e.length ? this.empty() : this.table(e)
            );
          }),
          (t.table = function (e) {
            var a = this;
            return m(
              'div',
              { className: 'LanguageDetection-table CardList CardList--cols-4' },
              m(
                'div',
                { className: 'CardList-header' },
                m('span', null, f('dashboard.languages_column_language')),
                m('span', { className: 'CardList-number' }, f('dashboard.languages_column_requests')),
                m('span', { className: 'CardList-number' }, f('dashboard.languages_column_visitors')),
                m('span', null, f('dashboard.languages_column_status'))
              ),
              e.map(function (e) {
                return a.row(e);
              })
            );
          }),
          (t.row = function (e) {
            return m(
              'div',
              { className: 'CardList-row' },
              m(
                'span',
                { className: 'LanguageDetection-name' },
                m('strong', null, _(e.locale, e.name)),
                null === e.native || e.native === e.name ? null : m('span', { className: 'LanguageDetection-native' }, e.native),
                m('code', { className: 'LanguageDetection-code' }, e.locale),
                this.variants(e.tags)
              ),
              m('span', { className: 'CardList-number', 'data-label': f('dashboard.languages_column_requests') }, v(e.requests)),
              m('span', { className: 'CardList-number', 'data-label': f('dashboard.languages_column_visitors') }, v(e.visitors)),
              m('span', { 'data-label': f('dashboard.languages_column_status') }, this.status(e.served))
            );
          }),
          (t.variants = function (e) {
            return e.length < 2
              ? null
              : m('span', { className: 'LanguageDetection-variants' }, f('dashboard.languages_variants', { tags: e.join(', ') }));
          }),
          (t.status = function (e) {
            if (null === e) return null;
            var a = e ? 'served' : 'unserved',
              t = f(e ? 'dashboard.languages_served' : 'dashboard.languages_unserved');
            return m('span', { className: 'LanguageDetection-status LanguageDetection-status--' + a }, t);
          }),
          (t.empty = function () {
            return m('div', { className: 'LanguageDetection-empty' }, g()('fas fa-language'), m('p', null, f('dashboard.languages_empty')));
          }),
          a
        );
      })(b()),
      L = (function (e) {
        function a() {
          return e.apply(this, arguments) || this;
        }
        s(a, e);
        var t = a.prototype;
        return (
          (t.view = function () {
            var e = this.attrs.missing;
            return m(
              'div',
              { className: 'LanguageDetection-section' },
              m('h3', { className: 'LanguageDetection-section-title' }, f('dashboard.missing_title')),
              m('p', { className: 'helpText' }, f('dashboard.missing_description')),
              0 === e.length ? this.empty() : this.table(e)
            );
          }),
          (t.table = function (e) {
            var a = this;
            return m(
              'div',
              { className: 'LanguageDetection-table CardList CardList--cols-4' },
              m(
                'div',
                { className: 'CardList-header' },
                m('span', null, f('dashboard.missing_column_language')),
                m('span', { className: 'CardList-number' }, f('dashboard.missing_column_requests')),
                m('span', { className: 'CardList-number' }, f('dashboard.missing_column_visitors')),
                m('span', null, f('dashboard.missing_column_package'))
              ),
              e.map(function (e) {
                return a.row(e);
              })
            );
          }),
          (t.row = function (e) {
            return m(
              'div',
              { className: 'CardList-row' },
              m(
                'span',
                { className: 'LanguageDetection-name' },
                m('strong', null, _(e.locale, e.name)),
                null === e.native || e.native === e.name ? null : m('span', { className: 'LanguageDetection-native' }, e.native),
                m('code', { className: 'LanguageDetection-code' }, e.locale),
                this.variants(e.tags)
              ),
              m('span', { className: 'CardList-number', 'data-label': f('dashboard.missing_column_requests') }, v(e.requests)),
              m('span', { className: 'CardList-number', 'data-label': f('dashboard.missing_column_visitors') }, v(e.visitors)),
              m('span', { 'data-label': f('dashboard.missing_column_package') }, this.pack(e.package))
            );
          }),
          (t.variants = function (e) {
            return e.length < 2
              ? null
              : m('span', { className: 'LanguageDetection-variants' }, f('dashboard.missing_variants', { tags: e.join(', ') }));
          }),
          (t.pack = function (e) {
            return null === e
              ? m('span', { className: 'LanguageDetection-noPack' }, f('dashboard.missing_no_package'))
              : m(
                  'a',
                  { className: 'LanguageDetection-pack', href: 'https://packagist.org/packages/' + e, target: '_blank', rel: 'noopener noreferrer' },
                  m('code', null, e)
                );
          }),
          (t.empty = function () {
            return m('div', { className: 'LanguageDetection-empty' }, g()('fas fa-check-circle'), m('p', null, f('dashboard.missing_empty')));
          }),
          a
        );
      })(b());
    const D = flarum.core.compat['common/utils/extractText'];
    var w = e.n(D),
      C = 'huseyinfiliz-language-detection.',
      q = (function (e) {
        function a() {
          for (var a, t = arguments.length, n = new Array(t), s = 0; s < t; s++) n[s] = arguments[s];
          return (((a = e.call.apply(e, [this].concat(n)) || this).deleting = !1), (a.outcome = null), a);
        }
        s(a, e);
        var n = a.prototype;
        return (
          (n.view = function () {
            var e = this.attrs.page;
            return m(
              'div',
              { className: 'LanguageDetection-settings Form' },
              e.buildSettingComponent({
                type: 'select',
                setting: C + 'detection_order',
                label: f('settings.detection_order_label'),
                help: f('settings.detection_order_help'),
                options: { browser_ip: f('settings.detection_order_browser_ip'), ip_browser: f('settings.detection_order_ip_browser') },
                default: 'browser_ip',
              }),
              e.buildSettingComponent({
                type: 'select',
                setting: C + 'default_locale',
                label: f('settings.default_locale_label'),
                help: f('settings.default_locale_help'),
                options: this.localeOptions(),
                default: '',
              }),
              this.ipNotice(),
              e.buildSettingComponent({
                type: 'bool',
                setting: C + 'enable_analytics',
                label: f('settings.enable_analytics_label'),
                help: f('settings.enable_analytics_help'),
              }),
              e.buildSettingComponent({
                type: 'bool',
                setting: C + 'ignore_bots',
                label: f('settings.ignore_bots_label'),
                help: f('settings.ignore_bots_help'),
              }),
              e.buildSettingComponent({
                type: 'select',
                setting: C + 'retention_days',
                label: f('settings.retention_days_label'),
                help: f('settings.retention_days_help'),
                options: {
                  0: f('settings.retention_days_never'),
                  30: f('settings.retention_days_30'),
                  90: f('settings.retention_days_90'),
                  180: f('settings.retention_days_180'),
                  365: f('settings.retention_days_365'),
                },
                default: '90',
              }),
              m('div', { className: 'Form-group' }, e.submitButton()),
              this.cleanup()
            );
          }),
          (n.cleanup = function () {
            var e = this;
            return m(
              'div',
              { className: 'Form-group LanguageDetection-cleanup' },
              m(
                o(),
                {
                  className: 'Button',
                  icon: 'fas fa-trash',
                  loading: this.deleting,
                  onclick: function () {
                    return e.delete();
                  },
                },
                f('cleanup.button')
              ),
              m('p', { className: 'helpText' }, f('cleanup.button_help')),
              this.outcomeMessage()
            );
          }),
          (n.outcomeMessage = function () {
            return null === this.outcome
              ? null
              : !1 === this.outcome
                ? m('p', { className: 'helpText LanguageDetection-cleanup-failed' }, f('cleanup.failed'))
                : null === this.outcome.days
                  ? m('p', { className: 'helpText' }, f('cleanup.retention_disabled'))
                  : 0 === this.outcome.deleted
                    ? m('p', { className: 'helpText' }, f('cleanup.nothing_to_delete'))
                    : m('p', { className: 'helpText' }, f('cleanup.deleted', { count: v(this.outcome.deleted), days: this.outcome.days }));
          }),
          (n.delete = function () {
            var e = this;
            this.deleting ||
              (confirm(w()(f('cleanup.confirm'))) &&
                ((this.deleting = !0),
                (this.outcome = null),
                t()
                  .request({ method: 'POST', url: t().forum.attribute('apiUrl') + '/language-detection/cleanup' })
                  .then(function (a) {
                    ((e.outcome = a), (e.deleting = !1), m.redraw());
                  })
                  .catch(function () {
                    ((e.outcome = !1), (e.deleting = !1), m.redraw());
                  })));
          }),
          (n.localeOptions = function () {
            var e = { '': f('settings.default_locale_forum_default') };
            return (
              Object.keys(t().data.locales).forEach(function (a) {
                e[a] = t().data.locales[a] + ' (' + a + ')';
              }),
              e
            );
          }),
          (n.ipNotice = function () {
            var e = this.dataset();
            return null === e
              ? m('p', { className: 'helpText' }, f('ip_data.notice_unavailable'))
              : null === e.date
                ? null
                : m('p', { className: 'helpText' }, f('ip_data.notice', { date: e.date }));
          }),
          (n.dataset = function () {
            var e = t().data[C + 'ipData'];
            return null != e ? e : null;
          }),
          a
        );
      })(b()),
      x = [
        { field: 'requests', label: 'dashboard.card_requests_label', help: 'dashboard.card_requests_help', share: !1, tab: null },
        { field: 'visitors', label: 'dashboard.card_visitors_label', help: 'dashboard.card_visitors_help', share: !1, tab: null },
        { field: 'languages', label: 'dashboard.card_languages_label', help: 'dashboard.card_languages_help', share: !1, tab: 'languages' },
        { field: 'countries', label: 'dashboard.card_countries_label', help: 'dashboard.card_countries_help', share: !1, tab: 'countries' },
        { field: 'served', label: 'dashboard.card_served_label', help: 'dashboard.card_served_help', share: !0, tab: 'languages' },
        { field: 'unserved', label: 'dashboard.card_unserved_label', help: 'dashboard.card_unserved_help', share: !0, tab: 'missing' },
        { field: 'unstated', label: 'dashboard.card_unstated_label', help: 'dashboard.card_unstated_help', share: !0, tab: null },
      ],
      k = (function (e) {
        function a() {
          return e.apply(this, arguments) || this;
        }
        s(a, e);
        var t = a.prototype;
        return (
          (t.view = function () {
            var e = this,
              a = this.attrs.summary;
            return m(
              'div',
              { className: 'LanguageDetection-cards' },
              x.map(function (t) {
                return e.card(t, a);
              })
            );
          }),
          (t.card = function (e, a) {
            var t,
              n,
              s = a[e.field],
              i = this.attrs.onSelect,
              r = null !== e.tab && void 0 !== i,
              l = m(
                '[',
                null,
                m(
                  'div',
                  { className: 'LanguageDetection-card-value' },
                  v(s),
                  e.share
                    ? m('span', { className: 'LanguageDetection-card-share' }, ((t = s), (n = a.requests) <= 0 ? 0 : Math.round((t / n) * 100)), '%')
                    : null
                ),
                m('div', { className: 'LanguageDetection-card-label' }, f(e.label)),
                m('div', { className: 'LanguageDetection-card-help' }, f(e.help))
              ),
              o = 'LanguageDetection-card LanguageDetection-card--' + e.field + (r ? ' LanguageDetection-card--clickable' : '');
            return r
              ? m(
                  'button',
                  {
                    type: 'button',
                    className: o,
                    onclick: function () {
                      return i(e.tab);
                    },
                  },
                  l
                )
              : m('div', { className: o }, l);
          }),
          a
        );
      })(b()),
      T = 100,
      O = (function (e) {
        function a() {
          return e.apply(this, arguments) || this;
        }
        s(a, e);
        var t = a.prototype;
        return (
          (t.view = function () {
            var e = this.attrs.trend;
            return m(
              'div',
              { className: 'LanguageDetection-section' },
              m('h3', { className: 'LanguageDetection-section-title' }, f('dashboard.trend_title')),
              0 === e.length ? null : this.chart(e),
              e.some(function (e) {
                return e.requests > 0;
              })
                ? null
                : m('p', { className: 'helpText' }, f('dashboard.trend_empty'))
            );
          }),
          (t.chart = function (e) {
            var a,
              t = this,
              n =
                ((a = e.map(function (e) {
                  return e.requests;
                })),
                Math.max.apply(Math, [1].concat(a))),
              s = 10 * e.length;
            return m(
              'div',
              { className: 'LanguageDetection-chart' },
              m(
                'svg',
                { className: 'LanguageDetection-chart-svg', viewBox: '0 0 ' + s + ' ' + T, preserveAspectRatio: 'none' },
                e.map(function (e, a) {
                  return t.column(e, a, n);
                })
              ),
              m('div', { className: 'LanguageDetection-chart-axis' }, m('span', null, e[0].date), m('span', null, e[e.length - 1].date))
            );
          }),
          (t.column = function (e, a, t) {
            var n = (e.requests / t) * T,
              s = 10 * a + 1;
            return m(
              'g',
              { className: 'LanguageDetection-chart-column' },
              m('title', null, f('dashboard.trend_tooltip', { date: e.date, requests: v(e.requests), visitors: v(e.visitors) })),
              m('rect', { className: 'LanguageDetection-chart-track', x: s, y: 0, width: 8, height: T }),
              m('rect', { className: 'LanguageDetection-chart-bar', x: s, y: T - n, width: 8, height: n })
            );
          }),
          a
        );
      })(b()),
      P = [7, 30, 90],
      B = [
        { id: 'overview', label: 'dashboard.tab_overview', icon: 'fas fa-chart-line' },
        { id: 'languages', label: 'dashboard.tab_languages', icon: 'fas fa-language' },
        { id: 'missing', label: 'dashboard.tab_missing', icon: 'fas fa-download' },
        { id: 'countries', label: 'dashboard.tab_countries', icon: 'fas fa-globe' },
        { id: 'settings', label: 'dashboard.tab_settings', icon: 'fas fa-cog' },
      ],
      j = { 7: 'dashboard.window_7', 30: 'dashboard.window_30', 90: 'dashboard.window_90' },
      S = (function (e) {
        function a() {
          for (var a, t = arguments.length, n = new Array(t), s = 0; s < t; s++) n[s] = arguments[s];
          return (
            ((a = e.call.apply(e, [this].concat(n)) || this).tab = 'overview'),
            (a.days = 30),
            (a.refreshing = !0),
            (a.failed = !1),
            (a.statistics = null),
            (a.missing = null),
            a
          );
        }
        s(a, e);
        var n = a.prototype;
        return (
          (n.oninit = function (a) {
            (e.prototype.oninit.call(this, a), this.refresh());
          }),
          (n.content = function () {
            var e = this;
            return m(
              'div',
              { className: 'ExtensionPage-settings LanguageDetectionPage' },
              m(
                'div',
                { className: 'container' },
                m(
                  'div',
                  { className: 'LanguageDetection-bar' },
                  m(
                    'div',
                    { className: 'LanguageDetection-tabs' },
                    B.map(function (a) {
                      return e.tabButton(a);
                    })
                  ),
                  'settings' === this.tab
                    ? null
                    : m(
                        'div',
                        { className: 'LanguageDetection-windows' },
                        P.map(function (a) {
                          return e.windowButton(a);
                        })
                      )
                ),
                m('div', { className: 'LanguageDetection-content' }, this.tabContent())
              )
            );
          }),
          (n.tabContent = function () {
            var e = this;
            if ('settings' === this.tab) return m(q, { page: this });
            if (this.failed) return this.failure();
            var a = this.statistics,
              t = this.missing;
            if (this.refreshing || null === a || null === t) return m(u(), null);
            switch (this.tab) {
              case 'languages':
                return m(N, { languages: a.languages });
              case 'missing':
                return m(L, { missing: t.missing });
              case 'countries':
                return m(y, { countries: a.countries });
              default:
                return [
                  this.analyticsNotice(),
                  m(k, {
                    summary: a.summary,
                    onSelect: function (a) {
                      return e.selectTab(a);
                    },
                  }),
                  m(O, { trend: a.trend }),
                ];
            }
          }),
          (n.refresh = function () {
            var e = this;
            ((this.refreshing = !0), (this.failed = !1));
            var a = t().forum.attribute('apiUrl') + '/language-detection/',
              n = { days: this.days };
            Promise.all([
              t().request({ method: 'GET', url: a + 'statistics', params: n }),
              t().request({ method: 'GET', url: a + 'missing', params: n }),
            ])
              .then(function (a) {
                var t = a[0],
                  n = a[1];
                ((e.statistics = t), (e.missing = n), (e.refreshing = !1), m.redraw());
              })
              .catch(function () {
                ((e.failed = !0), (e.refreshing = !1), m.redraw());
              });
          }),
          (n.select = function (e) {
            e !== this.days && ((this.days = e), this.refresh());
          }),
          (n.selectTab = function (e) {
            this.tab = e;
          }),
          (n.tabButton = function (e) {
            var a = this,
              t = this.badge(e.id);
            return m(
              o(),
              {
                className: 'Button' + (this.tab === e.id ? ' active' : ''),
                icon: e.icon,
                onclick: function () {
                  return a.selectTab(e.id);
                },
              },
              f(e.label),
              null === t ? null : m('span', { className: 'Button-badge' }, t)
            );
          }),
          (n.windowButton = function (e) {
            var a = this;
            return m(
              o(),
              {
                className: 'Button' + (this.days === e ? ' active' : ''),
                disabled: this.refreshing,
                onclick: function () {
                  a.select(e);
                },
              },
              f(j[e])
            );
          }),
          (n.badge = function (e) {
            return null === this.statistics || null === this.missing
              ? null
              : 'languages' === e
                ? this.statistics.languages.filter(function (e) {
                    return '' !== e.locale;
                  }).length
                : 'missing' === e
                  ? this.missing.missing.length
                  : 'countries' === e
                    ? this.statistics.countries.length
                    : null;
          }),
          (n.analyticsNotice = function () {
            return '1' === this.setting('huseyinfiliz-language-detection.enable_analytics', '1')()
              ? null
              : m('p', { className: 'LanguageDetection-notice' }, f('dashboard.analytics_disabled'));
          }),
          (n.failure = function () {
            return m('div', { className: 'LanguageDetection-empty' }, g()('fas fa-exclamation-triangle'), m('p', null, f('dashboard.load_failed')));
          }),
          a
        );
      })(r());
    t().initializers.add('huseyinfiliz-language-detection', function () {
      t().extensionData.for('huseyinfiliz-language-detection').registerPage(S);
    });
  })(),
    (module.exports = {}));
})();
//# sourceMappingURL=admin.js.map
