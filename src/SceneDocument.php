<?php

declare(strict_types=1);

namespace PHPolygon\Editor;

class SceneDocument
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var list<string> JSON snapshots for undo */
    private array $undoStack = [];

    /** @var list<string> JSON snapshots for redo */
    private array $redoStack = [];

    private bool $dirty = false;

    private const MAX_UNDO = 100;

    /** Marks the full-state shape {@see toState()} produces. */
    private const STATE_VERSION = 1;

    /**
     * Byte budget for the undo/redo history when the document is handed to
     * storage.
     *
     * The editor is stateless between HTTP requests: the document is written to
     * the session after every mutation and rebuilt on the next one. History has
     * to travel with it or undo does nothing at all — but a scene carrying a
     * sculpted heightmap is megabytes, and 100 snapshots of it would blow up
     * any session store. So the persisted history keeps the MOST RECENT
     * snapshots that fit and drops the rest: recent undo steps are the ones
     * anyone reaches for, and losing the oldest beats losing all of them.
     */
    private const HISTORY_BUDGET_BYTES = 2 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $data  Scene JSON structure (from SceneTranspiler::toArray)
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function markClean(): void
    {
        $this->dirty = false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The document INCLUDING its undo/redo history, for storage that has to
     * survive between requests.
     *
     * {@see toArray()} deliberately stays the plain scene shape — it is what
     * the transpiler and every command consume. Persisting that alone is what
     * silently disabled undo across HTTP: the next request rebuilt the document
     * with empty stacks, so `undo()` had nothing to pop.
     *
     * @return array<string, mixed>
     */
    public function toState(): array
    {
        return [
            '__doc' => self::STATE_VERSION,
            'data' => $this->data,
            'undo' => self::withinBudget($this->undoStack),
            'redo' => self::withinBudget($this->redoStack),
        ];
    }

    /**
     * Rebuild a document from {@see toState()}.
     *
     * Also accepts a bare scene array, which is both what older sessions hold
     * and what a caller that only has scene data can pass. Such a document
     * simply starts with no history rather than failing to load.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromState(array $state): self
    {
        if (($state['__doc'] ?? null) !== self::STATE_VERSION || ! is_array($state['data'] ?? null)) {
            return new self($state);
        }

        /** @var array<string, mixed> $data */
        $data = $state['data'];
        $document = new self($data);
        $document->undoStack = self::snapshotList($state['undo'] ?? null);
        $document->redoStack = self::snapshotList($state['redo'] ?? null);

        return $document;
    }

    /**
     * The newest snapshots that fit the budget, oldest-first order preserved.
     *
     * @param  list<string>  $snapshots
     * @return list<string>
     */
    private static function withinBudget(array $snapshots): array
    {
        $kept = [];
        $used = 0;
        foreach (array_reverse($snapshots) as $snapshot) {
            $size = strlen($snapshot);
            if ($used + $size > self::HISTORY_BUDGET_BYTES) {
                break;
            }
            $used += $size;
            $kept[] = $snapshot;
        }

        return array_reverse($kept);
    }

    /**
     * @return list<string>
     */
    private static function snapshotList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $snapshots = [];
        foreach ($raw as $snapshot) {
            if (is_string($snapshot)) {
                $snapshots[] = $snapshot;
            }
        }

        return $snapshots;
    }

    public function getName(): string
    {
        return is_string($this->data['name'] ?? null) ? $this->data['name'] : '';
    }

    // --- Entity operations ---

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntities(): array
    {
        $raw = $this->data['entities'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        /** @var list<array<string, mixed>> $entities */
        $entities = array_values($raw);

        return $entities;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntity(string $name): ?array
    {
        return $this->findEntity($name, $this->getEntities());
    }

    /**
     * A name no entity in the document uses yet.
     *
     * The editor identifies entities BY NAME — every command takes one, and
     * {@see getEntity()} answers with the first match. Two entities sharing a
     * name therefore make one of them unaddressable: edits land on whichever
     * comes first in the tree. Every path that introduces a name goes through
     * here so that cannot happen.
     */
    /**
     * @param  array<string, true>  $alsoTaken  Names not in the document yet but
     *                                          already claimed by the caller —
     *                                          a subtree being copied names its
     *                                          children before any of them are
     *                                          inserted.
     */
    public function uniqueName(string $base, array $alsoTaken = []): string
    {
        if ($base === '') {
            $base = 'Entity';
        }

        $free = fn (string $candidate): bool => ! isset($alsoTaken[$candidate])
            && $this->getEntity($candidate) === null;

        if ($free($base)) {
            return $base;
        }

        $i = 2;
        while (! $free($base.'_'.$i)) {
            $i++;
        }

        return $base.'_'.$i;
    }

    /**
     * Deep-copy an entity, its components and its descendants.
     *
     * The copy lands next to the original (same parent) unless a parent is
     * given. Descendants are renamed too: a duplicated subtree whose children
     * kept their names would leave the document with unaddressable entities.
     *
     * @return string The new root entity's name, or '' when there was nothing
     *                to copy.
     */
    public function duplicateEntity(string $name, ?string $parentName = null): string
    {
        return $this->duplicateEntities([$name], $parentName)[0] ?? '';
    }

    /**
     * Duplicate several entities as ONE undoable step.
     *
     * Duplicating a selection is a single action; pushing an undo entry per
     * entity would leave the user pressing ctrl+Z once per object to undo one
     * gesture.
     *
     * @param  list<string>  $names
     * @return list<string> The new entity names, in the order they were given.
     */
    public function duplicateEntities(array $names, ?string $parentName = null): array
    {
        $existing = array_values(array_filter(
            $names,
            fn (string $name): bool => $this->getEntity($name) !== null,
        ));
        if ($existing === []) {
            return [];
        }

        $this->pushUndo();

        $created = [];
        foreach ($existing as $name) {
            $entity = $this->getEntity($name);
            if ($entity === null) {
                continue;
            }

            $taken = [];
            $copy = $this->copyWithFreshNames($entity, $this->uniqueName($name, $taken), $taken);
            $parent = $parentName ?? $this->parentNameOf($name);

            $entities = $this->getEntities();
            if ($parent === null) {
                $entities[] = $copy;
            } else {
                $this->addEntityToParent($copy['name'], $parent, $copy, $entities);
            }
            $this->data['entities'] = $entities;
            $created[] = $copy['name'];
        }

        $this->dirty = true;

        return $created;
    }

    /**
     * @param  array<string, mixed>  $entity
     * @param  array<string, true>  $taken  Names claimed so far in this copy.
     * @return array<string, mixed>
     */
    private function copyWithFreshNames(array $entity, string $newName, array &$taken): array
    {
        $entity['name'] = $newName;
        $taken[$newName] = true;

        if (isset($entity['children']) && is_array($entity['children'])) {
            $children = [];
            /** @var list<array<string, mixed>> $entityChildren */
            $entityChildren = $entity['children'];
            foreach ($entityChildren as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $childName = is_string($child['name'] ?? null) ? $child['name'] : 'Child';
                $children[] = $this->copyWithFreshNames($child, $this->uniqueName($childName, $taken), $taken);
            }
            $entity['children'] = $children;
        }

        return $entity;
    }

    /**
     * The name of an entity's parent; null both when it sits at the root and
     * when it does not exist — the caller has already established that it does.
     */
    private function parentNameOf(string $name): ?string
    {
        $walk = function (array $entities, ?string $parent) use (&$walk, $name): ?string {
            foreach ($entities as $entity) {
                if (($entity['name'] ?? null) === $name) {
                    // Sentinel: the empty string means "found, at the root",
                    // which null cannot express here.
                    return $parent ?? '';
                }
                if (isset($entity['children']) && is_array($entity['children'])) {
                    $found = $walk(
                        array_values($entity['children']),
                        is_string($entity['name'] ?? null) ? $entity['name'] : null,
                    );
                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        $found = $walk($this->getEntities(), null);

        return ($found === null || $found === '') ? null : $found;
    }

    public function addEntity(string $name, ?string $parentName = null): void
    {
        $this->pushUndo();

        $newEntity = [
            'name' => $name,
            'components' => [],
        ];

        if ($parentName === null) {
            $entities = $this->getEntities();
            $entities[] = $newEntity;
            $this->data['entities'] = $entities;
        } else {
            $entities = $this->getEntities();
            $this->addEntityToParent($name, $parentName, $newEntity, $entities);
            $this->data['entities'] = $entities;
        }

        $this->dirty = true;
    }

    /**
     * Add an entity that REFERENCES a code prefab: it carries a `prefab` class
     * plus authored override components (e.g. a design variant + Transform3D)
     * instead of inlined geometry. The engine regenerates the geometry from the
     * prefab's build() on load; the editor expands it for preview. The `prefab`
     * field survives {@see toArray()} and thus save, and the engine transpiler /
     * JsonSceneLoader round-trip it.
     *
     * @param  list<array<string, mixed>>  $components  Authored components ({_class, ...}).
     */
    public function addPrefabInstance(string $name, string $prefabClass, array $components = [], ?string $parentName = null): void
    {
        $this->pushUndo();

        $newEntity = [
            'name' => $name,
            'prefab' => $prefabClass,
            'components' => array_values($components),
        ];

        if ($parentName === null) {
            $entities = $this->getEntities();
            $entities[] = $newEntity;
            $this->data['entities'] = $entities;
        } else {
            $entities = $this->getEntities();
            $this->addEntityToParent($name, $parentName, $newEntity, $entities);
            $this->data['entities'] = $entities;
        }

        $this->dirty = true;
    }

    public function removeEntity(string $name): void
    {
        $this->pushUndo();
        $this->data['entities'] = $this->removeEntityFromList($name, $this->getEntities());
        $this->dirty = true;
    }

    public function renameEntity(string $oldName, string $newName): void
    {
        $this->pushUndo();
        $entities = $this->getEntities();
        $this->renameEntityInList($oldName, $newName, $entities);
        $this->data['entities'] = $entities;
        $this->dirty = true;
    }

    public function reparentEntity(string $entityName, ?string $newParentName): void
    {
        $this->pushUndo();

        // Find and remove entity from current position
        $entity = $this->findEntity($entityName, $this->getEntities());
        if ($entity === null) {
            return;
        }

        $entities = $this->removeEntityFromList($entityName, $this->getEntities());
        $this->data['entities'] = $entities;

        if ($newParentName === null) {
            // Move to root
            $entities = $this->getEntities();
            $entities[] = $entity;
            $this->data['entities'] = $entities;
        } else {
            $entities = $this->getEntities();
            $this->addEntityToParent($entityName, $newParentName, $entity, $entities);
            $this->data['entities'] = $entities;
        }

        $this->dirty = true;
    }

    // --- Component operations ---

    /**
     * @param  array<string, mixed>  $defaults
     */
    public function addComponent(string $entityName, string $componentClass, array $defaults = []): void
    {
        $this->pushUndo();

        $component = array_merge(['_class' => $componentClass], $defaults);
        $this->modifyEntity($entityName, function (array &$entity) use ($component) {
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
            $components[] = $component;
            $entity['components'] = $components;
        });

        $this->dirty = true;
    }

    public function removeComponent(string $entityName, string $componentClass): void
    {
        $this->pushUndo();

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass) {
            /** @var list<array<string, mixed>> $components */
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
            $entity['components'] = array_values(array_filter(
                $components,
                fn (array $c) => ($c['_class'] ?? '') !== $componentClass,
            ));
        });

        $this->dirty = true;
    }

    public function updateProperty(string $entityName, string $componentClass, string $property, mixed $value): void
    {
        $this->pushUndo();
        $this->writeProperties($entityName, $componentClass, [$property => $value]);
        $this->dirty = true;
    }

    /**
     * Apply several property writes as ONE undoable step.
     *
     * A gizmo drag rewrites position + rotation + scale at once; sending those
     * as separate {@see updateProperty()} calls would push three undo entries,
     * so a single ctrl+Z would only undo the scale. Multi-entity edits collapse
     * the same way — one drag stays one undo entry however many entities moved.
     *
     * An edit may set `create` to attach the component when the entity does not
     * carry it yet. That is what overriding an INHERITED value on a prefab
     * instance needs: the value shows in the inspector because the prefab
     * produces it, but the instance has no component to write into until the
     * first override makes one. It stays opt-in so a mistyped class name on a
     * plain entity still writes nowhere instead of adding a junk component.
     *
     * @param  list<array{entity: string, component: string, properties: array<string, mixed>, create?: bool}>  $edits
     */
    public function applyPropertyEdits(array $edits): void
    {
        if ($edits === []) {
            return;
        }

        $this->pushUndo();
        foreach ($edits as $edit) {
            if (($edit['create'] ?? false) === true) {
                $this->ensureComponent($edit['entity'], $edit['component']);
            }
            $this->writeProperties($edit['entity'], $edit['component'], $edit['properties']);
        }
        $this->dirty = true;
    }

    /**
     * Attach a bare component if the entity has none of that class, without
     * touching the undo stack.
     */
    private function ensureComponent(string $entityName, string $componentClass): void
    {
        $entity = $this->getEntity($entityName);
        if ($entity === null) {
            return;
        }

        $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
        foreach ($components as $component) {
            if (is_array($component) && ($component['_class'] ?? '') === $componentClass) {
                return;
            }
        }

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass) {
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
            $components[] = ['_class' => $componentClass];
            $entity['components'] = array_values($components);
        });
    }

    /**
     * Replace an entity's component list.
     *
     * `$undoable = false` folds the change into whatever step is already in
     * progress — what prefab override-stripping needs: the write and the strip
     * are one edit from the user's side, and pushing twice would make ctrl+Z
     * restore a half-stripped state nobody ever saw.
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function setEntityComponents(string $entityName, array $components, bool $undoable = true): void
    {
        if ($undoable) {
            $this->pushUndo();
        }

        $this->modifyEntity($entityName, function (array &$entity) use ($components) {
            $entity['components'] = array_values($components);
        });

        $this->dirty = true;
    }

    /**
     * Write properties onto one entity's component WITHOUT touching the undo
     * stack — the callers above decide what counts as a single undoable step.
     *
     * @param  array<string, mixed>  $properties
     */
    private function writeProperties(string $entityName, string $componentClass, array $properties): void
    {
        if ($properties === []) {
            return;
        }

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass, $properties) {
            if (! is_array($entity['components'] ?? null)) {
                return;
            }
            foreach ($entity['components'] as &$component) {
                if (! is_array($component)) {
                    continue;
                }
                if (($component['_class'] ?? '') === $componentClass) {
                    foreach ($properties as $property => $value) {
                        $component[$property] = $value;
                    }

                    return;
                }
            }
        });
    }

    // --- Undo/Redo ---

    public function undo(): void
    {
        if (empty($this->undoStack)) {
            return;
        }

        $encoded = json_encode($this->data);
        if (is_string($encoded)) {
            $this->redoStack[] = $encoded;
        }
        $snapshot = array_pop($this->undoStack);
        $decoded = json_decode((string) $snapshot, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];
        $this->data = $data;
        $this->dirty = true;
    }

    public function redo(): void
    {
        if (empty($this->redoStack)) {
            return;
        }

        $encoded = json_encode($this->data);
        if (is_string($encoded)) {
            $this->undoStack[] = $encoded;
        }
        $snapshot = array_pop($this->redoStack);
        $decoded = json_decode((string) $snapshot, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];
        $this->data = $data;
        $this->dirty = true;
    }

    public function canUndo(): bool
    {
        return ! empty($this->undoStack);
    }

    public function canRedo(): bool
    {
        return ! empty($this->redoStack);
    }

    // --- Internal ---

    private function pushUndo(): void
    {
        $encoded = json_encode($this->data);
        if (is_string($encoded)) {
            $this->undoStack[] = $encoded;
        }
        if (count($this->undoStack) > self::MAX_UNDO) {
            array_shift($this->undoStack);
        }
        $this->redoStack = [];
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @return array<string, mixed>|null
     */
    private function findEntity(string $name, array $entities): ?array
    {
        foreach ($entities as $entity) {
            if (($entity['name'] ?? null) === $name) {
                return $entity;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $entity['children'];
                $found = $this->findEntity($name, $children);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @return list<array<string, mixed>>
     */
    private function removeEntityFromList(string $name, array $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            if (($entity['name'] ?? null) === $name) {
                continue;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $entity['children'] = $this->removeEntityFromList($name, $entityChildren);
                if (empty($entity['children'])) {
                    unset($entity['children']);
                }
            }
            $result[] = $entity;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $newEntity
     * @param  list<array<string, mixed>>  $entities
     */
    private function addEntityToParent(string $name, string $parentName, array $newEntity, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if (($entity['name'] ?? null) === $parentName) {
                $children = isset($entity['children']) && is_array($entity['children']) ? $entity['children'] : [];
                $children[] = $newEntity;
                $entity['children'] = $children;

                return;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $this->addEntityToParent($name, $parentName, $newEntity, $entityChildren);
                $entity['children'] = $entityChildren;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    private function renameEntityInList(string $oldName, string $newName, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if (($entity['name'] ?? null) === $oldName) {
                $entity['name'] = $newName;

                return;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $this->renameEntityInList($oldName, $newName, $entityChildren);
                $entity['children'] = $entityChildren;
            }
        }
    }

    private function modifyEntity(string $name, callable $modifier): void
    {
        $entities = $this->getEntities();
        $this->modifyEntityInList($name, $modifier, $entities);
        $this->data['entities'] = $entities;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    private function modifyEntityInList(string $name, callable $modifier, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if (($entity['name'] ?? null) === $name) {
                $modifier($entity);

                return;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $this->modifyEntityInList($name, $modifier, $entityChildren);
                $entity['children'] = $entityChildren;
            }
        }
    }
}
