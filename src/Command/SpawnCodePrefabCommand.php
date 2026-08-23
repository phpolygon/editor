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

        $components = $this->sanitizeComponents($this->args['components'] ?? null);
        $components = $this->withPlacementTransform($components, $context);

        $doc->addPrefabInstance($name, $class, $components, $parent);

        return [
            'spawned' => $name,
            'prefab' => $class,
            'parent' => $parent,
        ];
    }

    /**
     * Ensure the instance carries a transform.
     *
     * Placement is the one authored value every prefab instance needs — it is
     * what `build()` reads as input and what the transpiler turns into
     * `->at(...)`. Without the component there is nothing for the gizmo to grab
     * and nothing for an inspector edit to write into: `update_property` only
     * writes into a component that already exists, so setting a position would
     * silently do nothing and every instance would stack at the origin.
     *
     * @param  list<array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    private function withPlacementTransform(array $components, EditorContext $context): array
    {
        $transformClass = $context->manifest->defaultMode === '2d'
            ? 'PHPolygon\\Component\\Transform2D'
            : 'PHPolygon\\Component\\Transform3D';

        foreach ($components as $component) {
            $class = $component['_class'] ?? '';
            // Respect a transform the caller supplied, in either dimension —
            // a 3D prefab placed in a 2D project is the caller's call to make.
            if ($class === 'PHPolygon\\Component\\Transform2D' || $class === 'PHPolygon\\Component\\Transform3D') {
                return $components;
            }
        }

        $components[] = array_merge(
            ['_class' => $transformClass],
            $this->schemaDefaults($transformClass, $context),
        );

        return $components;
    }

    /**
     * Default property values for a component, as {@see AddComponentCommand}
     * would write them — so an instance's transform looks the same however it
     * got there, and the inspector has real values to show.
     *
     * @return array<string, mixed>
     */
    private function schemaDefaults(string $componentClass, EditorContext $context): array
    {
        if (! $context->components->has($componentClass)) {
            return [];
        }

        $defaults = [];
        foreach ($context->components->get($componentClass)->properties as $property) {
            if ($property->default !== null) {
                $defaults[$property->name] = $property->default;
            }
        }

        return $defaults;
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
        while ($doc->getEntity($base.'_'.$i) !== null) {
            $i++;
        }

        return $base.'_'.$i;
    }
}
