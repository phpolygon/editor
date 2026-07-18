<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use RuntimeException;

/**
 * Place a CODE prefab as a REFERENCE: create one scene entity carrying a `prefab`
 * class + authored override components (e.g. a design-variant component and a
 * Transform3D) instead of inlining geometry. The engine regenerates the geometry
 * from the prefab's build() on load; the editor expands it for preview.
 *
 * Contrast {@see SpawnPrefabCommand}, which inlines a file-based prefab's full
 * component/children tree.
 *
 * args: { class: string (prefab FQCN), name?: string, parent?: string,
 *         components?: list<{_class, ...}> }
 */
class SpawnCodePrefabCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{spawned: string, prefab: string, parent: string|null} */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $class = is_string($this->args['class'] ?? null) ? $this->args['class'] : '';
        if ($class === '') {
            throw new RuntimeException("Missing 'class' argument");
        }

        $base = is_string($this->args['name'] ?? null) && $this->args['name'] !== ''
            ? $this->args['name']
            : $this->shortName($class);
        $name = $this->uniqueName($doc, $base);

        $parent = is_string($this->args['parent'] ?? null) && $this->args['parent'] !== ''
            ? $this->args['parent']
            : null;

        $doc->addPrefabInstance($name, $class, $this->sanitizeComponents($this->args['components'] ?? null), $parent);

        return [
            'spawned' => $name,
            'prefab' => $class,
            'parent' => $parent,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanitizeComponents(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $components = [];
        foreach ($raw as $component) {
            if (is_array($component) && is_string($component['_class'] ?? null)) {
                /** @var array<string, mixed> $component */
                $components[] = $component;
            }
        }

        return $components;
    }

    private function shortName(string $class): string
    {
        $parts = explode('\\', $class);
        $short = end($parts);

        return $short !== false && $short !== '' ? $short : 'Prefab';
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
