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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Works out which country a request came from, without calling anything.
 *
 * Two signals, in this order: a country header set by the CDN or web server in front of the forum,
 * then the bundled dataset. The header comes first even though it is spoofable, because it is the only
 * signal that survives a reverse proxy -- Flarum 1.x resolves the visitor's address from `REMOTE_ADDR`
 * alone (`Http\Middleware\ProcessIp` honours no `X-Forwarded-For`), so behind Cloudflare or a tunnel
 * the address handed to this class may be the proxy's own while `CF-IPCountry` still describes the
 * visitor. Spoofing it changes nothing but which language is offered, and a visitor can already send
 * any `Accept-Language` they like.
 *
 * Whatever comes back is a country, never a language: `CountryLanguage` and `LocaleMatcher` decide
 * what to do about it. The address itself is read, used and dropped -- never stored, never logged.
 */
class IpCountryLookup
{
    /**
     * Country headers set by the edge, in the order they are tried. Order barely matters, since a
     * forum sits behind one CDN rather than five.
     */
    const HEADERS = [
        'CF-IPCountry',
        'CloudFront-Viewer-Country',
        'X-Vercel-IP-Country',
        'Fastly-Geo-Country',
        'X-AppEngine-Country',
    ];

    /**
     * The same signal from an nginx or Apache GeoIP module, which arrives as a server parameter
     * rather than as a header.
     */
    const SERVER_PARAMS = [
        'GEOIP_COUNTRY_CODE',
        'MM_COUNTRY_CODE',
    ];

    /**
     * Codes that are shaped like a country but assert the absence of one: Cloudflare answers `XX`
     * when it cannot place an address and `T1` for Tor exit nodes, and `ZZ` is the RIR files'
     * stand-in for "no country".
     *
     * The RIR extras `EU` and `AP` are *not* rejected -- they are real allocations that simply span
     * countries, and `resources/countries.php` leaves them unmapped, which yields no candidates
     * without pretending the lookup failed.
     */
    const PLACEHOLDERS = ['XX', 'T1', 'ZZ'];

    const FILE_V4 = 'ip4.dat';
    const FILE_V6 = 'ip6.dat';
    const FILE_INFO = 'ip-data.php';

    /**
     * Record and key widths: a big-endian range start followed by two ASCII country characters, with
     * no end address and no length -- the files are contiguous, so the next record's start is this
     * record's end.
     */
    const RECORD_V4 = 6;
    const RECORD_V6 = 10;
    const KEY_V4 = 4;
    const KEY_V6 = 8;

    /**
     * The 96-bit prefix of an IPv4-mapped IPv6 address (`::ffff:0:0/96`).
     */
    const MAPPED_V4_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    protected string $directory;

    /**
     * @param string|null $directory override for tests; defaults to the shipped dataset
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? dirname(__DIR__).'/resources';
    }

    /**
     * @return string|null an uppercase ISO 3166-1 alpha-2 code, or null if unknown
     */
    public function countryFor(ServerRequestInterface $request): ?string
    {
        $fromEdge = $this->fromEdge($request);

        if ($fromEdge !== null) {
            return $fromEdge;
        }

        $ip = $request->getAttribute('ipAddress');

        // `ProcessIp` always sets this attribute, and always to a string, but this class is
        // handed whatever the request carries rather than asking for the address itself.
        return $this->countryForIp(is_string($ip) ? $ip : null);
    }

    /**
     * The dataset's answer for one address, with no headers involved.
     */
    public function countryForIp(?string $ip): ?string
    {
        $address = $this->publicAddress($ip);

        if ($address === null) {
            return null;
        }

        // IPv6 records key on the top 64 bits only: a /64 is the smallest block anyone is
        // assigned, so the lower half never changes the answer and halving the key halves the file.
        return strlen($address) === 4
            ? $this->search(self::FILE_V4, self::RECORD_V4, self::KEY_V4, $address)
            : $this->search(self::FILE_V6, self::RECORD_V6, self::KEY_V6, substr($address, 0, self::KEY_V6));
    }

    /**
     * What the bundled dataset was built from and when, for the admin notice.
     *
     * Null when the dataset is not installed -- which is the state the extension has to keep
     * working in, on browser detection alone.
     *
     * @return array<string, mixed>|null
     */
    public function datasetInfo(): ?array
    {
        $path = $this->directory.'/'.self::FILE_INFO;

        if (! is_file($path)) {
            return null;
        }

        // The build date is generated into this file rather than read from `filemtime()`, because
        // git sets modification times to checkout time -- the notice would otherwise report the date
        // the forum was installed and claim the data is fresh.
        $info = require $path;

        return is_array($info) ? $info : null;
    }

    /**
     * A routable address in packed form, or null if there is nothing worth looking up.
     */
    protected function publicAddress(?string $ip): ?string
    {
        $ip = is_string($ip) ? trim($ip) : '';

        // Syntax first, so `inet_pton()` is only ever handed something it can parse.
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $address = (string) inet_pton($ip);

        // An IPv4 address in IPv6 clothing is what a dual-stack listener hands PHP for an IPv4
        // client. Unwrapping it is not a nicety: `::ffff:0:0/96` is itself a reserved range, so the
        // check below would discard every such address, and unwrapping first is also what makes
        // `::ffff:192.168.1.1` recognisably private.
        if (strlen($address) === 16 && strncmp($address, self::MAPPED_V4_PREFIX, 12) === 0) {
            $address = substr($address, 12);
            $ip = (string) inet_ntop($address);
        }

        // Loopback, private and reserved ranges all fall out here -- including `127.0.0.1`, which is
        // what `ProcessIp` uses when there is no `REMOTE_ADDR` at all.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $address;
    }

    /**
     * The first country header or server parameter that carries a real country.
     */
    protected function fromEdge(ServerRequestInterface $request): ?string
    {
        foreach (self::HEADERS as $header) {
            $country = $this->country($request->getHeaderLine($header));

            if ($country !== null) {
                return $country;
            }
        }

        $params = $request->getServerParams();

        foreach (self::SERVER_PARAMS as $param) {
            $value = $params[$param] ?? null;

            $country = is_string($value) ? $this->country($value) : null;

            if ($country !== null) {
                return $country;
            }
        }

        return null;
    }

    /**
     * Binary search for the last record whose range starts at or before the address.
     *
     * The file is contiguous and exhaustive, so "unknown" is spelled as a record rather than a hole.
     */
    protected function search(string $file, int $recordSize, int $keySize, string $key): ?string
    {
        $path = $this->directory.'/'.$file;

        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);
        $records = $size === false ? 0 : intdiv($size, $recordSize);

        if ($records === 0) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $low = 0;
            $high = $records - 1;
            $found = null;

            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);

                if (fseek($handle, $middle * $recordSize) !== 0) {
                    return null;
                }

                $record = fread($handle, $recordSize);

                if (! is_string($record) || strlen($record) !== $recordSize) {
                    return null;
                }

                // Keys are compared as raw big-endian bytes and never unpacked into integers.
                // `unpack('J')` on an IPv6 key at or above `8000::` yields a negative number on
                // every 64-bit build of PHP, which would invert the search for a sixteenth of the
                // address space. Big-endian byte order makes a byte-wise comparison identical to an
                // unsigned numeric one, and `strcmp` is binary-safe.
                if (strcmp(substr($record, 0, $keySize), $key) <= 0) {
                    $found = substr($record, $keySize, 2);
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
                }
            }

            return $this->country($found);
        } finally {
            fclose($handle);
        }
    }

    /**
     * A country code, if that is what this really is. Guards the dataset's `\0\0` unknown marker and
     * anything a proxy might put in a header, so callers never have to.
     */
    protected function country(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, self::PLACEHOLDERS, true)) {
            return null;
        }

        return $code;
    }
}
