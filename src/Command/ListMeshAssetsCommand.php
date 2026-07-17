<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;

/**
 * List saved mesh assets under `assets/meshes/` (the graphs written by
 * {@see SaveMeshCommand}), sorted by name.
 */
class ListMeshAssetsCommand implements CommandInterface
{
    private const MESHES_SUBDIR = 'meshes';

    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $dir = Path::join($context->getAssetsDir(), self::MESHES_SUBDIR);
        $meshes = [];

        if (is_dir($dir)) {
            foreach (glob(Path::join($dir, '*.mesh.json')) ?: [] as $file) {
                $base = basename($file, '.mesh.json');
                $meshes[] = ['name' => $base, 'path' => self::MESHES_SUBDIR.'/'.$base.'.mesh.json'];
            }
            usort($meshes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }

        return ['meshes' => $meshes];
    }
}
