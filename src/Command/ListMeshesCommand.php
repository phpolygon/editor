<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Geometry\MeshRegistry;

class ListMeshesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $registryIds = MeshRegistry::ids();
        $ids = array_values(array_unique(array_merge(
            $registryIds,
            ProjectAssetCache::meshIds($context->projectDir),
        )));
        sort($ids);

        $meshes = [];
        foreach ($ids as $id) {
            $meshes[] = [
                'id' => $id,
                'version' => MeshRegistry::version($id),
                'pending' => in_array($id, MeshRegistry::pendingIds(), true),
            ];
        }

        return ['meshes' => $meshes];
    }
}
