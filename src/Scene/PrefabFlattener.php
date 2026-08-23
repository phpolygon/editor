<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

use PHPolygon\Scene\Transpiler\ComponentOverrides;

/**
 * Replaces prefab references with the entities they stand for.
 *
 * The editor's normal output keeps the reference: scenes stay compact, and
 * changing a prefab reaches every placement. Flattening is the deliberate
 * opposite, for the cases where that indirection is in the way — shipping a
 * build whose start-up should not re-run every prefab's build(), handing a
 * scene to something that has no access to the prefab sources, or simply
 * reading what a reference actually produces.
 *
 * It is a PROJECTION: the authored document keeps its references and is never
 * written back from a flattened tree, so nothing about it is lossy in the
 * editor. What the flattened file loses is the link — that is the point of
 * asking for it.
 */
final class PrefabFlattener
{
    /**
     * Expand every prefab reference in an entity list.
     *
     * Instances whose prefab cannot be built here are left as references: a
     * scene that still says what it meant beats one silently missing objects.
     *
     * @param  list<array<string, mixed>>  $entities
     * @return array{entities: list<array<string, mixed>>, expanded: int, skipped: list<string>}
     */
    public static function flatten(array $entities): array
    {
        $used = [];
        self::collectNames($entities, $used);

        $expanded = 0;
        $skipped = [];
        $result = self::walk($entities, $used, $expanded, $skipped);

        return ['entities' => $result, 'expanded' => $expanded, 'skipped' => $skipped];
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @param  array<string, true>  $used
     * @param  list<string>  $skipped
     * @return list<array<string, mixed>>
     */
    private static function walk(array $entities, array &$used, int &$expanded, array &$skipped): array
    {
        $result = [];

        foreach ($entities as $entity) {
            $prefabClass = is_string($entity['prefab'] ?? null) ? $entity['prefab'] : '';

            if ($prefabClass === '') {
                if (is_array($entity['children'] ?? null)) {
                    $entity['children'] = self::walk(array_values($entity['children']), $used, $expanded, $skipped);
                }
                $result[] = $entity;

                continue;
            }

            $name = is_string($entity['name'] ?? null) ? $entity['name'] : $prefabClass;
            $content = PrefabBaseline::contentOf($prefabClass, $name);

            if ($content === null) {
                $skipped[] = $name;
                $result[] = $entity;

                continue;
            }

            /** @var list<array<string, mixed>> $authored */
            $authored = is_array($entity['components'] ?? null) ? array_values($entity['components']) : [];
            $content = self::applyOverrides($content, $authored);

            // The prefab names its own parts, so two instances of one prefab
            // would collide. Only the descendants are renamed: the instance's
            // own name is what the scene already refers to.
            self::uniquifyChildren($content, $name, $used);

            if (is_array($entity['children'] ?? null) && $entity['children'] !== []) {
                $children = is_array($content['children'] ?? null) ? array_values($content['children']) : [];
                $content['children'] = array_merge(
                    $children,
                    self::walk(array_values($entity['children']), $used, $expanded, $skipped),
                );
            }

            $expanded++;
            $result[] = $content;
        }

        return $result;
    }

    /**
     * Apply an instance's authored components onto the prefab's root, by class
     * — the same rule the runtime uses ({@see ComponentOverrides}).
     *
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $authored
     * @return array<string, mixed>
     */
    private static function applyOverrides(array $content, array $authored): array
    {
        /** @var list<array<string, mixed>> $components */
        $components = is_array($content['components'] ?? null) ? array_values($content['components']) : [];

        foreach ($authored as $override) {
            $class = is_string($override['_class'] ?? null) ? $override['_class'] : '';
            if ($class === '') {
                continue;
            }

            $replaced = false;
            foreach ($components as $i => $component) {
                if (($component['_class'] ?? '') !== $class) {
                    continue;
                }
                // Merge rather than replace: an override carries only the
                // properties it changes, and the rest still comes from the
                // prefab.
                $components[$i] = array_merge($component, $override);
                $replaced = true;
                break;
            }

            if (! $replaced) {
                $components[] = $override;
            }
        }

        $content['components'] = $components;

        return $content;
    }

    /**
     * Give an expanded subtree's descendants names nothing else in the scene
     * uses.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $used
     */
    private static function uniquifyChildren(array &$node, string $prefix, array &$used): void
    {
        if (! is_array($node['children'] ?? null)) {
            return;
        }

        $children = [];
        foreach (array_values($node['children']) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $base = is_string($child['name'] ?? null) ? $child['name'] : 'Child';
            $child['name'] = self::uniqueName($prefix.'_'.$base, $used);
            self::uniquifyChildren($child, $prefix, $used);
            $children[] = $child;
        }

        $node['children'] = $children;
    }

    /**
     * @param  array<string, true>  $used
     */
    private static function uniqueName(string $base, array &$used): string
    {
        $name = $base;
        $n = 2;
        while (isset($used[$name])) {
            $name = $base.'_'.$n;
            $n++;
        }
        $used[$name] = true;

        return $name;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @param  array<string, true>  $used
     */
    private static function collectNames(array $entities, array &$used): void
    {
        foreach ($entities as $entity) {
            if (is_string($entity['name'] ?? null)) {
                $used[$entity['name']] = true;
            }
            if (is_array($entity['children'] ?? null)) {
                self::collectNames(array_values($entity['children']), $used);
            }
        }
    }
}
