<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\SceneClassResolver;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Create a new, empty scene authored in the editor and write it out as a PHP
 * Scene class (the editor is the source; PHP is the generated artifact).
 *
 * The class name and namespace are derived from the scene name and the
 * project's PSR-4 roots, recorded as `_scene` so a later save round-trips to
 * the same class/file.
 */
class CreateSceneCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? trim($this->args['name']) : '';
        if ($name === '' || preg_replace('/[^A-Za-z0-9_\-]/', '', $name) !== $name) {
            throw new RuntimeException('Invalid scene name (use letters, digits, underscore or hyphen)');
        }

        $scenesDir = $context->getScenesDir();
        $className = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));

        // Recover the namespace the scene class will live in from the PSR-4
        // roots, so the generated PHP is placed correctly.
        $namespace = SceneClassResolver::namespaceFor(
            Path::join($scenesDir, $className.'.php'),
            $context,
        );
        $sceneFqcn = $namespace === '' ? $className : $namespace.'\\'.$className;

        if (! is_dir($scenesDir) && ! mkdir($scenesDir, 0o755, true) && ! is_dir($scenesDir)) {
            throw new RuntimeException("Failed to create scenes directory: {$scenesDir}");
        }

        $path = Path::join($scenesDir, $className.'.php');
        if (is_file($path)) {
            throw new RuntimeException("Scene already exists: {$className}");
        }

        $data = [
            '_version' => 1,
            '_scene' => $sceneFqcn,
            'name' => $name,
            'systems' => [],
            'entities' => [],
        ];

        file_put_contents($path, $context->transpiler->fromArray($data));

        $context->setActiveDocument(new SceneDocument($data));

        // Nested (empty) entities, mirroring load_scene's frontend shape.
        $data['entities'] = [];

        return $data;
    }
}
