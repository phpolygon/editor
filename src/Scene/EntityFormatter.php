<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

use PHPolygon\Editor\SceneDocument;

/**
 * Reshapes serialized entity components for the editor frontend.
 *
 * The engine's scene transpiler and {@see SceneDocument}
 * store components flat — `{ "_class": ..., "<prop>": <value>, ... }` — which
 * is the canonical on-disk JSON format. The frontend, however, consumes the
 * nested `{ "_class": ..., "properties": { ... } }` shape (its ComponentData
 * type, the Three.js sync layers, and the inspector all assume it). This
 * helper bridges the two at the API boundary so both viewport rendering and
 * the inspector receive a single, consistent shape.
 */
final class EntityFormatter
{
    /**
     * @param  list<array<string, mixed>>  $entities
     * @return list<array<string, mixed>>
     */
    public static function nestComponents(array $entities): array
    {
        return array_map(static function (array $entity): array {
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
            $entity['components'] = array_values(array_map(static function (mixed $component): array {
                if (! is_array($component)) {
                    return ['_class' => is_string($component) ? $component : 'Unknown', 'properties' => []];
                }
                $class = is_string($component['_class'] ?? null) ? $component['_class'] : 'Unknown';
                unset($component['_class']);

                return ['_class' => $class, 'properties' => $component];
            }, $components));

            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $entity['children'];
                $entity['children'] = self::nestComponents($children);
            }

            return $entity;
        }, $entities);
    }
}
