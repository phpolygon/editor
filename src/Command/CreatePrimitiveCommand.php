<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use RuntimeException;

class CreatePrimitiveCommand implements CommandInterface
{
    private const DEFAULT_MATERIAL_ID = 'editor_default_material';

    private const PRIMITIVES = [
        'box' => 'editor_primitive_box',
        'sphere' => 'editor_primitive_sphere',
        'cylinder' => 'editor_primitive_cylinder',
        'plane' => 'editor_primitive_plane',
    ];

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $type = is_string($this->args['type'] ?? null) ? $this->args['type'] : '';
        if (! isset(self::PRIMITIVES[$type])) {
            throw new RuntimeException("Unknown primitive type: {$type}");
        }

        $meshId = self::PRIMITIVES[$type];
        $this->ensureMesh($meshId, $type);
        $this->ensureDefaultMaterial();

        $baseName = is_string($this->args['name'] ?? null) && $this->args['name'] !== ''
            ? $this->args['name']
            : ucfirst($type);
        $parent = is_string($this->args['parent'] ?? null) ? $this->args['parent'] : null;

        $name = $this->uniqueName($doc, $baseName);

        $doc->addEntity($name, $parent);

        $doc->addComponent($name, 'PHPolygon\\Component\\Transform3D', [
            'position' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            'rotation' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'w' => 1.0],
            'scale' => ['x' => 1.0, 'y' => 1.0, 'z' => 1.0],
        ]);

        $doc->addComponent($name, 'PHPolygon\\Component\\MeshRenderer', [
            'meshId' => $meshId,
            'materialId' => self::DEFAULT_MATERIAL_ID,
            'castShadows' => true,
            'visible' => true,
        ]);

        return [
            'created' => $name,
            'parent' => $parent,
            'meshId' => $meshId,
            'materialId' => self::DEFAULT_MATERIAL_ID,
        ];
    }

    private function ensureMesh(string $meshId, string $type): void
    {
        if (MeshRegistry::has($meshId)) {
            return;
        }
        MeshRegistry::register($meshId, $this->generatePrimitive($type));
    }

    private function generatePrimitive(string $type): MeshData
    {
        return match ($type) {
            'box' => BoxMesh::generate(1.0, 1.0, 1.0),
            'sphere' => SphereMesh::generate(0.5, 12, 16),
            'cylinder' => CylinderMesh::generate(0.5, 1.0, 16),
            'plane' => PlaneMesh::generate(1.0, 1.0),
            default => throw new RuntimeException("Unknown primitive type: {$type}"),
        };
    }

    private function ensureDefaultMaterial(): void
    {
        if (MaterialRegistry::has(self::DEFAULT_MATERIAL_ID)) {
            return;
        }
        MaterialRegistry::register(self::DEFAULT_MATERIAL_ID, new Material(
            albedo: new Color(0.7, 0.7, 0.75),
            roughness: 0.55,
            metallic: 0.0,
        ));
    }

    private function uniqueName(SceneDocument $doc, string $base): string
    {
        if ($doc->getEntity($base) === null) {
            return $base;
        }
        $i = 2;
        while ($doc->getEntity($base.'_'.$i) !== null) {
            $i++;
        }

        return $base.'_'.$i;
    }
}
