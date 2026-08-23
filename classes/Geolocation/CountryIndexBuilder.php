<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

/**
 * Builds the compact, self-defined country-index file CountryLookup reads
 * from the ranges produced by RirStatsParser, and owns fetching the source
 * text over HTTP.
 *
 * Design goals (see docs/GEOLOCATION.md and the
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

    /**
     * A companion repository whose sole job is to run this same class
     * (RirStatsParser + CountryIndexBuilder, unchanged) once per cycle on a
     * well-resourced CI runner and publish the resulting index as a rolling
     * release asset - see docs/GEOLOCATION.md for the full
     * reasoning (this replaced a per-site daily/weekly raw-RIR download,
     * which was both a meaningful traffic cost on constrained hosting and
     * the reason build() needs a raised memory_limit in the first place).
     * fetchPrebuilt() below just downloads and validates this file - no
     * parsing, no `RirStatsParser` involvement, no elevated memory_limit
     * needed on the consuming site at all.
     */
    public const DEFAULT_PREBUILT_URL = 'https://github.com/chrisschm/page-insights-geo-db/releases/download/latest/geo-country-index.bin';

    /** Keep in sync with CountryLookup::HEADER_SIZE - both classes independently encode/decode against the format documented above. */
    private const HEADER_SIZE = 25;

    private const IPV4_MAX = 0xFFFFFFFF;

    public function __construct(private RirStatsParser $parser = new RirStatsParser())
    {
    }

    /**
     * Downloads an already-built index (produced by build() elsewhere, e.g.
     * a companion repo's CI job) and installs it directly - no parsing, no
     * sorting, no RirStatsParser involvement, and therefore no elevated
     * memory_limit requirement on this site's own process. This is the
     * default consumption path; build() remains available as the "trust
     * nobody, build it yourself from the raw RIR data locally" fallback
     * (see the geo_db_source_mode config field).
     *
     * Validates the downloaded bytes look like a genuine PIGC1 index
     * (correct magic + internally consistent entry counts) *before*
     * touching $outputPath, so a corrupt/truncated/wrong-content download
     * never clobbers a previously working index - an "Update now" click
     * that fails should leave the site exactly as good as it was before the
     * click, never worse.
     *
     * @return array{
     *     builtAt: ?int,
     *     sourceDate: ?string,
     *     sourceUrl: string,
     *     recordsParsed: null,
     *     ipv4Entries: int,
     *     ipv6Entries: int,
     *     fileSize: int,
     * }
     *
     * @throws \RuntimeException on download failure or if the downloaded
     *   content doesn't parse as a valid index.
     */
    public function fetchPrebuilt(string $outputPath, ?string $url = null): array
    {
        $url = $url ?: self::DEFAULT_PREBUILT_URL;

        $bytes = $this->fetch($url);
        $meta = self::parseHeader($bytes);

        if ($meta === null) {
            throw new \RuntimeException(
                "Downloaded file from '{$url}' doesn't look like a valid geo country index " .
                "(missing/corrupt header or truncated download) - left the existing index untouched."
            );
        }

        self::writeBytesAtomically($outputPath, $bytes);

        return [
            'builtAt' => $meta['builtAt'],
            'sourceDate' => $meta['sourceDate'],
            'sourceUrl' => $url,
            'recordsParsed' => null,
            'ipv4Entries' => $meta['ipv4Count'],
            'ipv6Entries' => $meta['ipv6Count'],
            'fileSize' => strlen($bytes),
        ];
    }

    /**
     * Reads and validates the PIGC1 header from raw index bytes (mirrors
     * CountryLookup::open()'s parsing, kept independent/duplicated
     * deliberately - see the HEADER_SIZE doc comment above). Also checks
     * that the declared entry counts actually account for the rest of the
     * byte string, which CountryLookup's own streaming reader has no reason
     * to check up front but a one-shot download validation very much does:
     * a download that got cut off mid-transfer would otherwise pass the
     * magic-byte check and only fail later, per-lookup, in a much more
     * confusing way.
     *
     * @return array{builtAt: int, sourceDate: ?string, ipv4Count: int, ipv6Count: int}|null
     */
    private static function parseHeader(string $bytes): ?array
    {
        if (strlen($bytes) < self::HEADER_SIZE || substr($bytes, 0, 5) !== self::FORMAT_MAGIC) {
            return null;
        }

        $builtAt = unpack('N', substr($bytes, 5, 4))[1];
        $sourceDate = substr($bytes, 9, 8);
        $ipv4Count = unpack('N', substr($bytes, 17, 4))[1];
        $ipv6Count = unpack('N', substr($bytes, 21, 4))[1];

        $expectedSize = self::HEADER_SIZE + $ipv4Count * 6 + $ipv6Count * 18;
        if (strlen($bytes) !== $expectedSize) {
            return null;
        }

        return [
            'builtAt' => $builtAt,
            'sourceDate' => $sourceDate === '00000000' ? null : $sourceDate,
            'ipv4Count' => $ipv4Count,
            'ipv6Count' => $ipv6Count,
        ];
    }

    /**
     * Minimal standalone atomic-write helper (temp file + rename) for
     * fetchPrebuilt(), which has nothing to build - it already has the
     * complete file contents in hand. Deliberately independent of
     * writeFile() below (which assembles the file incrementally from
     * entry arrays via an open file handle) rather than generalizing both
     * into one shared helper, to avoid touching writeFile()'s existing,
     * already-shipped read/write logic for this addition.
     */
    private static function writeBytesAtomically(string $outputPath, string $contents): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory '{$dir}' for the geo country index.");
        }

        $tmpPath = $outputPath . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpPath, $contents) === false) {
            throw new \RuntimeException("Unable to write '{$tmpPath}'.");
        }

        if (!@rename($tmpPath, $outputPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Unable to move the downloaded index into place at '{$outputPath}'.");
        }
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

        // The real source file is tens of MB of text; holding it plus the
        // parsed ranges array at once has been observed to exceed a stock
        // 128M memory_limit (fatal, uncatchable "Allowed memory size
        // exhausted" - php.net/manual/en/ini.core.php#ini.memory-limit).
        // This is a rare, explicit, admin-triggered action, not the page-
        // request path (see docs/GEOLOCATION.md), so
        // temporarily raising the limit for just this call is reasonable -
        // restored in finally() so it never leaks into the rest of the
        // request. ini_set() returns false (not a warning/exception) when
        // memory_limit is locked down (e.g. some shared-hosting php.ini
        // configs disable raising it) - silently no-op in that case rather
        // than fail; a host tight enough to block this will simply hit the
        // same memory error as before, no worse off than today.
        $previousMemoryLimit = ini_set('memory_limit', '512M');

        try {
            $text = $this->fetch($sourceUrl);

            return $this->buildFromText($text, $outputPath, $sourceUrl);
        } finally {
            if ($previousMemoryLimit !== false) {
                $this->restoreMemoryLimit($previousMemoryLimit);
            }
        }
    }

    /**
     * Restores memory_limit to its pre-build() value, but only if that's
     * actually safe.
     *
     * Lowering memory_limit below the memory already in use isn't a no-op
     * or a warning - PHP raises a hard, catchable \Error ("Failed to set
     * memory limit to X bytes (Current memory usage is Y bytes)",
     * Zend/zend_alloc.c) the moment ini_set() is called with such a value.
     * The arrays built while parsing/indexing the real, multi-MB RIR file
     * are still referenced by $parsed's return value at this point in the
     * finally block, so usage is routinely still well above a typical 128M
     * default even though the build itself already completed successfully -
     * observed in production as "Failed to set memory limit to 134217728
     * bytes (Current memory usage is ~300MB bytes)", which surfaced as a
     * false "Could not update the geo country database" failure even
     * though the index file had already been written.
     *
     * Restoring the limit is a courtesy for the rest of the request, not a
     * hard requirement - PHP resets every ini_set() change at the end of
     * the request/PHP-FPM worker cycle regardless - so it's safe to just
     * leave the raised limit in place for the remainder of this request
     * rather than risk crashing an otherwise-successful build.
     */
    private function restoreMemoryLimit(string $previousMemoryLimit): void
    {
        $limitBytes = self::parseMemoryLimit($previousMemoryLimit);
        if ($limitBytes !== null && memory_get_usage(true) >= $limitBytes) {
            return;
        }

        ini_set('memory_limit', $previousMemoryLimit);
    }

    /**
     * Parses a php.ini-style memory_limit value ("128M", "512K", "1G", a
     * bare byte count, or "-1" for unlimited) into a byte count.
     *
     * Returns null for "-1"/unlimited (never unsafe to "restore" to) and
     * for anything unparseable (fails safe: treated as "unknown", so the
     * caller falls through to attempting the restore as before).
     */
    private static function parseMemoryLimit(string $value): ?int
    {
        $value = trim($value);
        if ($value === '-1') {
            return null;
        }

        if (!preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches)) {
            return null;
        }

        $number = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
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
