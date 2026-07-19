<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Geometry\MeshRegistry;

/**
 * Return EVERY mesh (live registry + per-project cache snapshot) in one response,
 * so the viewport can preload all geometry with a single request instead of one
 * get_mesh per unique mesh. The dev server is single-threaded, so N per-mesh
 * round-trips serialise into seconds of latency for a big scene; this collapses
 * them to one.
 */
class GetMeshesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{meshes: list<array<string, mixed>>} */
    public function execute(EditorContext $context): array
    {
        $byId = [];
        foreach (ProjectAssetCache::allMeshes($context->projectDir) as $mesh) {
            $id = $mesh['id'] ?? null;
            if (is_string($id)) {
                $byId[$id] = $mesh;
            }
        }

        // A scene just built in-process wins over the on-disk snapshot.
        foreach (MeshRegistry::ids() as $id) {
            $mesh = MeshRegistry::get($id);
            if ($mesh !== null) {
                $byId[$id] = ProjectAssetCache::meshToArray($id, $mesh, MeshRegistry::version($id));
            }
        }

        return ['meshes' => array_values($byId)];
    }
}
