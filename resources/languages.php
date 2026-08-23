<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

/*
 * The language catalog: every locale Flarum has an official language pack for.
 *
 * Keys are the locale codes exactly as each pack publishes them in
 * `extra.flarum-locale.code`, and are the codes `LocaleManager::getLocales()` returns
 * once a pack is installed. They are NOT normalised, because they must be usable
 * verbatim with `LocaleManager::hasLocale()` / `setLocale()`. Note the irregularities
 * that come from upstream and must be preserved:
 *
 *   - `es_AR` and `es_MX` use an underscore where every other regional pack uses a
 *     hyphen (`pt-BR`).
 *   - `uzb` is not the ISO 639-1 code for Uzbek (`uz`).
 *   - Script subtags are mixed case (`zh-Hans`, `sr-Cyrl`) and must not be lowercased.
 *
 * `name` is the English name and `native` the endonym; both are curated here rather
 * than taken from each pack's published `title`, because those titles are an
 * inconsistent mix of English and native names.
 *
 * `package` is the Composer package, used only to build a link for an admin who wants
 * to install a language that visitors are asking for. Nothing here is ever fetched at
 * runtime -- this file is the single source of truth for the catalog. `en` is included
 * for display purposes with a null package, because English ships with core rather
 * than as a language pack.
 *
 * Not admin-editable. Maintained by the extension author.
 */

return [
    'af' => ['name' => 'Afrikaans', 'native' => 'Afrikaans', 'package' => 'flarum-lang/afrikaans'],
    'sq' => ['name' => 'Albanian', 'native' => 'Shqip', 'package' => 'flarum-lang/albanian'],
    'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'package' => 'flarum-lang/arabic'],
    'hy' => ['name' => 'Armenian', 'native' => 'Հայերեն', 'package' => 'flarum-lang/armenian'],
    'ast' => ['name' => 'Asturian', 'native' => 'Asturianu', 'package' => 'flarum-lang/asturian'],
    'az' => ['name' => 'Azerbaijani', 'native' => 'Azərbaycan dili', 'package' => 'flarum-lang/azerbaijani'],
    'eu' => ['name' => 'Basque', 'native' => 'Euskara', 'package' => 'flarum-lang/basque'],
    'be' => ['name' => 'Belarusian', 'native' => 'Беларуская', 'package' => 'flarum-lang/belarusian'],
    'bn' => ['name' => 'Bengali', 'native' => 'বাংলা', 'package' => 'flarum-lang/bengali'],
    'bs' => ['name' => 'Bosnian', 'native' => 'Bosanski', 'package' => 'flarum-lang/bosnian'],
    'pt-BR' => ['name' => 'Brazilian Portuguese', 'native' => 'Português (Brasil)', 'package' => 'flarum-lang/brazilian'],
    'br' => ['name' => 'Breton', 'native' => 'Brezhoneg', 'package' => 'flarum-lang/breton'],
    'bg' => ['name' => 'Bulgarian', 'native' => 'Български', 'package' => 'flarum-lang/bulgarian'],
    'my' => ['name' => 'Burmese', 'native' => 'မြန်မာ', 'package' => 'flarum-lang/burmese'],
    'ca' => ['name' => 'Catalan', 'native' => 'Català', 'package' => 'flarum-lang/catalan'],
    'zh-Hans' => ['name' => 'Chinese (Simplified)', 'native' => '简体中文', 'package' => 'flarum-lang/chinese-simplified'],
    'zh-Hant' => ['name' => 'Chinese (Traditional)', 'native' => '繁體中文', 'package' => 'flarum-lang/chinese-traditional'],
    'hr' => ['name' => 'Croatian', 'native' => 'Hrvatski', 'package' => 'flarum-lang/croatian'],
    'cs' => ['name' => 'Czech', 'native' => 'Čeština', 'package' => 'flarum-lang/czech'],
    'da' => ['name' => 'Danish', 'native' => 'Dansk', 'package' => 'flarum-lang/danish'],
    'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'package' => 'flarum-lang/dutch'],
    'en' => ['name' => 'English', 'native' => 'English', 'package' => null],
    'eo' => ['name' => 'Esperanto', 'native' => 'Esperanto', 'package' => 'flarum-lang/esperanto'],
    'et' => ['name' => 'Estonian', 'native' => 'Eesti', 'package' => 'flarum-lang/estonian'],
    'fil' => ['name' => 'Filipino', 'native' => 'Filipino', 'package' => 'flarum-lang/filipino'],
    'fi' => ['name' => 'Finnish', 'native' => 'Suomi', 'package' => 'flarum-lang/finnish'],
    'fr' => ['name' => 'French', 'native' => 'Français', 'package' => 'flarum-lang/french'],
    'gl' => ['name' => 'Galician', 'native' => 'Galego', 'package' => 'flarum-lang/galician'],
    'ka' => ['name' => 'Georgian', 'native' => 'ქართული', 'package' => 'flarum-lang/georgian'],
    'de' => ['name' => 'German', 'native' => 'Deutsch', 'package' => 'flarum-lang/german'],
    'el' => ['name' => 'Greek', 'native' => 'Ελληνικά', 'package' => 'flarum-lang/greek'],
    'he' => ['name' => 'Hebrew', 'native' => 'עברית', 'package' => 'flarum-lang/hebrew'],
    'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'package' => 'flarum-lang/hindi'],
    'hu' => ['name' => 'Hungarian', 'native' => 'Magyar', 'package' => 'flarum-lang/hungarian'],
    'is' => ['name' => 'Icelandic', 'native' => 'Íslenska', 'package' => 'flarum-lang/icelandic'],
    'id' => ['name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'package' => 'flarum-lang/indonesian'],
    'ga' => ['name' => 'Irish', 'native' => 'Gaeilge', 'package' => 'flarum-lang/irish'],
    'it' => ['name' => 'Italian', 'native' => 'Italiano', 'package' => 'flarum-lang/italian'],
    'ja' => ['name' => 'Japanese', 'native' => '日本語', 'package' => 'flarum-lang/japanese'],
    'kab' => ['name' => 'Kabyle', 'native' => 'Taqbaylit', 'package' => 'flarum-lang/kabyle'],
    'kn' => ['name' => 'Kannada', 'native' => 'ಕನ್ನಡ', 'package' => 'flarum-lang/kannada'],
    'kk' => ['name' => 'Kazakh', 'native' => 'Қазақша', 'package' => 'flarum-lang/kazakh'],
    'km' => ['name' => 'Khmer', 'native' => 'ខ្មែរ', 'package' => 'flarum-lang/khmer'],
    'ko' => ['name' => 'Korean', 'native' => '한국어', 'package' => 'flarum-lang/korean'],
    'ckb' => ['name' => 'Kurdish (Central)', 'native' => 'کوردیی ناوەندی', 'package' => 'flarum-lang/kurdish-central'],
    'kmr' => ['name' => 'Kurdish (Northern)', 'native' => 'Kurmancî', 'package' => 'flarum-lang/kurdish-northern'],
    'lv' => ['name' => 'Latvian', 'native' => 'Latviešu', 'package' => 'flarum-lang/latvian'],
    'lt' => ['name' => 'Lithuanian', 'native' => 'Lietuvių', 'package' => 'flarum-lang/lithuanian'],
    'mk' => ['name' => 'Macedonian', 'native' => 'Македонски', 'package' => 'flarum-lang/macedonian'],
    'ml' => ['name' => 'Malayalam', 'native' => 'മലയാളം', 'package' => 'flarum-lang/malayalam'],
    'ms' => ['name' => 'Malay', 'native' => 'Bahasa Melayu', 'package' => 'flarum-lang/malaysian'],
    'mr' => ['name' => 'Marathi', 'native' => 'मराठी', 'package' => 'flarum-lang/marathi'],
    'ne' => ['name' => 'Nepali', 'native' => 'नेपाली', 'package' => 'flarum-lang/nepali'],
    'nb' => ['name' => 'Norwegian Bokmål', 'native' => 'Norsk bokmål', 'package' => 'flarum-lang/norwegian-bokmal'],
    'nn' => ['name' => 'Norwegian Nynorsk', 'native' => 'Norsk nynorsk', 'package' => 'flarum-lang/norwegian-nynorsk'],
    'oc' => ['name' => 'Occitan', 'native' => 'Occitan', 'package' => 'flarum-lang/occitan'],
    'fa' => ['name' => 'Persian', 'native' => 'فارسی', 'package' => 'flarum-lang/persian'],
    'pl' => ['name' => 'Polish', 'native' => 'Polski', 'package' => 'flarum-lang/polish'],
    'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'package' => 'flarum-lang/portuguese'],
    'pa' => ['name' => 'Punjabi', 'native' => 'ਪੰਜਾਬੀ', 'package' => 'flarum-lang/punjabi'],
    'ro' => ['name' => 'Romanian', 'native' => 'Română', 'package' => 'flarum-lang/romanian'],
    'ru' => ['name' => 'Russian', 'native' => 'Русский', 'package' => 'flarum-lang/russian'],
    'sc' => ['name' => 'Sardinian', 'native' => 'Sardu', 'package' => 'flarum-lang/sardinian'],
    'sr-Cyrl' => ['name' => 'Serbian (Cyrillic)', 'native' => 'Српски (ћирилица)', 'package' => 'flarum-lang/serbian-cyrillic'],
    'sr-Latn' => ['name' => 'Serbian (Latin)', 'native' => 'Srpski (latinica)', 'package' => 'flarum-lang/serbian-latin'],
    'si' => ['name' => 'Sinhala', 'native' => 'සිංහල', 'package' => 'flarum-lang/sinhala'],
    'sk' => ['name' => 'Slovak', 'native' => 'Slovenčina', 'package' => 'flarum-lang/slovak'],
    'sl' => ['name' => 'Slovenian', 'native' => 'Slovenščina', 'package' => 'flarum-lang/slovenian'],
    'es' => ['name' => 'Spanish', 'native' => 'Español', 'package' => 'flarum-lang/spanish'],
    'es_AR' => ['name' => 'Spanish (Argentina)', 'native' => 'Español (Argentina)', 'package' => 'flarum-lang/spanish-argentina'],
    'es_MX' => ['name' => 'Spanish (Mexico)', 'native' => 'Español (México)', 'package' => 'flarum-lang/spanish-mexico'],
    'sv' => ['name' => 'Swedish', 'native' => 'Svenska', 'package' => 'flarum-lang/swedish'],
    'tl' => ['name' => 'Tagalog', 'native' => 'Tagalog', 'package' => 'flarum-lang/tagalog'],
    'tg' => ['name' => 'Tajik', 'native' => 'Тоҷикӣ', 'package' => 'flarum-lang/tajik'],
    'ta' => ['name' => 'Tamil', 'native' => 'தமிழ்', 'package' => 'flarum-lang/tamil'],
    'tt' => ['name' => 'Tatar', 'native' => 'Татарча', 'package' => 'flarum-lang/tatar'],
    'te' => ['name' => 'Telugu', 'native' => 'తెలుగు', 'package' => 'flarum-lang/telugu'],
    'th' => ['name' => 'Thai', 'native' => 'ไทย', 'package' => 'flarum-lang/thai'],
    'tok' => ['name' => 'Toki Pona', 'native' => 'toki pona', 'package' => 'flarum-lang/toki-pona'],
    'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'package' => 'flarum-lang/turkish'],
    'tk' => ['name' => 'Turkmen', 'native' => 'Türkmençe', 'package' => 'flarum-lang/turkmen'],
    'uk' => ['name' => 'Ukrainian', 'native' => 'Українська', 'package' => 'flarum-lang/ukrainian'],
    'ur' => ['name' => 'Urdu', 'native' => 'اردو', 'package' => 'flarum-lang/urdu'],
    'ug' => ['name' => 'Uyghur', 'native' => 'ئۇيغۇرچە', 'package' => 'flarum-lang/uyghur'],
    'uzb' => ['name' => 'Uzbek', 'native' => 'Oʻzbekcha', 'package' => 'flarum-lang/uzbek'],
    'vi' => ['name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'package' => 'flarum-lang/vietnamese'],
    'cy' => ['name' => 'Welsh', 'native' => 'Cymraeg', 'package' => 'flarum-lang/welsh'],
];
