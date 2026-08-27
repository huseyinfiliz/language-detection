#!/usr/bin/env php
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
 * Rebuilds `resources/ip4.dat`, `resources/ip6.dat` and `resources/ip-data.php` from the
 * five regional registries' published statistics.
 *
 * Maintainer-facing: this never runs at runtime -- the extension only ever reads what it
 * produces -- so it is allowed to be slow, to talk to the network, and to hold the whole
 * dataset in memory.
 *
 *     php scripts/build-ip-data.php                  download, build, verify
 *     php scripts/build-ip-data.php --source=DIR      build from files already downloaded
 *     php scripts/build-ip-data.php --out=DIR         write somewhere other than resources/
 *
 * Output format (`IpCountryLookup` is the reader):
 *
 *     ip4.dat    6-byte records: 4-byte big-endian range start + 2 ASCII country characters
 *     ip6.dat   10-byte records: 8-byte big-endian top half of the start + the same 2 chars
 *
 * Records are sorted, contiguous and exhaustive. A record carries no end address because
 * the next record's start is its end, and address space nobody has been allocated is spelled
 * out as a record whose country is "\0\0" rather than left as a hole. That is what lets the
 * reader answer any address with one binary search and no bounds arithmetic.
 *
 * Addresses are handled as raw big-endian byte strings from parsing through to output, and
 * compared with `strcmp`. That is not a stylistic choice: an IPv6 key is 64 bits wide, so
 * half of the key space does not fit in a PHP integer and `unpack('J', ...)` would report
 * everything at or above 8000:: as negative. Byte strings have no such ceiling, and because
 * big-endian order makes a byte-wise comparison identical to an unsigned numeric one, one
 * set of routines handles IPv4 and IPv6 without knowing which it is holding.
 */

const REGISTRIES = [
    'afrinic' => 'https://ftp.afrinic.net/stats/afrinic/delegated-afrinic-extended-latest',
    'apnic' => 'https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest',
    'arin' => 'https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest',
    'lacnic' => 'https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-extended-latest',
    'ripencc' => 'https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest',
];

/**
 * The two statuses that mean "somebody holds this address space". `available` and
 * `reserved` rows are exactly what the gap filler exists for, and `summary` rows are
 * counters rather than ranges.
 */
const USABLE = ['allocated', 'assigned'];

/**
 * The country of address space nobody holds. Two bytes, so it costs what every other
 * record costs.
 */
const UNKNOWN = "\0\0";

/**
 * Key widths in bytes: a whole IPv4 address, and the top half of an IPv6 one.
 */
const WIDTH_V4 = 4;
const WIDTH_V6 = 8;

/**
 * IPv6 keys cover the top 64 bits, which is as fine as any allocation gets.
 */
const V6_KEY_BITS = 64;

exit(main($argv));

function main(array $argv): int
{
    $options = options($argv);
    $out = rtrim($options['out'] ?? dirname(__DIR__).'/resources', "/\\");

    if (! is_dir($out)) {
        return fail("not a directory: $out");
    }

    $ranges = [WIDTH_V4 => [], WIDTH_V6 => []];
    $dataDate = null;
    $skipped = 0;

    foreach (REGISTRIES as $registry => $url) {
        $body = isset($options['source'])
            ? read(rtrim($options['source'], "/\\").'/'.basename($url))
            : download($url);

        if ($body === null) {
            return fail("could not read the $registry statistics");
        }

        report(sprintf('  %-8s %9s', $registry, bytes(strlen($body))));

        $dataDate = later($dataDate, parseInto($body, $ranges, $skipped));

        // Five bodies of 1-18 MB each, and nothing needs them a second time.
        unset($body);
    }

    if ($skipped > 0) {
        report(sprintf('  %d row(s) skipped as unparseable', $skipped));
    }

    report('');
    report(sprintf(
        'parsed  %s IPv4 ranges, %s IPv6 ranges, data dated %s',
        number(count($ranges[WIDTH_V4])),
        number(count($ranges[WIDTH_V6])),
        $dataDate ?? 'unknown'
    ));

    [$records4, $count4] = build($ranges[WIDTH_V4], WIDTH_V4);
    [$records6, $count6] = build($ranges[WIDTH_V6], WIDTH_V6);

    unset($ranges);

    report(sprintf(
        'built   %s IPv4 records (%s), %s IPv6 records (%s)',
        number($count4),
        bytes(strlen($records4)),
        number($count6),
        bytes(strlen($records6))
    ));

    $written = write($out.'/ip4.dat', $records4)
        && write($out.'/ip6.dat', $records6)
        && write($out.'/ip-data.php', metadata($count4, $count6, $dataDate));

    if (! $written) {
        return fail('could not write the dataset');
    }

    report('');

    return verify($out) ? 0 : fail('the dataset was written but does not read back correctly');
}

/**
 * Long options only, and only the two that exist: `--source=DIR`, `--out=DIR`.
 *
 * @return array<string, string>
 */
function options(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(source|out)=(.+)$/', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2];
        } else {
            report("ignoring unrecognised argument: $argument");
        }
    }

    return $options;
}

function download(string $url): ?string
{
    report("fetching $url");

    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_USERAGENT => 'huseyinfiliz/language-detection dataset builder',
        ]);

        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        return is_string($body) && $status === 200 ? $body : null;
    }

    // No cURL extension: fall back to the stream wrapper, which needs `allow_url_fopen`.
    return read($url);
}

function read(string $path): ?string
{
    $body = @file_get_contents($path);

    return is_string($body) ? $body : null;
}

/**
 * Pull every usable range out of one registry's file, appending to the two range lists.
 *
 * A range is stored as one string -- start, then end, then the country -- so that plain
 * string sorting puts the whole set in address order later, without a comparison callback
 * over a quarter of a million elements.
 *
 * @param array<int, string[]> $ranges keyed by key width
 * @return string|null the date the file says it describes, as `YYYYMMDD`
 */
function parseInto(string $body, array &$ranges, int &$skipped): ?string
{
    $fileDate = null;

    foreach (explode("\n", $body) as $line) {
        $line = rtrim($line, "\r");

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $fields = explode('|', $line);

        if (count($fields) < 7) {
            continue;
        }

        // registry|cc|type|start|value|date|status, per the RIR exchange format. The value
        // is a count of addresses for IPv4 and a prefix length for IPv6, which is why the
        // two are parsed separately below.
        [, $country, $type, $start, $value, $date, $status] = $fields;

        // The one line whose type field is not a record type is the file header, and its
        // sixth field is the date the registry says this snapshot describes -- which is what
        // an administrator should be shown, rather than the day this script happened to run.
        if ($type !== 'ipv4' && $type !== 'ipv6' && $type !== 'asn') {
            if (preg_match('/^\d{8}$/', $date) === 1) {
                $fileDate = later($fileDate, $date);
            }

            continue;
        }

        if ($type === 'asn' || ! in_array($status, USABLE, true)) {
            continue;
        }

        // One APNIC row ships with an empty country. `EU` and `AP` are *not* filtered here:
        // they are real allocations that simply span countries, and leaving them unmapped in
        // `resources/countries.php` yields no candidates without pretending the lookup failed.
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $skipped++;

            continue;
        }

        $range = $type === 'ipv4' ? rangeV4($start, $value) : rangeV6($start, $value);

        if ($range === null) {
            $skipped++;

            continue;
        }

        $ranges[$type === 'ipv4' ? WIDTH_V4 : WIDTH_V6][] = $range[0].$range[1].$country;
    }

    return $fileDate;
}

/**
 * `start` plus a *count of addresses* -- which is not always a power of two (750 rows in the
 * August 2026 files are not), and does not have to be: this format stores range starts, not
 * prefixes, so an arbitrary length needs no splitting.
 *
 * @return string[]|null [start, inclusive end], both 4 bytes
 */
function rangeV4(string $start, string $count): ?array
{
    if (filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }

    $addresses = (int) $count;

    if ($addresses < 1) {
        return null;
    }

    $first = (string) inet_pton($start);
    $last = add($first, $addresses - 1);

    // A range running off the end of the address space is malformed; there is no such row
    // today and guessing at a repair would be worse than dropping it.
    return $last === null ? null : [$first, $last];
}

/**
 * `start` plus a *prefix length*, collapsed onto the top 64 bits of the address.
 *
 * A prefix shorter than /64 spans 2^(64 - length) keys; /64 and anything longer lives
 * inside a single key. There are no prefixes longer than /64 in the registries today, but a
 * /96 would be one key rather than none.
 *
 * @return string[]|null [start, inclusive end], both 8 bytes
 */
function rangeV6(string $start, string $length): ?array
{
    if (filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return null;
    }

    $bits = (int) $length;

    if ($bits < 1 || $bits > 128) {
        return null;
    }

    $first = substr((string) inet_pton($start), 0, WIDTH_V6);
    $keys = $bits >= V6_KEY_BITS ? 1 : 1 << (V6_KEY_BITS - $bits);
    $last = add($first, $keys - 1);

    return $last === null ? null : [$first, $last];
}

/**
 * Turn sorted ranges into the record stream the reader expects.
 *
 * Three things happen here, in this order for each range: an "unknown" record is emitted if
 * the previous range stopped short of this one, overlaps are trimmed away, and a record that
 * would repeat the country the file is already in is dropped. The last two are what keeps
 * the file honest and small -- the registries publish a handful of overlapping rows, and
 * neighbouring allocations to the same country are extremely common.
 *
 * @param string[] $ranges
 * @return array{0: string, 1: int}
 */
function build(array $ranges, int $width): array
{
    // Byte-wise, which for big-endian keys is address order. Sorting the packed strings
    // means no comparison callback is invoked a few million times.
    sort($ranges, SORT_STRING);

    $records = '';
    $count = 0;
    $country = null;
    $covered = null;

    $emit = function (string $key, string $code) use (&$records, &$count, &$country) {
        // Every record this function is handed begins exactly where the previous one's range
        // ended, so a record repeating the current country adds nothing: the record already
        // in the file covers it.
        if ($code === $country) {
            return;
        }

        $records .= $key.$code;
        $count++;
        $country = $code;
    };

    foreach ($ranges as $range) {
        $start = substr($range, 0, $width);
        $end = substr($range, $width, $width);
        $code = substr($range, 2 * $width, 2);

        if ($covered === null) {
            // Address space below the first allocation. Emitting it rather than leaving the
            // file to start mid-space is what makes "contiguous and exhaustive" true.
            $zero = str_repeat("\0", $width);

            if (strcmp($start, $zero) > 0) {
                $emit($zero, UNKNOWN);
            }
        } else {
            if (strcmp($end, $covered) <= 0) {
                // Wholly inside what is already covered.
                continue;
            }

            $next = add($covered, 1);

            if ($next === null) {
                break;
            }

            if (strcmp($start, $next) <= 0) {
                // Overlaps what is already covered: the earlier claim keeps the overlap and
                // this range takes what is left of it.
                $start = $next;
            } else {
                $emit($next, UNKNOWN);
            }
        }

        $emit($start, $code);

        $covered = $end;
    }

    // Everything above the last allocation is unknown too, and saying so explicitly is what
    // stops the last country from swallowing the rest of the address space.
    $tail = $covered === null ? null : add($covered, 1);

    if ($tail !== null) {
        $emit($tail, UNKNOWN);
    }

    return [$records, $count];
}

/**
 * Add to a big-endian key, byte by byte.
 *
 * Byte arithmetic rather than integer arithmetic because a 64-bit key does not fit in a
 * signed PHP integer. Returns null when the addition would run past the end of the address
 * space the key describes.
 */
function add(string $key, int $amount): ?string
{
    for ($i = strlen($key) - 1; $i >= 0 && $amount > 0; $i--) {
        $sum = ord($key[$i]) + ($amount & 0xff);
        $amount >>= 8;

        if ($sum > 0xff) {
            $sum -= 0x100;
            $amount++;
        }

        $key[$i] = chr($sum);
    }

    return $amount > 0 ? null : $key;
}

/**
 * The sidecar the admin notice reads.
 *
 * It exists because git sets file modification times to checkout time: `filemtime()` would
 * report the day the forum was installed and quietly claim the data is fresh.
 */
function metadata(int $count4, int $count6, ?string $dataDate): string
{
    $date = function (?string $compact): string {
        return $compact === null
            ? 'unknown'
            : substr($compact, 0, 4).'-'.substr($compact, 4, 2).'-'.substr($compact, 6, 2);
    };

    return '<?php'."\n\n"
        .'/*'."\n"
        .' * Generated by scripts/build-ip-data.php. Do not edit by hand.'."\n"
        .' *'."\n"
        .' * `built` is when the dataset was generated; `data_date` is the date the registries'."\n"
        .' * say their statistics describe, which is the one worth showing an administrator.'."\n"
        .' */'."\n\n"
        .'return ['."\n"
        ."    'built' => '".gmdate('Y-m-d')."',\n"
        ."    'data_date' => '".$date($dataDate)."',\n"
        ."    'source' => 'RIR delegated-extended statistics',\n"
        ."    'registries' => ['".implode("', '", array_keys(REGISTRIES))."'],\n"
        ."    'ipv4_records' => $count4,\n"
        ."    'ipv6_records' => $count6,\n"
        .'];'."\n";
}

/**
 * Read the files back with the shipped reader and check addresses whose country is known
 * independently of this script.
 *
 * The reader is required directly rather than through composer's autoloader so that this
 * works in a bare checkout. `countryForIp()` touches nothing but the filesystem, so the
 * PSR-7 type hint on the class's other method never has to resolve.
 */
function verify(string $out): bool
{
    require dirname(__DIR__).'/src/IpCountryLookup.php';

    $lookup = new HuseyinFiliz\LanguageDetection\IpCountryLookup($out);

    $expected = [
        '8.8.8.8' => 'US',
        '1.1.1.1' => 'AU',
        '212.156.44.5' => 'TR',
        '2001:4860:4860::8888' => 'US',
        '2606:4700::1111' => 'US',
        '2001:930::1' => 'TR',
        // Rejected before the files are opened at all.
        '127.0.0.1' => null,
        '192.168.1.1' => null,
        'not an address' => null,
    ];

    $failed = 0;

    foreach ($expected as $ip => $country) {
        $actual = $lookup->countryForIp((string) $ip);

        if ($actual !== $country) {
            $failed++;

            report(sprintf('  FAIL %-22s expected %s, got %s', $ip, $country ?? 'null', $actual ?? 'null'));
        }
    }

    $info = $lookup->datasetInfo();

    report($failed === 0
        ? sprintf('verified %d addresses, data dated %s', count($expected), $info['data_date'] ?? 'unknown')
        : sprintf('%d of %d checks failed', $failed, count($expected)));

    return $failed === 0;
}

function write(string $path, string $contents): bool
{
    $written = file_put_contents($path, $contents);

    if ($written === false) {
        return false;
    }

    report(sprintf('wrote   %-12s %9s', basename($path), bytes($written)));

    return true;
}

/**
 * The later of two `YYYYMMDD` dates, either of which may be missing.
 */
function later(?string $one, ?string $other): ?string
{
    if ($one === null || $other === null) {
        return $one ?? $other;
    }

    return strcmp($one, $other) >= 0 ? $one : $other;
}

function bytes(int $count): string
{
    return $count < 1024 * 1024
        ? number((int) round($count / 1024)).' KB'
        : sprintf('%.1f MB', $count / 1024 / 1024);
}

function number(int $count): string
{
    return number_format($count);
}

function report(string $line): void
{
    fwrite(STDOUT, $line."\n");
}

function fail(string $message): int
{
    fwrite(STDERR, "error: $message\n");

    return 1;
}
