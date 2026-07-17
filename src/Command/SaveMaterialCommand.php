<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist a material (the editor's MaterialData shape) as a reusable asset under
 * `assets/materials/<id>.material.json`. This is the editor's own material
 * store — the engine only builds materials imperatively in a scene's PHP, so
 * before this there was no way to author and keep a material. The saved id is
 * what a MeshRenderer's materialId references; {@see GetMaterialCommand} reads
 * these files back so the viewport can render them.
 */
class SaveMaterialCommand implements CommandInterface
{
    public const MATERIALS_SUBDIR = 'materials';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $material = is_array($this->args['material'] ?? null) ? $this->args['material'] : null;
        if ($material === null) {
            throw new RuntimeException("Missing 'material' argument");
        }

        $id = is_string($material['id'] ?? null) ? $material['id'] : '';
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException("Material must have a valid 'id'");
        }

        // Keep the stored id in lockstep with the filename it's addressed by.
        $material['id'] = $sanitized;

        $dir = Path::join($context->getAssetsDir(), self::MATERIALS_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create materials directory: {$dir}");
        }

        $filePath = Path::join($dir, $sanitized.'.material.json');
        $relativePath = self::MATERIALS_SUBDIR.'/'.$sanitized.'.material.json';

        $json = json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write material file: {$filePath}");
        }

        return [
            'saved' => true,
            'id' => $sanitized,
            'path' => $filePath,
            'relativePath' => $relativePath,
        ];
    }
}
