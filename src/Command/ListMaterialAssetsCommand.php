<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;

/**
 * List saved material assets under `assets/materials/` (the files written by
 * {@see SaveMaterialCommand}), sorted by id.
 */
class ListMaterialAssetsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $dir = Path::join($context->getAssetsDir(), SaveMaterialCommand::MATERIALS_SUBDIR);
        $materials = [];

        if (is_dir($dir)) {
            foreach (glob(Path::join($dir, '*.material.json')) ?: [] as $file) {
                $id = basename($file, '.material.json');
                $materials[] = ['id' => $id, 'path' => SaveMaterialCommand::MATERIALS_SUBDIR.'/'.$id.'.material.json'];
            }
            usort($materials, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        }

        return ['materials' => $materials];
    }
}
