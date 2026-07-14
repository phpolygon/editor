<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Component\ProceduralMesh;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\ProceduralMeshEvaluator;

/**
 * Evaluate a ProceduralMesh node graph and return the resulting mesh as the
 * same DTO {@see GetMeshCommand} produces, so the editor's Three.js viewport
 * can preview it live while the node editor is being edited.
 *
 * When a non-empty `meshId` is given the mesh is also (re-)registered in the
 * MeshRegistry under that id (bumping its version), so anything referencing it
 * by id — a MeshRenderer in the scene — picks up the new geometry too.
 *
 * The caller is expected to send a graph that already validates (the node
 * editor validates client-side); an invalid graph surfaces as the evaluator's
 * RuntimeException.
 */
class EvaluateProceduralMeshCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $graph = new ProceduralMesh();
        $graph->nodes = is_array($this->args['nodes'] ?? null) ? array_values($this->args['nodes']) : [];
        $graph->output = is_string($this->args['output'] ?? null) ? $this->args['output'] : '';
        $graph->meshId = is_string($this->args['meshId'] ?? null) ? $this->args['meshId'] : '';

        $mesh = (new ProceduralMeshEvaluator())->evaluate($graph);

        $version = 0;
        if ($graph->meshId !== '') {
            MeshRegistry::register($graph->meshId, $mesh);
            $version = MeshRegistry::version($graph->meshId);
        }

        return [
            'id' => $graph->meshId,
            'version' => $version,
            'vertices' => $mesh->vertices,
            'normals' => $mesh->normals,
            'uvs' => $mesh->uvs,
            'indices' => $mesh->indices,
            'vertexCount' => $mesh->vertexCount(),
            'triangleCount' => $mesh->triangleCount(),
        ];
    }
}
