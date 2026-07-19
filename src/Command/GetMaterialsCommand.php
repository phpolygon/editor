<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Return EVERY material (live registry + per-project cache snapshot) in one
 * response, so the viewport can preload all materials with a single request
 * instead of one get_material per unique material. See {@see GetMeshesCommand}.
 */
class GetMaterialsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{materials: list<array<string, mixed>>} */
    public function execute(EditorContext $context): array
    {
        $byId = [];
        foreach (ProjectAssetCache::allMaterials($context->projectDir) as $material) {
            $id = $material['id'] ?? null;
            if (is_string($id)) {
                $byId[$id] = $material;
            }
        }

        foreach (MaterialRegistry::ids() as $id) {
            $material = MaterialRegistry::get($id);
            if ($material instanceof Material) {
                $byId[$id] = ProjectAssetCache::materialToArray($id, $material);
            }
        }

        return ['materials' => array_values($byId)];
    }
}
