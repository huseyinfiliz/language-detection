<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection;

/**
 * Canonical comparison keys for installed locales and published language packs.
 *
 * Analytics deliberately stores the requested spelling without these aliases, so changes to
 * matching do not re-key historical statistics.
 *
 * @internal
 */
class LocaleNormalizer
{
    /**
     * Published pack codes mapped to the equivalent code a browser sends.
     */
    const CODE_ALIASES = [
        'uzb' => 'uz',
    ];

    /**
     * Full language-region tags whose region identifies a published script variant.
     */
    const REGION_SCRIPT_ALIASES = [
        'zh-cn' => 'zh-hans',
        'zh-sg' => 'zh-hans',
        'zh-tw' => 'zh-hant',
        'zh-hk' => 'zh-hant',
        'zh-mo' => 'zh-hant',
    ];

    public static function normalize(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));

        if ($code === '') {
            return '';
        }

        if (isset(self::REGION_SCRIPT_ALIASES[$code])) {
            return self::REGION_SCRIPT_ALIASES[$code];
        }

        $subtags = explode('-', $code);

        if (isset(self::CODE_ALIASES[$subtags[0]])) {
            $subtags[0] = self::CODE_ALIASES[$subtags[0]];

            return implode('-', $subtags);
        }

        return $code;
    }
}
