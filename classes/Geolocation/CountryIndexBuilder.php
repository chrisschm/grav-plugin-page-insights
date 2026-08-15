<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

/**
 * Builds the compact, self-defined country-index file CountryLookup reads
 * from the ranges produced by RirStatsParser, and owns fetching the source
 * text over HTTP.
 *
 * Design goals (see docs/ARCHITECTURE.md "Geolocation" section and the
 * 2026-08-15 session notes for the full reasoning):
 *  - No third-party BIN format to reverse-engineer/depend on - the format
 *    below is our own, small enough to fully document in this file.
 *  - No data ships in the plugin's git repo/release archive at all - this
 *    class is only ever invoked on-demand (admin "update now" action today,
 *    a Scheduler-friendly console command as a possible follow-up), never
 *    at install time and never on the page-request hot path.
 *  - Country-only. RIR delegated-stats records don't carry region/city in
 *    the first place, which matches the earlier finding that those fields
 *    were captured but never actually used anywhere in this plugin.
 *
 * On-disk format ("PIGC1", Page Insights Geo Country v1):
 *
 *   offset  size  field
 *   0       5     magic "PIGC1"
 *   5       4     builtAt (unix timestamp, uint32 big-endian)
 *   9       8     sourceDate (8 ASCII digits, YYYYMMDD, '00000000' if unknown)
 *   17      4     ipv4EntryCount (uint32 big-endian)
 *   21      4     ipv6EntryCount (uint32 big-endian)
 *   25      ipv4EntryCount * 6   IPv4 entries, sorted ascending by start
 *   ...     ipv6EntryCount * 18  IPv6 entries, sorted ascending by start
 *
 *   IPv4 entry (6 bytes): [4 bytes start, uint32 BE][2 bytes ISO-3166-1 cc]
 *   IPv6 entry (18 bytes): [16 bytes start, network order][2 bytes cc]
 *
 * Both entry lists are gapless: every possible address falls into exactly
 * one entry (unallocated/reserved ranges get an explicit UNKNOWN_CC entry
 * rather than being left out), so a lookup is always "find the entry with
 * the greatest start <= address" - see CountryLookup.
 */
class CountryIndexBuilder
{
    public const FORMAT_MAGIC = 'PIGC1';

    /**
     * ISO 3166-1 reserves "ZZ" for "unknown or unspecified country" - used
     * to fill gaps between allocated/assigned ranges (reserved, not-yet-
     * delegated, or otherwise out-of-scope address space).
     */
    public const UNKNOWN_CC = 'ZZ';

    public const DEFAULT_SOURCE_URL = 'https://ftp.ripe.net/pub/stats/ripencc/nro-stats/latest/nro-delegated-stats';

    private const IPV4_MAX = 0xFFFFFFFF;

    public function __construct(private RirStatsParser $parser = new RirStatsParser())
    {
    }

    /**
     * Full pipeline: download the source file, parse it, build both indexes,
     * and write the result to $outputPath (atomically, via a temp file + rename,
     * so a concurrent lookup never sees a half-written file).
     *
     * @return array{
     *     builtAt: int,
     *     sourceDate: ?string,
     *     sourceUrl: string,
     *     recordsParsed: int,
     *     ipv4Entries: int,
     *     ipv6Entries: int,
     *     fileSize: int,
     * }
     *
     * @throws \RuntimeException on download or write failure.
     */
    public function build(string $outputPath, ?string $sourceUrl = null): array
    {
        $sourceUrl = $sourceUrl ?: self::DEFAULT_SOURCE_URL;
        $text = $this->fetch($sourceUrl);

        return $this->buildFromText($text, $outputPath, $sourceUrl);
    }

    /**
     * Same as build(), but takes the source text directly - split out so
     * tests can exercise the parsing/encoding pipeline against a small fixture
     * without any network access (see the "geo-db-parser" verification pass).
     */
    public function buildFromText(string $text, string $outputPath, string $sourceUrl): array
    {
        $parsed = $this->parser->parse($text);

        $ipv4Ranges = [];
        $ipv6Ranges = [];
        foreach ($parsed['ranges'] as $range) {
            if ($range['version'] === 4) {
                $ipv4Ranges[] = $range;
            } else {
                $ipv6Ranges[] = $range;
            }
        }

        $ipv4Entries = $this->buildIpv4Entries($ipv4Ranges);
        $ipv6Entries = $this->buildIpv6Entries($ipv6Ranges);

        $builtAt = time();
        $this->writeFile($outputPath, $builtAt, $parsed['sourceDate'], $ipv4Entries, $ipv6Entries);

        return [
            'builtAt' => $builtAt,
            'sourceDate' => $parsed['sourceDate'],
            'sourceUrl' => $sourceUrl,
            'recordsParsed' => $parsed['recordsParsed'],
            'ipv4Entries' => count($ipv4Entries),
            'ipv6Entries' => count($ipv6Entries),
            'fileSize' => filesize($outputPath) ?: 0,
        ];
    }

    /**
     * @param list<array{cc: string, start: int, end: int}> $ranges
     * @return list<array{start: int, cc: string}>
     */
    private function buildIpv4Entries(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $entries = [];
        $cursor = 0;
        $lastCc = null;

        foreach ($ranges as $range) {
            if ($range['end'] < $cursor) {
                continue; // Fully covered by a previously-written range already.
            }

            if ($range['start'] > $cursor) {
                if ($lastCc !== self::UNKNOWN_CC) {
                    $entries[] = ['start' => $cursor, 'cc' => self::UNKNOWN_CC];
                    $lastCc = self::UNKNOWN_CC;
                }
                $cursor = $range['start'];
            }

            if ($range['cc'] !== $lastCc) {
                $entries[] = ['start' => $cursor, 'cc' => $range['cc']];
                $lastCc = $range['cc'];
            }

            $cursor = $range['end'] + 1;
        }

        if ($cursor <= self::IPV4_MAX && $lastCc !== self::UNKNOWN_CC) {
            $entries[] = ['start' => $cursor, 'cc' => self::UNKNOWN_CC];
        }

        return $entries;
    }

    /**
     * @param list<array{cc: string, start: string, end: string}> $ranges
     * @return list<array{start: string, cc: string}>
     */
    private function buildIpv6Entries(array $ranges): array
    {
        // Explicit strcmp() rather than PHP's <=>/</> operators: those fall
        // back to numeric comparison for operands that happen to look like
        // numeric strings, which binary 16-byte address data occasionally
        // could. strcmp() always does the plain byte-wise comparison these
        // fixed-length big-endian values need.
        usort($ranges, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $entries = [];
        $cursor = str_repeat("\x00", 16);
        $lastCc = null;
        $overflowed = false;

        foreach ($ranges as $range) {
            if (!$overflowed && strcmp($range['end'], $cursor) < 0) {
                continue;
            }

            if (!$overflowed && strcmp($range['start'], $cursor) > 0) {
                if ($lastCc !== self::UNKNOWN_CC) {
                    $entries[] = ['start' => $cursor, 'cc' => self::UNKNOWN_CC];
                    $lastCc = self::UNKNOWN_CC;
                }
                $cursor = $range['start'];
            }

            if ($range['cc'] !== $lastCc) {
                $entries[] = ['start' => $cursor, 'cc' => $range['cc']];
                $lastCc = $range['cc'];
            }

            $next = self::ipv6Increment($range['end']);
            if ($next === null) {
                // $range['end'] was the very last possible IPv6 address
                // (all-0xFF) - nothing can come after it, stop.
                $overflowed = true;
                break;
            }
            $cursor = $next;
        }

        if (!$overflowed && $lastCc !== self::UNKNOWN_CC) {
            $entries[] = ['start' => $cursor, 'cc' => self::UNKNOWN_CC];
        }

        return $entries;
    }

    /**
     * Returns the 16-byte address one past $bin, or null if $bin was the
     * highest possible address (all bytes 0xFF - overflow).
     */
    private static function ipv6Increment(string $bin): ?string
    {
        for ($i = 15; $i >= 0; $i--) {
            $byte = ord($bin[$i]);
            if ($byte === 0xFF) {
                $bin[$i] = "\x00";
                continue;
            }
            $bin[$i] = chr($byte + 1);

            return $bin;
        }

        return null;
    }

    /**
     * @param list<array{start: int, cc: string}> $ipv4Entries
     * @param list<array{start: string, cc: string}> $ipv6Entries
     */
    private function writeFile(string $outputPath, int $builtAt, ?string $sourceDate, array $ipv4Entries, array $ipv6Entries): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory '{$dir}' for the geo country index.");
        }

        $tmpPath = $outputPath . '.tmp-' . bin2hex(random_bytes(4));
        $fp = @fopen($tmpPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Unable to open '{$tmpPath}' for writing.");
        }

        try {
            fwrite($fp, self::FORMAT_MAGIC);
            fwrite($fp, pack('N', $builtAt));
            fwrite($fp, str_pad((string) ($sourceDate ?? '00000000'), 8, '0', STR_PAD_LEFT));
            fwrite($fp, pack('N', count($ipv4Entries)));
            fwrite($fp, pack('N', count($ipv6Entries)));

            foreach ($ipv4Entries as $entry) {
                fwrite($fp, pack('N', $entry['start']));
                fwrite($fp, self::normalizeCc($entry['cc']));
            }
            foreach ($ipv6Entries as $entry) {
                fwrite($fp, $entry['start']);
                fwrite($fp, self::normalizeCc($entry['cc']));
            }
        } finally {
            fclose($fp);
        }

        if (!@rename($tmpPath, $outputPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Unable to move the built index into place at '{$outputPath}'.");
        }
    }

    private static function normalizeCc(string $cc): string
    {
        $cc = strtoupper(substr($cc, 0, 2));

        return str_pad($cc, 2, '?');
    }

    /**
     * Downloads $url as plain text. Prefers curl (proper timeouts, TLS,
     * redirect handling) and falls back to a stream-context file_get_contents
     * if the curl extension isn't available - both are common enough on
     * shared PHP hosting that requiring either outright felt unnecessary.
     */
    private function fetch(string $url): string
    {
        if (\extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'grav-plugin-page-insights (+https://codeberg.org/chschmidt/grav-plugin-page-insights)',
            ]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false) {
                throw new \RuntimeException("Download of '{$url}' failed: {$error}");
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException("Download of '{$url}' failed with HTTP status {$status}.");
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 120, 'follow_location' => 1],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException("Download of '{$url}' failed (curl extension not available, file_get_contents fallback also failed).");
        }

        return $body;
    }
}
