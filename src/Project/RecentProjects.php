<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

/**
 * Persists the list of recently opened projects so the editor can offer
 * a "Recent Projects" menu across restarts. Backed by a single JSON file
 * whose location is injected (framework-agnostic).
 *
 * @phpstan-type RecentEntry array{dir: string, name: string, lastOpened: int}
 */
class RecentProjects
{
    public function __construct(
        private readonly string $storageFile,
        private readonly int $max = 10,
    ) {}

    /**
     * All remembered projects, most-recently-opened first.
     *
     * @return list<RecentEntry>
     */
    public function all(): array
    {
        if (! is_file($this->storageFile)) {
            return [];
        }

        $content = file_get_contents($this->storageFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $entry) {
            if (! is_array($entry) || ! isset($entry['dir']) || ! is_string($entry['dir'])) {
                continue;
            }

            $entries[] = [
                'dir' => $entry['dir'],
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : basename($entry['dir']),
                'lastOpened' => is_int($entry['lastOpened'] ?? null) ? $entry['lastOpened'] : 0,
            ];
        }

        return $entries;
    }

    /**
     * Record a project as most-recently opened, de-duplicating by directory
     * and capping the list at {@see self::$max} entries.
     */
    public function add(string $dir, string $name, ?int $timestamp = null): void
    {
        $key = $this->normalize($dir);

        $entries = array_values(array_filter(
            $this->all(),
            fn (array $e): bool => $this->normalize($e['dir']) !== $key,
        ));

        array_unshift($entries, [
            'dir' => rtrim($dir, '/\\'),
            'name' => $name,
            'lastOpened' => $timestamp ?? time(),
        ]);

        $this->save(array_slice($entries, 0, $this->max));
    }

    /**
     * Forget a single project (e.g. when the user removes it from the list).
     */
    public function remove(string $dir): void
    {
        $key = $this->normalize($dir);

        $this->save(array_values(array_filter(
            $this->all(),
            fn (array $e): bool => $this->normalize($e['dir']) !== $key,
        )));
    }

    /**
     * Drop entries whose project manifest no longer exists on disk and
     * return the surviving list. Keeps the menu free of dead paths.
     *
     * @return list<RecentEntry>
     */
    public function prune(): array
    {
        $valid = array_values(array_filter(
            $this->all(),
            fn (array $e): bool => is_file(
                rtrim($e['dir'], '/\\').DIRECTORY_SEPARATOR.ProjectLoader::MANIFEST_FILE,
            ),
        ));

        $this->save($valid);

        return $valid;
    }

    private function normalize(string $dir): string
    {
        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    /**
     * @param  list<RecentEntry>  $entries
     */
    private function save(array $entries): void
    {
        $dir = dirname($this->storageFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->storageFile,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
