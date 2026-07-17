<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
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

        $nodes = is_array($this->args['nodes'] ?? null) ? $this->args['nodes'] : [];
        $output = is_string($this->args['output'] ?? null) ? $this->args['output'] : '';
        if ($nodes === [] || $output === '') {
            throw new RuntimeException('Mesh graph must have nodes and an output');
        }

        $dir = Path::join($context->getAssetsDir(), self::MESHES_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create meshes directory: {$dir}");
        }

        $filePath = Path::join($dir, $sanitized.'.mesh.json');
        $relativePath = self::MESHES_SUBDIR.'/'.$sanitized.'.mesh.json';

        $json = json_encode(
            ['name' => $sanitized, 'nodes' => $nodes, 'output' => $output],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
        if ($json === false || file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write mesh file: {$filePath}");
        }

        return [
            'saved' => true,
            'name' => $sanitized,
            'path' => $filePath,
            'relativePath' => $relativePath,
        ];
    }
}
