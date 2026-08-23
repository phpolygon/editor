<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use RuntimeException;

/**
 * Copy entities, with their components and descendants, next to the originals.
 *
 * Takes a list so duplicating a multi-entity selection is one action and one
 * undo step — duplicating them one command at a time would leave the user
 * pressing ctrl+Z once per object to get back.
 *
 * A prefab instance duplicates as an instance: its `prefab` reference and
 * overrides are part of the entity, so the copy keeps following the same
 * prefab.
 *
 * args: { entities: string[] }  (or { entity: string } for a single one)
 */
class DuplicateEntityCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{duplicated: list<string>, from: list<string>} */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $names = $this->requestedNames();
        if ($names === []) {
            throw new RuntimeException("Missing 'entity' or 'entities' argument");
        }

        foreach ($names as $name) {
            if ($doc->getEntity($name) === null) {
                throw new RuntimeException("Entity not found: {$name}");
            }
        }

        // Duplicating a parent already copies its children, so a selection of
        // both would produce the child twice.
        $roots = $this->withoutDescendants($doc, $names);

        return ['duplicated' => $doc->duplicateEntities($roots), 'from' => $roots];
    }

    /** @return list<string> */
    private function requestedNames(): array
    {
        $raw = $this->args['entities'] ?? $this->args['entity'] ?? null;
        if (is_string($raw)) {
            return $raw === '' ? [] : [$raw];
        }
        if (! is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Drop names that live under another name in the same list.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function withoutDescendants(SceneDocument $doc, array $names): array
    {
        $roots = [];
        foreach ($names as $name) {
            $covered = false;
            foreach ($names as $other) {
                if ($other === $name) {
                    continue;
                }
                $ancestor = $doc->getEntity($other);
                if ($ancestor !== null && $this->contains($ancestor, $name)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $roots[] = $name;
            }
        }

        return $roots;
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function contains(array $entity, string $name): bool
    {
        if (! isset($entity['children']) || ! is_array($entity['children'])) {
            return false;
        }

        foreach ($entity['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['name'] ?? null) === $name || $this->contains($child, $name)) {
                return true;
            }
        }

        return false;
    }
}
