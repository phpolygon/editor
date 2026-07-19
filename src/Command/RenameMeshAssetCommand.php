<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Rename a saved mesh asset under `assets/meshes/`. Both names are sanitized
 * and resolved inside the meshes dir (no traversal). The stored payload's
 * `name` field is rewritten too, so the asset round-trips consistently.
 */
class RenameMeshAssetCommand implements CommandInterface
{
    private const MESHES_SUBDIR = 'meshes';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $from = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        $to = is_string($this->args['newName'] ?? null) ? $this->args['newName'] : '';

        $fromSan = preg_replace('/[^A-Za-z0-9_\-]/', '_', $from) ?? '';
        $toSan = preg_replace('/[^A-Za-z0-9_\-]/', '_', $to) ?? '';
        if ($fromSan === '') {
            throw new RuntimeException("Invalid mesh name: {$from}");
        }
        if ($toSan === '') {
            throw new RuntimeException("Invalid mesh name: {$to}");
        }

        $dir = Path::join($context->getAssetsDir(), self::MESHES_SUBDIR);
        $fromFile = Path::join($dir, $fromSan.'.mesh.json');
        $toFile = Path::join($dir, $toSan.'.mesh.json');

        if (! is_file($fromFile)) {
            throw new RuntimeException("Mesh not found: {$fromSan}");
        }
        if ($toSan !== $fromSan && is_file($toFile)) {
            throw new RuntimeException("A mesh named '{$toSan}' already exists");
        }

        // Rewrite the payload's own name so the loaded asset matches its file.
        $raw = file_get_contents($fromFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $data['name'] = $toSan;
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                file_put_contents($fromFile, $json);
            }
        }

        if ($fromFile !== $toFile && ! rename($fromFile, $toFile)) {
            throw new RuntimeException("Failed to rename mesh: {$fromSan} → {$toSan}");
        }

        return [
            'renamed' => true,
            'name' => $toSan,
            'path' => self::MESHES_SUBDIR.'/'.$toSan.'.mesh.json',
        ];
    }
}
