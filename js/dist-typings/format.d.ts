/// <reference types="flarum/@types/translator-icu-rich" />
export declare function trans(key: string, parameters?: Record<string, unknown>): import("@askvortsov/rich-icu-message-formatter").NestedStringArray;
/**
 * A country's name in the administrator's own language, falling back to its code.
 *
 * `''` is the caller's problem: it is not a country, and "Unknown" is a translated string.
 */
export declare function countryName(code: string): string;
/**
 * How a requested language is named in a table cell.
 *
 * Two cases have no name to print and they are not the same case. `locale === ''` is a visitor whose
 * browser stated no preference, often the biggest row on the page; a null `name` is a tag no bundled
 * language pack answers. Neither may render as an empty cell, and neither may borrow the other's label.
 */
export declare function languageLabel(locale: string, name: string | null): import("@askvortsov/rich-icu-message-formatter").NestedStringArray;
/**
 * A count, grouped for readability in the administrator's own language.
 */
export declare function count(value: number): string;
/**
 * `part` as a whole-number percentage of `whole`, and zero rather than `NaN` when `whole` is -- which
 * is every forum on its first day, where `0 / 0` would render on a card as "NaN%".
 */
export declare function percentage(part: number, whole: number): number;
/**
 * The value the tallest bar in a chart stands for. Never zero: an all-zero window is a forum that has
 * just switched analytics on, and scaling by its own maximum would divide by zero.
 */
export declare function scale(values: number[]): number;
