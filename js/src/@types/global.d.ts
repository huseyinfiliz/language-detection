/**
 * The Mithril types this extension uses, as globals.
 *
 * Flarum does not bundle Mithril into an extension: `flarum-webpack-config` declares externals for
 * `@flarum/core` and `jquery` but not for `mithril`, and `mithril` is not a dependency of this
 * package either. A `import ... from 'mithril'` that survives to the bundler is therefore an
 * unresolvable request and fails the build. At runtime Mithril is already there as the global `m`,
 * which core types for us in its own `@types/global.d.ts`.
 *
 * That leaves the type side, which is what this file is for. `@types/mithril` ends in
 * `export = Mithril`, so its `Mithril` namespace is reachable only through the module and there is
 * no global to annotate against. The aliases below re-expose the members we need under a global
 * `Mithril`, using the same `import('mithril')` type syntax core uses for `m` and `dayjs`: it is a
 * type-space reference to the `@types/mithril` package, so nothing is emitted and the bundler never
 * sees a request for `mithril`.
 *
 * Add an alias here when a component needs a Mithril type this does not cover yet, rather than
 * importing the module back into `src`.
 */
declare namespace Mithril {
  /** Anything a `view()` or a view helper may return. */
  type Children = import('mithril').Children;

  /** A component's own vnode, as the lifecycle methods receive it. */
  type Vnode<Attrs = {}, State = {}> = import('mithril').Vnode<Attrs, State>;
}
