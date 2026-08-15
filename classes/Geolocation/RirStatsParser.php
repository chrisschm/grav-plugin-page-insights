<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

/**
 * Parses the combined "delegated-extended" statistics file published daily
 * by the five Regional Internet Registries (RIPE NCC, ARIN, APNIC, LACNIC,
 * AFRINIC) via the RIPE-NCC/nro-delegated-stats project (see
 * https://github.com/RIPE-NCC/nro-delegated-stats and
 * https://ftp.ripe.net/pub/stats/ripencc/nro-stats/latest/nro-delegated-stats).
 *
 * Format spec: https://ftp.ripe.net/ripe/stats/RIR-Statistics-Exchange-Format.txt
 *
 * This replaces the composer.json `ip2location/ip2location-php` dependency
 * and the committed (and, per IP2Location LITE's own terms, not actually
 * redistributable-via-public-repo) `data/IP2LOCATION-LITE-DB3.BIN` file. Only
 * country-level data is extracted, since that's the only field the plugin
 * (Stats.php / admin-next "Top countries") actually reads - region/city were
 * collected but never displayed anywhere (see docs/ARCHITECTURE.md).
 *
 * This parser is intentionally source-format-only: it has no knowledge of
 * HTTP, files, or the on-disk lookup format - see CountryIndexBuilder for
 * that. Keeping it pure text-in/ranges-out makes it independently testable
 * against a small in-memory fixture instead of the real, tens-of-MB file.
 */
class RirStatsParser
{
    /**
     * A record line only ever reflects an RIR's own allocation/assignment
     * action - "allocated" and "assigned" are the only two statuses that
     * represent a real, in-use resource with a known holder country.
     * "available" (not yet delegated) and "reserved" rows exist in the real
     * file and must be skipped, not treated as a country match.
     */
    private const USABLE_STATUSES = ['allocated', 'assigned'];

    /**
     * Parse the full text of a delegated-extended stats file.
     *
     * @return array{
     *     recordsDeclared: ?int,
     *     recordsParsed: int,
     *     sourceDate: ?string,
     *     ranges: list<array{cc: string, start: int|string, end: int|string, version: 4|6}>
     * }
     */
    public function parse(string $text): array
    {
        $recordsDeclared = null;
        $sourceDate = null;
        $ranges = [];
        $recordsParsed = 0;

        // Line endings vary depending on which mirror served the file.
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode('|', $line);

            // Version/header line: version|registry|serial|records|startdate|enddate|UTCoffset
            // Only ever the first non-comment line, but checking the field
            // count (7) rather than a line counter keeps this resilient to
            // an unexpected blank line or leading comment. "enddate" (the
            // period this snapshot covers up to) is surfaced as sourceDate
            // so the admin UI can show how fresh the currently built index
            // actually is, independent of when it was last (re)built.
            if ($recordsDeclared === null && count($fields) === 7 && ctype_digit($fields[3])) {
                $recordsDeclared = (int) $fields[3];
                $sourceDate = ctype_digit($fields[5]) ? $fields[5] : null;
                continue;
            }

            // Summary line: registry|*|type|*|count|summary - the literal
            // '*' and trailing 'summary' marker are what distinguish it from
            // a record line, which never contains either.
            if (count($fields) >= 6 && $fields[1] === '*' && end($fields) === 'summary') {
                continue;
            }

            // Record line: registry|cc|type|start|value|date|status|opaque-id[|extensions...]
            if (count($fields) < 7) {
                // Malformed/unexpected line shape - skip rather than throw,
                // an occasional oddity anywhere in a multi-hundred-thousand
                // line file shouldn't fail the whole build.
                continue;
            }

            [$registry, $cc, $type, $start, $value, $date, $status] = array_slice($fields, 0, 7);
            $recordsParsed++;

            if (!in_array($status, self::USABLE_STATUSES, true)) {
                continue;
            }
            if ($type !== 'ipv4' && $type !== 'ipv6') {
                continue; // 'asn' records - not IP ranges, nothing to index.
            }
            if ($cc === '' || strlen($cc) !== 2) {
                continue; // Defensive - every real record carries a 2-letter cc.
            }

            $cc = strtoupper($cc);

            if ($type === 'ipv4') {
                $startInt = Ip::toNumber($start);
                $count = (int) $value;
                if ($count <= 0) {
                    continue;
                }
                $ranges[] = [
                    'cc' => $cc,
                    'start' => $startInt,
                    'end' => $startInt + $count - 1,
                    'version' => 4,
                ];
            } else {
                $startBin = @inet_pton($start);
                $prefixLen = (int) $value;
                if ($startBin === false || strlen($startBin) !== 16 || $prefixLen < 0 || $prefixLen > 128) {
                    continue;
                }
                $ranges[] = [
                    'cc' => $cc,
                    'start' => $startBin,
                    'end' => self::ipv6RangeEnd($startBin, $prefixLen),
                    'version' => 6,
                ];
            }
        }

        return [
            'recordsDeclared' => $recordsDeclared,
            'recordsParsed' => $recordsParsed,
            'sourceDate' => $sourceDate,
            'ranges' => $ranges,
        ];
    }

    /**
     * Given a 16-byte IPv6 network start address and a CIDR prefix length,
     * returns the 16-byte address of the last host in that network (i.e.
     * every bit after the prefix set to 1).
     */
    private static function ipv6RangeEnd(string $startBin, int $prefixLen): string
    {
        $hostBits = 128 - $prefixLen;
        $end = $startBin;

        // Walk the address from the last byte backwards, OR-ing in 1 bits
        // for as much of each byte as still falls within the host part.
        for ($byte = 15; $byte >= 0 && $hostBits > 0; $byte--) {
            $bitsInByte = min(8, $hostBits);
            $mask = (1 << $bitsInByte) - 1; // e.g. 8 bits -> 0xFF, 3 bits -> 0x07
            $end[$byte] = chr(ord($end[$byte]) | $mask);
            $hostBits -= $bitsInByte;
        }

        return $end;
    }
}
