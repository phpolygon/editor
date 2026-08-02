<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Terrain;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Reads and writes `.terrain.json` assets under `assets/terrains/`.
 *
 * Every terrain command funnels its filesystem access through here so name
 * sanitisation — which is what keeps a terrain name from escaping the project
 * directory — happens in exactly one place rather than being re-implemented
 * per command.
 */
final class TerrainAssetStore
{
    public const SUBDIR = 'terrains';

    private const EXTENSION = '.terrain.json';

    public function __construct(private readonly EditorContext $context) {}

    /** Absolute path of the terrains directory (not created until a write). */
    public function directory(): string
    {
        return Path::join($this->context->getAssetsDir(), self::SUBDIR);
    }

    /**
     * Reduce a user-supplied name to a safe filename stem.
     *
     * Path separators and dots collapse to underscores, so no input can
     * traverse out of the terrains directory.
     */
    public function sanitizeName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException("Invalid terrain name: {$name}");
        }

        return $sanitized;
    }

    /** Project-relative, portable path used in command responses. */
    public function relativePath(string $sanitizedName): string
    {
        return self::SUBDIR.'/'.$sanitizedName.self::EXTENSION;
    }

    public function exists(string $sanitizedName): bool
    {
        return is_file($this->absolutePath($sanitizedName));
    }

    public function absolutePath(string $sanitizedName): string
    {
        return Path::join($this->directory(), $sanitizedName.self::EXTENSION);
    }

    /** @return list<array{name: string, path: string}> Sorted by name. */
    public function list(): array
    {
        $dir = $this->directory();
        if (! is_dir($dir)) {
            return [];
        }

        $terrains = [];
        foreach (glob(Path::join($dir, '*'.self::EXTENSION)) ?: [] as $file) {
            $base = basename($file, self::EXTENSION);
            $terrains[] = ['name' => $base, 'path' => $this->relativePath($base)];
        }
        usort($terrains, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $terrains;
    }

    public function load(string $sanitizedName): TerrainDocument
    {
        $file = $this->absolutePath($sanitizedName);
        if (! is_file($file)) {
            throw new RuntimeException("Terrain not found: {$sanitizedName}");
        }

        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid terrain JSON: {$sanitizedName}");
        }

        return TerrainDocument::fromArray($data, $sanitizedName);
    }

    /** @return string Absolute path written to. */
    public function save(string $sanitizedName, TerrainDocument $document): string
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create terrains directory: {$dir}");
        }

        $file = $this->absolutePath($sanitizedName);

        // Terrain payloads are large base64 blobs; pretty-printing them buys
        // nothing and roughly doubles the file size, so only the structure is
        // formatted for readability in diffs.
        $json = json_encode($document->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json) === false) {
            throw new RuntimeException("Failed to write terrain file: {$file}");
        }

        return $file;
    }

    public function delete(string $sanitizedName): void
    {
        $file = $this->absolutePath($sanitizedName);
        if (! is_file($file)) {
            throw new RuntimeException("Terrain not found: {$sanitizedName}");
        }

        if (! unlink($file)) {
            throw new RuntimeException("Failed to delete terrain file: {$file}");
        }
    }
}
