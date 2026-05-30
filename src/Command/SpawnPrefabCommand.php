<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use RuntimeException;

class SpawnPrefabCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $relPath = is_string($this->args['path'] ?? null) ? $this->args['path'] : '';
        if ($relPath === '') {
            throw new RuntimeException("Missing 'path' argument");
        }

        $assetsDir = realpath($context->getAssetsDir());
        if ($assetsDir === false) {
            throw new RuntimeException('Assets directory not found');
        }

        $absolute = realpath($assetsDir . DIRECTORY_SEPARATOR . $relPath);
        if ($absolute === false || !is_file($absolute)
            || !str_starts_with($absolute, $assetsDir . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException("Prefab file not found: {$relPath}");
        }

        $raw = file_get_contents($absolute);
        if ($raw === false) {
            throw new RuntimeException("Failed to read prefab file: {$absolute}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['root']) || !is_array($decoded['root'])) {
            throw new RuntimeException("Invalid prefab format: {$relPath}");
        }

        /** @var array<string, mixed> $root */
        $root = $decoded['root'];
        $parent = is_string($this->args['parent'] ?? null) && $this->args['parent'] !== ''
            ? $this->args['parent']
            : null;

        $spawnedRootName = $this->spawn($doc, $root, $parent);

        return [
            'spawned' => $spawnedRootName,
            'parent'  => $parent,
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function spawn(SceneDocument $doc, array $node, ?string $parent): string
    {
        $base = is_string($node['name'] ?? null) ? $node['name'] : 'Entity';
        $name = $this->uniqueName($doc, $base);

        $doc->addEntity($name, $parent);

        /** @var list<array<string, mixed>> $components */
        $components = isset($node['components']) && is_array($node['components']) ? $node['components'] : [];
        foreach ($components as $component) {
            if (!is_array($component) || !isset($component['_class']) || !is_string($component['_class'])) {
                continue;
            }
            $class = $component['_class'];
            $defaults = $component;
            unset($defaults['_class']);
            $doc->addComponent($name, $class, $defaults);
        }

        /** @var list<array<string, mixed>> $children */
        $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
        foreach ($children as $child) {
            $this->spawn($doc, $child, $name);
        }

        return $name;
    }

    private function uniqueName(SceneDocument $doc, string $base): string
    {
        if ($doc->getEntity($base) === null) {
            return $base;
        }
        $i = 2;
        while ($doc->getEntity($base . '_' . $i) !== null) {
            $i++;
        }
        return $base . '_' . $i;
    }
}
