<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Rendering\MaterialRegistry;
use RuntimeException;

class GetMaterialCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : null;
        if ($id === null || $id === '') {
            throw new RuntimeException("Missing 'id' argument");
        }

        $material = MaterialRegistry::get($id);
        if ($material !== null) {
            return ProjectAssetCache::materialToArray($id, $material);
        }

        // Fall back to the per-project snapshot captured at scene-load time —
        // materials built imperatively in a scene's PHP don't survive into this
        // separate request.
        $cached = ProjectAssetCache::material($context->projectDir, $id);
        if ($cached !== null) {
            return $cached;
        }

        throw new RuntimeException("Unknown material: {$id}");
    }
}
