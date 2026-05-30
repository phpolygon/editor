<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
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
        if ($mesh === null) {
            throw new RuntimeException("Unknown mesh: {$id}");
        }

        return [
            'id' => $id,
            'version' => MeshRegistry::version($id),
            'vertices' => $mesh->vertices,
            'normals' => $mesh->normals,
            'uvs' => $mesh->uvs,
            'indices' => $mesh->indices,
            'vertexCount' => $mesh->vertexCount(),
            'triangleCount' => $mesh->triangleCount(),
        ];
    }
}
