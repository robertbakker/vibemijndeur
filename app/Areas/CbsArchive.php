<?php

declare(strict_types=1);

namespace App\Areas;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Resolves the CBS gebiedsindelingen GeoPackage for a given year from one of:
 * a direct `.gpkg` path, a `.zip` archive path, or — with {@see download()} — the
 * ~591 MB archive fetched from CBS. Returns the absolute path to the `.gpkg`.
 */
final class CbsArchive
{
    public function __construct(private readonly string $workDir) {}

    /**
     * @throws RuntimeException when the source cannot be resolved to a year's gpkg
     */
    public function geoPackageFor(string $source, int $year): string
    {
        if (str_ends_with(strtolower($source), '.gpkg')) {
            return $this->ensureExists($source);
        }

        if (str_ends_with(strtolower($source), '.zip')) {
            return $this->extractYear($this->ensureExists($source), $year);
        }

        throw new RuntimeException("Unsupported source: {$source} (expected a .gpkg or .zip).");
    }

    /**
     * Download the full CBS archive and return the requested year's gpkg.
     */
    public function download(string $url, int $year): string
    {
        $zip = $this->workDir.'/cbsgebiedsindelingen.zip';
        $response = Http::timeout(600)->sink($zip)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Failed to download CBS archive from {$url}.");
        }

        return $this->extractYear($zip, $year);
    }

    private function extractYear(string $zipPath, int $year): string
    {
        $entry = "cbsgebiedsindelingen{$year}.gpkg";
        $target = $this->workDir.'/'.$entry;

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open archive: {$zipPath}.");
        }

        if ($zip->locateName($entry) === false) {
            $zip->close();
            throw new RuntimeException("Year {$year} not found in archive (expected {$entry}).");
        }

        copy("zip://{$zipPath}#{$entry}", $target);
        $zip->close();

        return $target;
    }

    private function ensureExists(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}.");
        }

        return $path;
    }
}
