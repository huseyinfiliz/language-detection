<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Locale\LocaleManager;
use HuseyinFiliz\LanguageDetection\Cleanup;

/**
 * `php flarum language-detection:cleanup` -- and, through `Extend\Console`'s scheduler, the same
 * thing once a day without anybody typing it.
 *
 * The command owns no policy: `Cleanup` reads the setting, computes the cutoff and writes the
 * query, and the admin page's button calls the same object.
 */
class CleanupCommand extends AbstractCommand
{
    const KEY = 'huseyinfiliz-language-detection.admin.cleanup.';

    protected Cleanup $cleanup;

    protected LocaleManager $locales;

    /**
     * `LocaleManager` rather than `TranslatorInterface`, and the difference is load-bearing.
     *
     * Symfony calls `configure()` from `Command::__construct()`, and this command is constructed on
     * every HTTP request: `Extend\Console::schedule()` hands the class name to Laravel's scheduler,
     * which resolves it from the container just to read the name and description. So `configure()`
     * runs during application boot, before any middleware.
     *
     * Translations reach the shared translator only as a side effect of constructing
     * `LocaleManager` -- core's own catalogue in its factory, every extension's and language pack's
     * YAML in `resolving()` callbacks. Asking for the translator directly therefore yields one with
     * no resources, and the first `trans()` compiles an empty catalogue for the forum's default
     * locale and writes it to `storage/locale` with an empty resource list, which Symfony treats as
     * permanently fresh. Every translation key on the forum then renders raw until that directory
     * is cleared by hand.
     *
     * Injecting `LocaleManager` is what guarantees the resources are registered first. Extenders
     * all run on the application's `booting` callback, before any service provider boots, so
     * nothing is missing by the time the scheduler gets here.
     */
    public function __construct(Cleanup $cleanup, LocaleManager $locales)
    {
        $this->cleanup = $cleanup;
        $this->locales = $locales;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('language-detection:cleanup')
            ->setDescription($this->trans('command_description'));
    }

    protected function fire()
    {
        $result = $this->cleanup->run();

        // Retention off is reported rather than passed over in silence: somebody who scheduled this
        // command and sees nothing happening is owed the reason.
        if ($result === null) {
            $this->info($this->trans('retention_disabled'));

            return 0;
        }

        if ($result['deleted'] === 0) {
            $this->info($this->trans('nothing_to_delete'));

            return 0;
        }

        // Braces included in the keys because Symfony's message formatter is a plain `strtr` over
        // the parameters, so `count` would not match `{count}` in the locale file.
        $this->info($this->trans('deleted', [
            '{count}' => $result['deleted'],
            '{days}' => $result['days'],
        ]));

        return 0;
    }

    protected function trans(string $key, array $parameters = []): string
    {
        return $this->locales->getTranslator()->trans(self::KEY.$key, $parameters);
    }
}
