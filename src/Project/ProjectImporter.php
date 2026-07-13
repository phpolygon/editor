<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

use RuntimeException;

/**
 * Turns an ordinary PHPolygon game directory into an editor-openable project
 * by generating its {@see ProjectLoader::MANIFEST_FILE} manifest.
 *
 * The manifest is derived from the project's composer.json when present
 * (name + PSR-4 autoload roots) and otherwise from sensible defaults.
 * An existing manifest is never overwritten — importing such a folder just
 * loads the manifest already there.
 */
class ProjectImporter
{
    public function __construct(
        private readonly ProjectLoader $loader,
    ) {}

    /**
     * Ensure the given directory has an editor manifest, creating one when it
     * is absent. Returns the (possibly pre-existing) manifest and whether a
     * fresh one was written.
     *
     * @return array{manifest: ProjectManifest, created: bool}
     */
    public function import(string $dir): array
    {
        $dir = rtrim($dir, '/\\');

        if (! is_dir($dir)) {
            throw new RuntimeException("Directory not found: {$dir}");
        }

        $manifestPath = $dir.DIRECTORY_SEPARATOR.ProjectLoader::MANIFEST_FILE;
        if (is_file($manifestPath)) {
            return ['manifest' => $this->loader->load($dir), 'created' => false];
        }

        $manifest = $this->buildManifest($dir);
        $this->loader->save($manifest, $dir);

        // Make sure the scene/asset folders the manifest points at exist so
        // the scene list and asset browser have somewhere to look.
        $this->ensureDir($dir.DIRECTORY_SEPARATOR.$manifest->scenesPath);
        $this->ensureDir($dir.DIRECTORY_SEPARATOR.$manifest->assetsPath);

        return ['manifest' => $manifest, 'created' => true];
    }

    private function buildManifest(string $dir): ProjectManifest
    {
        $composer = $this->readComposer($dir);

        return new ProjectManifest(
            name: $this->deriveName($composer, $dir),
            version: '0.1.0',
            engineVersion: '*',
            scenesPath: 'src/Scene',
            assetsPath: 'assets',
            psr4Roots: $this->derivePsr4Roots($composer),
            entryScene: '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(string $dir): array
    {
        $path = $dir.DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function deriveName(array $composer, string $dir): string
    {
        $name = $composer['name'] ?? null;
        if (is_string($name) && $name !== '') {
            // Composer names are "vendor/package"; the package part reads nicer.
            $package = str_contains($name, '/') ? (string) substr(strrchr($name, '/'), 1) : $name;

            return $this->titleize($package);
        }

        return $this->titleize(basename($dir));
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return array<string, string>
     */
    private function derivePsr4Roots(array $composer): array
    {
        $autoload = $composer['autoload']['psr-4'] ?? null;
        if (! is_array($autoload)) {
            return [];
        }

        $roots = [];
        foreach ($autoload as $namespace => $path) {
            // Composer allows an array of paths per namespace; take the first.
            if (is_array($path)) {
                $path = $path[0] ?? null;
            }
            if (is_string($namespace) && is_string($path)) {
                $roots[$namespace] = rtrim($path, '/\\');
            }
        }

        return $roots;
    }

    private function titleize(string $value): string
    {
        $value = trim(str_replace(['-', '_'], ' ', $value));

        return $value === '' ? 'Untitled Project' : ucwords($value);
    }

    private function ensureDir(string $path): void
    {
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}
