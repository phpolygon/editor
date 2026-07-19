<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Delete a saved mesh asset (`assets/meshes/<name>.mesh.json`). The name is
 * sanitized and resolved inside the meshes dir, so it can't traverse outside
 * the project.
 */
class DeleteMeshAssetCommand implements CommandInterface
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

        if (! unlink($file)) {
            throw new RuntimeException("Failed to delete mesh file: {$file}");
        }

        return ['deleted' => true, 'name' => $sanitized];
    }
}
