<?php

/*
 * A stand-in for the generated resources/ip-data.php, so datasetInfo() can be tested against
 * values no real build would ever produce. Hand-written, unlike the file it stands in for.
 */

return [
    'built' => '2026-01-02',
    'data_date' => '2026-01-01',
    'source' => 'test fixture',
    'registries' => ['afrinic', 'apnic', 'arin', 'lacnic', 'ripencc'],
    'ipv4_records' => 6,
    'ipv6_records' => 6,
];
