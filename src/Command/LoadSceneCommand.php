<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Scene;
use RuntimeException;

class LoadSceneCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $sceneName = (string)($this->args['sceneName'] ?? $this->args['scene'] ?? '');
        if ($sceneName === '') {
            throw new RuntimeException("Missing 'sceneName' argument");
        }

        $scenesDir = $context->getScenesDir();
        $sceneFile = $scenesDir . DIRECTORY_SEPARATOR . $sceneName . '.php';

        if (!file_exists($sceneFile)) {
            throw new RuntimeException("Scene file not found: {$sceneFile}");
        }

        // Load the scene class
        require_once $sceneFile;

        // Find the scene class in the file by convention
        $namespace = $this->guessNamespace($sceneFile, $context);
        $className = $namespace . '\\' . $sceneName;

        if (!class_exists($className)) {
            throw new RuntimeException("Scene class not found: {$className}");
        }

        $scene = new $className();
        if (!$scene instanceof Scene) {
            throw new RuntimeException("Class {$className} is not a Scene");
        }
        $data = $context->transpiler->toArray($scene);

        $context->setActiveDocument(new SceneDocument($data));

        return $data;
    }

    private function guessNamespace(string $file, EditorContext $context): string
    {
        foreach ($context->manifest->psr4Roots as $ns => $path) {
            $fullPath = $context->projectDir . DIRECTORY_SEPARATOR . $path;
            if (str_starts_with($file, $fullPath)) {
                $relative = str_replace($fullPath, '', dirname($file));
                $relative = trim($relative, DIRECTORY_SEPARATOR);
                $ns = rtrim($ns, '\\');
                return $relative ? $ns . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative) : $ns;
            }
        }
        return '';
    }
}
