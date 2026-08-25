<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Tests\Unit;

use Flarum\Testing\unit\TestCase;
use HuseyinFiliz\LanguageDetection\IpCountryLookup;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * These tests run against tests/fixtures/ip-dataset/, not the shipped 2.2 MB dataset: the
 * registries reallocate address space, so asserting on the real files would write a test that
 * fails one day for a reason that is nobody's fault. Six records each is enough to reach every
 * branch of the search.
 *
 * The fixture is laid out below so it can be read without a hex editor. `\0\0` is the format's
 * "nobody holds this" marker (HANDOFF.md §3), and both files begin and end with one because
 * the format is contiguous and exhaustive rather than a list of islands.
 *
 *     ip4.dat -- 6-byte records, 4-byte big-endian start + 2 country characters
 *       00 00 00 00  \0\0     everything below the first allocation
 *       08 00 00 00  US       8.0.0.0 - 8.255.255.255
 *       09 00 00 00  \0\0     a gap between two allocations
 *       14 00 00 00  TR       20.0.0.0 - 29.255.255.255
 *       1e 00 00 00  DE       30.0.0.0 - 30.255.255.255, adjacent to TR with no gap
 *       1f 00 00 00  \0\0     everything above the last allocation
 *
 *     ip6.dat -- 10-byte records, 8-byte big-endian top half of the start + the same 2 chars
 *       0000:0000:0000:0000  \0\0
 *       2001:0200:0000:0000  JP
 *       2001:0300:0000:0000  \0\0
 *       2a01:04f8:0000:0000  DE
 *       9000:0000:0000:0000  TR    deliberately above 8000:: -- see the strcmp test
 *       9001:0000:0000:0000  \0\0
 */
class IpCountryLookupTest extends TestCase
{
    public function test_the_fixture_is_the_shape_these_tests_assume()
    {
        // Not a tautology: the records are read at fixed byte offsets, so a fixture that got
        // through git with its line endings rewritten would make every other test in this
        // file fail for a reason none of them would name. 6 records of 6 and 10 bytes.
        $this->assertSame(36, filesize($this->directory().'/ip4.dat'));
        $this->assertSame(60, filesize($this->directory().'/ip6.dat'));
    }

    public function test_it_finds_the_country_an_address_falls_inside()
    {
        $lookup = $this->lookup();

        $this->assertSame('US', $lookup->countryForIp('8.8.8.8'));
        $this->assertSame('TR', $lookup->countryForIp('20.30.40.50'));
        $this->assertSame('DE', $lookup->countryForIp('30.99.1.1'));
    }

    public function test_a_record_covers_its_own_first_address()
    {
        // The search takes the greatest start at or before the key, so an exact hit is the
        // boundary case where `<=` rather than `<` is what makes the answer right.
        $lookup = $this->lookup();

        $this->assertSame('US', $lookup->countryForIp('8.0.0.0'));
        $this->assertSame('TR', $lookup->countryForIp('20.0.0.0'));
    }

    public function test_a_record_stops_where_the_next_one_starts()
    {
        // No record stores an end address; the next record's start is the end. These two pairs
        // are what that claim means in practice, across a gap and across a country change.
        $lookup = $this->lookup();

        $this->assertSame('US', $lookup->countryForIp('8.255.255.255'));
        $this->assertNull($lookup->countryForIp('9.0.0.0'));

        $this->assertSame('TR', $lookup->countryForIp('29.255.255.255'));
        $this->assertSame('DE', $lookup->countryForIp('30.0.0.0'));
    }

    public function test_unallocated_space_is_not_a_country()
    {
        // The `\0\0` marker has to be filtered where the record is read, not left to callers:
        // it is two bytes in the country field like any other, and would otherwise arrive at
        // `CountryLanguage` looking like a code.
        $lookup = $this->lookup();

        $this->assertNull($lookup->countryForIp('9.4.5.6'));
        $this->assertNull($lookup->countryForIp('2001:300::1'));
    }

    public function test_an_address_below_the_first_record_has_no_country()
    {
        $this->assertNull($this->lookup()->countryForIp('1.2.3.4'));
    }

    public function test_the_last_country_does_not_swallow_the_rest_of_the_address_space()
    {
        // The high end of the search, and the reason the generator emits a trailing unknown
        // record: without it every address above the last allocation would answer `DE`.
        $lookup = $this->lookup();

        $this->assertNull($lookup->countryForIp('31.0.0.0'));
        $this->assertNull($lookup->countryForIp('200.1.2.3'));
    }

    public function test_an_ipv6_address_is_matched_on_its_top_half_alone()
    {
        // Records key on the top 64 bits, so everything inside a /64 answers the same -- which
        // is what lets ip6.dat be half the size it would otherwise be.
        $lookup = $this->lookup();

        $this->assertSame('JP', $lookup->countryForIp('2001:200::1'));
        $this->assertSame('JP', $lookup->countryForIp('2001:200:ffff:ffff:ffff:ffff:ffff:ffff'));
        $this->assertSame('DE', $lookup->countryForIp('2a01:4f8:1c17:6f8::1'));
    }

    public function test_an_ipv6_address_above_8000_is_not_read_as_negative()
    {
        // The regression test the fixture's fifth record exists for. An IPv6 key is 64 bits
        // wide, so `unpack('J', ...)` on anything at or above 8000:: yields a *negative* PHP
        // integer: the key would sort below every record in the file and the binary search
        // would silently invert for a sixteenth of the address space. `strcmp` on raw
        // big-endian bytes has no such ceiling, and this asserts it.
        $lookup = $this->lookup();

        $this->assertSame('TR', $lookup->countryForIp('9000::'));
        $this->assertSame('TR', $lookup->countryForIp('9000::1'));
        $this->assertNull($lookup->countryForIp('9001::1'));
    }

    public function test_an_ipv4_address_wearing_ipv6_clothing_is_unwrapped()
    {
        // What a dual-stack listener hands PHP for an IPv4 client. It has to be unwrapped
        // *before* the reserved-range filter, because `::ffff:0:0/96` is itself reserved --
        // otherwise every such visitor would be discarded.
        $this->assertSame('US', $this->lookup()->countryForIp('::ffff:8.8.8.8'));
    }

    public function test_an_address_worth_nothing_is_never_looked_up()
    {
        $lookup = $this->lookup();

        foreach ([
            '127.0.0.1',            // what ProcessIp uses when there is no REMOTE_ADDR at all
            '10.0.0.1',
            '192.168.1.1',
            '172.16.0.1',
            '::1',
            'fd00::1',
            '::ffff:192.168.1.1',   // private once unwrapped, and only then
            'not an address',
            '8.8.8',
            '',
            null,
        ] as $ip) {
            $this->assertNull($lookup->countryForIp($ip), var_export($ip, true));
        }
    }

    public function test_a_missing_dataset_is_not_an_error()
    {
        // The state a checkout without the data files is in, and one the extension has to keep
        // working in: browser detection carries it, and nothing warns or throws.
        $lookup = new IpCountryLookup(__DIR__.'/nowhere');

        $this->assertNull($lookup->countryForIp('8.8.8.8'));
        $this->assertNull($lookup->countryForIp('2001:200::1'));
        $this->assertNull($lookup->datasetInfo());
    }

    public function test_it_reads_the_country_a_cdn_reports()
    {
        foreach (IpCountryLookup::HEADERS as $header) {
            $request = $this->request()->withHeader($header, 'TR');

            $this->assertSame('TR', $this->lookup()->countryFor($request), $header);
        }
    }

    public function test_it_reads_the_country_a_web_server_module_reports()
    {
        foreach (IpCountryLookup::SERVER_PARAMS as $param) {
            $request = new ServerRequest([$param => 'TR'], [], '/', 'GET');

            $this->assertSame('TR', $this->lookup()->countryFor($request), $param);
        }
    }

    public function test_a_cdn_header_beats_the_connecting_address()
    {
        // The case that decides the order of the two signals. Flarum 1.x resolves the visitor
        // from REMOTE_ADDR alone -- it honours no X-Forwarded-For -- so behind a CDN the
        // address this class is handed is the proxy's, and reading the header second would
        // mean never reading it at all on the deployments that set it.
        $request = $this->request('192.168.1.1')->withHeader('CF-IPCountry', 'JP');

        $this->assertSame('JP', $this->lookup()->countryFor($request));

        // And it outranks an address that would have answered on its own.
        $request = $this->request('8.8.8.8')->withHeader('CF-IPCountry', 'JP');

        $this->assertSame('JP', $this->lookup()->countryFor($request));
    }

    public function test_the_dataset_answers_when_no_edge_header_was_set()
    {
        $this->assertSame('US', $this->lookup()->countryFor($this->request('8.8.8.8')));
    }

    public function test_a_header_that_is_not_a_country_falls_through_to_the_dataset()
    {
        // `XX` is what Cloudflare sends when it cannot place an address and `T1` is a Tor exit
        // node; neither is a country, and treating them as one would mean answering with no
        // candidates rather than trying the signal that might still work.
        foreach (['XX', 'T1', 'ZZ', '', 'TUR', 'T', '??', '12'] as $value) {
            $request = $this->request('8.8.8.8')->withHeader('CF-IPCountry', $value);

            $this->assertSame('US', $this->lookup()->countryFor($request), var_export($value, true));
        }
    }

    public function test_a_lowercase_header_is_still_a_country()
    {
        $request = $this->request()->withHeader('CF-IPCountry', ' tr ');

        $this->assertSame('TR', $this->lookup()->countryFor($request));
    }

    public function test_a_request_carrying_no_address_at_all_is_survivable()
    {
        // ProcessIp always sets the attribute, but this class takes the request it is given
        // rather than trusting a middleware two layers away to have run.
        $this->assertNull($this->lookup()->countryFor($this->request()));
    }

    public function test_it_reports_what_the_dataset_was_built_from()
    {
        // The admin notice's whole source of truth. `built` is generated into the file rather
        // than read from `filemtime()`, because git sets modification times to checkout time
        // and the notice would otherwise report the day the forum was installed.
        $info = $this->lookup()->datasetInfo();

        $this->assertSame('2026-01-01', $info['data_date']);
        $this->assertSame('2026-01-02', $info['built']);
        $this->assertSame(6, $info['ipv4_records']);
    }

    protected function lookup(): IpCountryLookup
    {
        return new IpCountryLookup($this->directory());
    }

    protected function directory(): string
    {
        return __DIR__.'/../fixtures/ip-dataset';
    }

    protected function request(?string $ip = null): ServerRequestInterface
    {
        $request = new ServerRequest([], [], '/', 'GET');

        // The attribute name and its shape are ProcessIp's, which is the only thing that ever
        // sets it in a real request.
        return $ip === null ? $request : $request->withAttribute('ipAddress', $ip);
    }
}
