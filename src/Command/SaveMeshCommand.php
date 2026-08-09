<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist a procedural-mesh node graph as a reusable mesh asset under
 * `assets/meshes/<name>.mesh.json`, independent of any entity. The stored
 * shape ({name, nodes, output}) round-trips back into the mesh editor and can
 * later be attached to an entity's ProceduralMesh component.
 */
class SaveMeshCommand implements CommandInterface
{
    private const MESHES_SUBDIR = 'meshes';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($name === '') {
            throw new RuntimeException("Missing 'name' argument");
        }

        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException("Invalid mesh name: {$name}");
        }

        // A mesh asset is either a procedural graph ({nodes, output}) or baked
        // raw geometry ({raw: {vertices, normals, uvs, indices}}) — the latter
        // for vertex-edited or imported meshes that have no generating graph.
        $raw = is_array($this->args['raw'] ?? null) ? $this->args['raw'] : null;

        if ($raw !== null) {
            $payload = ['name' => $sanitized, 'raw' => $raw];
        } else {
            $nodes = is_array($this->args['nodes'] ?? null) ? $this->args['nodes'] : [];
            $output = is_string($this->args['output'] ?? null) ? $this->args['output'] : '';
            if ($nodes === [] || $output === '') {
                throw new RuntimeException('Mesh graph must have nodes and an output');
            }
            $payload = ['name' => $sanitized, 'nodes' => $nodes, 'output' => $output];
        }

        $dir = Path::join($context->getAssetsDir(), self::MESHES_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create meshes directory: {$dir}");
        }

        $filePath = Path::join($dir, $sanitized.'.mesh.json');
        $relativePath = self::MESHES_SUBDIR.'/'.$sanitized.'.mesh.json';

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write mesh file: {$filePath}");
        }

        // A raw mesh saved here is what the viewport resolves this id to. The
        // per-project snapshot is preferred over the file, so a leftover entry
        // from an import or an earlier scene build would keep serving the shape
        // from before this edit — every time the scene is reopened.
        if ($raw !== null) {
            ProjectAssetCache::forget($context->projectDir, 'meshes', $sanitized);
        }

        return [
            'saved' => true,
            'name' => $sanitized,
            'path' => $filePath,
            'relativePath' => $relativePath,
        ];
    }
}
