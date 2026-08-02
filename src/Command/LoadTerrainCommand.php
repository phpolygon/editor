<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Terrain\TerrainAssetStore;
use RuntimeException;

/**
 * Load a saved terrain asset back into the terrain editor.
 */
class LoadTerrainCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($name === '') {
            throw new RuntimeException("Missing 'name' argument");
        }

        $store = new TerrainAssetStore($context);

        return $store->load($store->sanitizeName($name))->toArray();
    }
}
