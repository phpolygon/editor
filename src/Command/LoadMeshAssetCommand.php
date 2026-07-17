<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Load a saved mesh asset ({name, nodes, output}) from `assets/meshes/` back
 * into the mesh editor. The name is sanitized and resolved inside the meshes
 * dir, so it can't traverse outside the project.
 */
class LoadMeshAssetCommand implements CommandInterface
{
    private const MESHES_SUBDIR = 'meshes';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException("Invalid mesh name: {$name}");
        }

        $file = Path::join($context->getAssetsDir(), self::MESHES_SUBDIR, $sanitized.'.mesh.json');
        if (! is_file($file)) {
            throw new RuntimeException("Mesh not found: {$sanitized}");
        }

        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid mesh JSON: {$sanitized}");
        }

        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : $sanitized,
            'nodes' => is_array($data['nodes'] ?? null) ? $data['nodes'] : [],
            'output' => is_string($data['output'] ?? null) ? $data['output'] : '',
        ];
    }
}
