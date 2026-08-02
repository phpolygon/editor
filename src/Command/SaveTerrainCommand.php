<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Terrain\TerrainAssetStore;
use PHPolygon\Editor\Terrain\TerrainDocument;
use RuntimeException;

/**
 * Persist a terrain — heightmap, texture layers and scatter sets — as
 * `assets/terrains/<name>.terrain.json`.
 *
 * The whole document is sent in one call rather than the editor streaming
 * individual brush strokes: sculpting runs client-side at frame rate, and a
 * round trip per stroke would be both unusably slow and pointless, since only
 * the settled result is worth persisting.
 */
class SaveTerrainCommand implements CommandInterface
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
        $sanitized = $store->sanitizeName($name);

        // Parsing before writing means a malformed payload is rejected instead
        // of overwriting a good asset with something that will not load back.
        $document = TerrainDocument::fromArray(
            ['name' => $sanitized] + $this->args,
            $sanitized,
        );

        $path = $store->save($sanitized, $document);

        return [
            'saved' => true,
            'name' => $sanitized,
            'path' => $path,
            'relativePath' => $store->relativePath($sanitized),
        ];
    }
}
