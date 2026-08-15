<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

/**
 * Reads the compact country-index file written by CountryIndexBuilder and
 * answers country-code lookups via binary search directly against the file
 * (fseek/fread per probe, nothing loaded into memory up front) - this class
 * is instantiated fresh on every single page hit (see
 * PageInsightsPlugin::collectPageData()), so construction and lookup both
 * need to stay cheap even before the index has ever been built.
 *
 * Never throws for "no index yet" / "corrupt index" / "IP not found" - all
 * of those simply resolve to isAvailable() === false or lookup() === null,
 * which Geolocation.php turns into the existing 'unknown' fallback. A
 * missing or stale geo database must never break page collection.
 */
class CountryLookup
{
    private const HEADER_SIZE = 25; // 5 (magic) + 4 (builtAt) + 8 (sourceDate) + 4 + 4 (counts)
    private const IPV4_ENTRY_SIZE = 6;  // 4 (start) + 2 (cc)
    private const IPV6_ENTRY_SIZE = 18; // 16 (start) + 2 (cc)

    /** @var resource|false */
    private $handle = false;

    private bool $available = false;
    private int $ipv4Count = 0;
    private int $ipv6Count = 0;
    private int $ipv4Base = 0;
    private int $ipv6Base = 0;
    private ?int $builtAt = null;
    private ?string $sourceDate = null;

    public function __construct(private string $path)
    {
        $this->open();
    }

    public function __destruct()
    {
        if ($this->handle !== false) {
            fclose($this->handle);
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Unix timestamp of when the currently loaded index was built, or null
     * if no (valid) index is loaded.
     */
    public function builtAt(): ?int
    {
        return $this->builtAt;
    }

    /**
     * The upstream RIR stats snapshot's own "as of" date (YYYYMMDD), or null
     * if unknown. Distinct from builtAt() - a site could rebuild against an
     * upstream file that hasn't itself changed.
     */
    public function sourceDate(): ?string
    {
        return $this->sourceDate;
    }

    public function ipv4EntryCount(): int
    {
        return $this->ipv4Count;
    }

    public function ipv6EntryCount(): int
    {
        return $this->ipv6Count;
    }

    /**
     * Returns the ISO 3166-1 alpha-2 country code for $ip, or null if no
     * index is loaded, the address is invalid, or it maps to an
     * unallocated/reserved range (CountryIndexBuilder::UNKNOWN_CC).
     */
    public function lookup(string $ip): ?string
    {
        if (!$this->available) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $cc = $this->lookupIpv4($ip);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $cc = $this->lookupIpv6($ip);
        } else {
            return null;
        }

        return $cc === CountryIndexBuilder::UNKNOWN_CC ? null : $cc;
    }

    private function open(): void
    {
        if (!is_file($this->path)) {
            return;
        }

        $fp = @fopen($this->path, 'rb');
        if ($fp === false) {
            return;
        }

        $header = fread($fp, self::HEADER_SIZE);
        if ($header === false || strlen($header) !== self::HEADER_SIZE
            || substr($header, 0, 5) !== CountryIndexBuilder::FORMAT_MAGIC) {
            fclose($fp);

            return;
        }

        // Offsets per the format table in CountryIndexBuilder's class doc
        // comment: magic(5) + builtAt(4) + sourceDate(8) + ipv4Count(4) + ipv6Count(4).
        $builtAt = unpack('N', substr($header, 5, 4))[1];
        $sourceDate = substr($header, 9, 8);
        $ipv4Count = unpack('N', substr($header, 17, 4))[1];
        $ipv6Count = unpack('N', substr($header, 21, 4))[1];

        $this->handle = $fp;
        $this->builtAt = $builtAt;
        $this->sourceDate = $sourceDate === '00000000' ? null : $sourceDate;
        $this->ipv4Count = $ipv4Count;
        $this->ipv6Count = $ipv6Count;
        $this->ipv4Base = self::HEADER_SIZE;
        $this->ipv6Base = self::HEADER_SIZE + $ipv4Count * self::IPV4_ENTRY_SIZE;
        $this->available = true;
    }

    private function lookupIpv4(string $ip): ?string
    {
        if ($this->ipv4Count === 0) {
            return null;
        }

        $target = Ip::toNumber($ip);
        $lo = 0;
        $hi = $this->ipv4Count - 1;
        $result = null;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $entry = $this->readEntry($this->ipv4Base, $mid, self::IPV4_ENTRY_SIZE);
            if ($entry === null) {
                return $result;
            }
            $start = unpack('N', substr($entry, 0, 4))[1];

            if ($start <= $target) {
                $result = substr($entry, 4, 2);
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $result;
    }

    private function lookupIpv6(string $ip): ?string
    {
        if ($this->ipv6Count === 0) {
            return null;
        }

        $target = @inet_pton($ip);
        if ($target === false || strlen($target) !== 16) {
            return null;
        }

        $lo = 0;
        $hi = $this->ipv6Count - 1;
        $result = null;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $entry = $this->readEntry($this->ipv6Base, $mid, self::IPV6_ENTRY_SIZE);
            if ($entry === null) {
                return $result;
            }
            $start = substr($entry, 0, 16);

            // strcmp(), not <=/</> - see CountryIndexBuilder::buildIpv6Entries().
            if (strcmp($start, $target) <= 0) {
                $result = substr($entry, 16, 2);
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $result;
    }

    private function readEntry(int $base, int $index, int $entrySize): ?string
    {
        if (fseek($this->handle, $base + $index * $entrySize) !== 0) {
            return null;
        }
        $raw = fread($this->handle, $entrySize);

        return $raw !== false && strlen($raw) === $entrySize ? $raw : null;
    }
}
