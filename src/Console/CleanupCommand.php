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
use HuseyinFiliz\LanguageDetection\Cleanup;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `php flarum language-detection:cleanup` -- and, through `Extend\Console`'s scheduler, the same
 * thing once a day without anybody typing it.
 *
 * The command owns no policy at all. It reads no setting, computes no cutoff and writes no query;
 * `Cleanup` does all three, and the admin page's button calls the same object. What is left here
 * is turning one of three outcomes into a line of output.
 */
class CleanupCommand extends AbstractCommand
{
    const KEY = 'huseyinfiliz-language-detection.admin.cleanup.';

    protected Cleanup $cleanup;

    protected TranslatorInterface $translator;

    public function __construct(Cleanup $cleanup, TranslatorInterface $translator)
    {
        $this->cleanup = $cleanup;
        $this->translator = $translator;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('language-detection:cleanup')
            ->setDescription($this->translator->trans(self::KEY.'command_description'));
    }

    protected function fire()
    {
        $result = $this->cleanup->run();

        // Retention off is reported rather than passed over in silence: somebody who scheduled this
        // command and sees nothing happening is owed the reason.
        if ($result === null) {
            $this->info($this->translator->trans(self::KEY.'retention_disabled'));

            return 0;
        }

        if ($result['deleted'] === 0) {
            $this->info($this->translator->trans(self::KEY.'nothing_to_delete'));

            return 0;
        }

        // Braces included in the keys because Symfony's message formatter is a plain `strtr` over
        // the parameters, so `count` would not match `{count}` in the locale file.
        $this->info($this->translator->trans(self::KEY.'deleted', [
            '{count}' => $result['deleted'],
            '{days}' => $result['days'],
        ]));

        return 0;
    }
}
