<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Terrain\TerrainAssetStore;

/**
 * List saved terrain assets under `assets/terrains/`, sorted by name.
 */
class ListTerrainAssetsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        return ['terrains' => (new TerrainAssetStore($context))->list()];
    }
}
