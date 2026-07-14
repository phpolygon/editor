<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

/**
 * List the reusable prefabs saved under `assets/prefabs/*.prefab.json`.
 *
 * Returns each prefab's display name (from the file's `name`, falling back to
 * the filename) and its path relative to the assets directory — the same path
 * {@see SpawnPrefabCommand} expects, so a prefab picker can list then spawn
 * without any extra bookkeeping.
 */
class ListPrefabsCommand implements CommandInterface
{
    private const PREFABS_SUBDIR = 'prefabs';

    private const SUFFIX = '.prefab.json';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{prefabs: list<array{name: string, path: string}>} */
    public function execute(EditorContext $context): array
    {
        $dir = $context->getAssetsDir().DIRECTORY_SEPARATOR.self::PREFABS_SUBDIR;
        if (! is_dir($dir)) {
            return ['prefabs' => []];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*'.self::SUFFIX) ?: [];

        $prefabs = [];
        foreach ($files as $file) {
            $base = basename($file);
            $name = substr($base, 0, -strlen(self::SUFFIX));

            $raw = file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['name']) && is_string($decoded['name']) && $decoded['name'] !== '') {
                    $name = $decoded['name'];
                }
            }

            $prefabs[] = [
                'name' => $name,
                'path' => self::PREFABS_SUBDIR.'/'.$base,
            ];
        }

        usort($prefabs, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['prefabs' => $prefabs];
    }
}
