<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Gltf;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Rendering\Material;

/**
 * Outcome of {@see GlbParser::parse()}: the world-space geometry of a whole
 * glTF-binary, batched into one merged mesh per material, plus every material
 * translated to the engine's {@see Material} value object.
 *
 * Each batch is already baked into world space, so a consuming importer turns
 * it into ONE entity carrying an identity Transform3D + a MeshRenderer that
 * references the batch mesh id and its material id (see {@see $meshMaterials}).
 */
final class GlbImportResult
{
    /**
     * @param array<string, MeshData> $meshes        batch mesh id => merged world-space geometry
     * @param array<string, Material> $materials      material id => engine material (only used ones)
     * @param array<string, string>   $meshMaterials  batch mesh id => material id it references
     */
    public function __construct(
        public readonly array $meshes = [],
        public readonly array $materials = [],
        public readonly array $meshMaterials = [],
    ) {}
}
