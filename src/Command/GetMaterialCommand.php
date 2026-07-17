<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Rendering\MaterialRegistry;
use RuntimeException;

class GetMaterialCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : null;
        if ($id === null || $id === '') {
            throw new RuntimeException("Missing 'id' argument");
        }

        $material = MaterialRegistry::get($id);
        if ($material !== null) {
            return ProjectAssetCache::materialToArray($id, $material);
        }

        // Fall back to the per-project snapshot captured at scene-load time —
        // materials built imperatively in a scene's PHP don't survive into this
        // separate request.
        $cached = ProjectAssetCache::material($context->projectDir, $id);
        if ($cached !== null) {
            return $cached;
        }

        // Finally, a material authored + saved in the editor lives on disk under
        // assets/materials/. This is how a MeshRenderer references a material the
        // editor created (which never entered a scene's runtime registry).
        $disk = $this->loadFromDisk($context, $id);
        if ($disk !== null) {
            return $disk;
        }

        throw new RuntimeException("Unknown material: {$id}");
    }

    /** @return array<string, mixed>|null */
    private function loadFromDisk(EditorContext $context, string $id): ?array
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id) ?? '';
        if ($sanitized === '') {
            return null;
        }

        $file = Path::join($context->getAssetsDir(), SaveMaterialCommand::MATERIALS_SUBDIR, $sanitized.'.material.json');
        if (! is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }
}
