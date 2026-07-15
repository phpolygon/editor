<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Geometry\MeshRegistry;
use RuntimeException;

class GetMeshCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : null;
        if ($id === null || $id === '') {
            throw new RuntimeException("Missing 'id' argument");
        }

        $mesh = MeshRegistry::get($id);
        if ($mesh !== null) {
            return ProjectAssetCache::meshToArray($id, $mesh, MeshRegistry::version($id));
        }

        // Fall back to the per-project snapshot captured at scene-load time.
        $cached = ProjectAssetCache::mesh($context->projectDir, $id);
        if ($cached !== null) {
            return $cached;
        }

        throw new RuntimeException("Unknown mesh: {$id}");
    }
}
