<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\DroppedPropertyDetector;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

class SaveSceneCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $data = $doc->toArray();
        $sceneName = is_string($data['name'] ?? null) ? $data['name'] : 'untitled';
        $scenesDir = $context->getScenesDir();

        if (! is_dir($scenesDir)) {
            mkdir($scenesDir, 0755, true);
        }

        // A scene loaded from an exported JSON snapshot is saved back as JSON,
        // not regenerated as a PHP class. PHP scenes stay the canonical source.
        $jsonPath = Path::join($scenesDir, $sceneName.'.scene.json');
        if (is_file($jsonPath)) {
            file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $doc->markClean();

            return ['saved' => $jsonPath, 'dirty' => false, 'format' => 'json'];
        }

        $className = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $sceneName)));
        $path = Path::join($scenesDir, $className.'.php');
        $generated = $context->transpiler->fromArray($data);
        file_put_contents($path, $generated);
        $doc->markClean();

        // Saving is the point where a component the generator cannot express
        // loses its values. Report it rather than let the next scene load come
        // back with an empty mesh graph or heightmap and no explanation.
        $dropped = DroppedPropertyDetector::detect($data, $generated);

        return [
            'saved' => $path,
            'dirty' => false,
            'format' => 'php',
            'dropped' => $dropped,
            'warning' => DroppedPropertyDetector::describe($dropped),
        ];
    }
}
