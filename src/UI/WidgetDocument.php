<?php

declare(strict_types=1);

namespace PHPolygon\Editor\UI;

use PHPolygon\Editor\SceneDocument;
use PHPolygon\UI\Widget\WidgetSerializer;
use RuntimeException;

/**
 * In-memory, mutable representation of a UI layout (a widget tree) that the
 * editor operates on — the UI counterpart to {@see SceneDocument}.
 *
 * The tree is held as nested arrays in the engine's {@see WidgetSerializer}
 * format. Every node is given a stable, editor-only `_id` so commands can
 * address a widget unambiguously (widgets have no natural name). Ids are
 * stripped on save so persisted `.ui.json` stays clean.
 */
final class WidgetDocument
{
    private const RESERVED = ['_id', '_widget', 'children'];

    private string $name;

    /** @var array<string, mixed> Root node, every node carrying an `_id`. */
    private array $root;

    private int $nextId = 1;

    /**
     * @param  array<string, mixed>  $root  Serialized widget node (may lack ids).
     */
    public function __construct(string $name, array $root)
    {
        if (! isset($root['_widget'])) {
            throw new RuntimeException('Root node is not a widget (missing "_widget")');
        }
        $this->name = $name;
        $this->root = $this->assignIds($root);
    }

    /**
     * @param  array<string, mixed>  $data  On-disk `{ name, root }` form.
     */
    public static function fromFileArray(array $data): self
    {
        $name = is_string($data['name'] ?? null) ? $data['name'] : 'untitled';
        $root = $data['root'] ?? null;
        if (! is_array($root)) {
            throw new RuntimeException('UI layout is missing its "root" widget');
        }

        /** @var array<string, mixed> $root */
        return new self($name, $root);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRootId(): string
    {
        return (string) $this->root['_id'];
    }

    /**
     * Frontend payload — includes the `_id`s the UI needs to address widgets.
     *
     * @return array{name: string, root: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'root' => $this->root];
    }

    /**
     * Persistence form — `_id`s stripped so the saved file stays clean.
     *
     * @return array<string, mixed>
     */
    public function toFileArray(): array
    {
        return ['_format' => 1, 'name' => $this->name, 'root' => $this->stripIds($this->root)];
    }

    /**
     * Append a new widget node under a parent. Returns the new node's id.
     *
     * @param  array<string, mixed>  $node  A default node from the catalog.
     */
    public function addWidget(string $parentId, array $node): string
    {
        $node = $this->assignIds($node);
        $newId = (string) $node['_id'];

        $inserted = $this->mutate($this->root, $parentId, function (array &$parent) use ($node): void {
            $parent['children'] = array_merge(
                is_array($parent['children'] ?? null) ? $parent['children'] : [],
                [$node],
            );
        });

        if (! $inserted) {
            throw new RuntimeException("Parent widget not found: {$parentId}");
        }

        return $newId;
    }

    public function removeWidget(string $id): void
    {
        if ($id === $this->getRootId()) {
            throw new RuntimeException('Cannot remove the root widget');
        }
        if ($this->detach($this->root, $id) === null) {
            throw new RuntimeException("Widget not found: {$id}");
        }
    }

    public function reparentWidget(string $id, string $newParentId, ?int $index = null): void
    {
        if ($id === $this->getRootId()) {
            throw new RuntimeException('Cannot reparent the root widget');
        }

        $node = $this->find($this->root, $id);
        if ($node === null) {
            throw new RuntimeException("Widget not found: {$id}");
        }
        if ($newParentId === $id || $this->contains($node, $newParentId)) {
            throw new RuntimeException('Cannot reparent a widget into itself or one of its descendants');
        }
        if ($this->find($this->root, $newParentId) === null) {
            throw new RuntimeException("Parent widget not found: {$newParentId}");
        }

        $detached = $this->detach($this->root, $id);
        // Guaranteed non-null: find() above located it.
        $this->mutate($this->root, $newParentId, function (array &$parent) use ($detached, $index): void {
            $children = is_array($parent['children'] ?? null) ? $parent['children'] : [];
            if ($index === null || $index < 0 || $index >= count($children)) {
                $children[] = $detached;
            } else {
                array_splice($children, $index, 0, [$detached]);
            }
            $parent['children'] = $children;
        });
    }

    public function updateProperty(string $id, string $property, mixed $value): void
    {
        if (in_array($property, self::RESERVED, true)) {
            throw new RuntimeException("Cannot set reserved property: {$property}");
        }

        $updated = $this->mutate($this->root, $id, function (array &$node) use ($property, $value): void {
            $node[$property] = $value;
        });

        if (! $updated) {
            throw new RuntimeException("Widget not found: {$id}");
        }
    }

    /**
     * Bind a property to a context path (`{"$bind": path}`), or clear the binding
     * with `$path = null` (the property falls back to its literal/default).
     */
    public function setBinding(string $id, string $property, ?string $path): void
    {
        if (in_array($property, self::RESERVED, true)) {
            throw new RuntimeException("Cannot bind reserved property: {$property}");
        }

        $updated = $this->mutate($this->root, $id, function (array &$node) use ($property, $path): void {
            if ($path === null) {
                unset($node[$property]);
            } else {
                $node[$property] = ['$bind' => $path];
            }
        });

        if (! $updated) {
            throw new RuntimeException("Widget not found: {$id}");
        }
    }

    /**
     * Map a widget event (e.g. 'click', 'change') to a context action, or remove
     * the mapping with a null/empty action. Stored under the node's `$on` key.
     */
    public function setEvent(string $id, string $event, ?string $action): void
    {
        $updated = $this->mutate($this->root, $id, function (array &$node) use ($event, $action): void {
            $on = is_array($node['$on'] ?? null) ? $node['$on'] : [];
            if ($action === null || $action === '') {
                unset($on[$event]);
            } else {
                $on[$event] = $action;
            }
            if ($on === []) {
                unset($node['$on']);
            } else {
                $node['$on'] = $on;
            }
        });

        if (! $updated) {
            throw new RuntimeException("Widget not found: {$id}");
        }
    }

    // ── Tree helpers ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function assignIds(array $node): array
    {
        // Preserve any existing id (so a session-restored tree keeps stable
        // ids across requests) and keep the counter ahead of the highest seen.
        if (isset($node['_id']) && is_string($node['_id'])) {
            if (preg_match('/^w(\d+)$/', $node['_id'], $m)) {
                $this->nextId = max($this->nextId, (int) $m[1] + 1);
            }
        } else {
            $node['_id'] = 'w'.$this->nextId++;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_map(
                fn ($child) => is_array($child) ? $this->assignIds($child) : $child,
                $node['children'],
            );
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function stripIds(array $node): array
    {
        unset($node['_id']);
        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_map(
                fn ($child) => is_array($child) ? $this->stripIds($child) : $child,
                $node['children'],
            );
        }

        return $node;
    }

    /**
     * Apply a callback to the node with the given id (by reference).
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): void  $cb
     */
    private function mutate(array &$node, string $id, callable $cb): bool
    {
        if (($node['_id'] ?? null) === $id) {
            $cb($node);

            return true;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            foreach (array_keys($node['children']) as $i) {
                if (is_array($node['children'][$i]) && $this->mutate($node['children'][$i], $id, $cb)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove and return the node with the given id from anywhere in the tree.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function detach(array &$node, string $id): ?array
    {
        if (! isset($node['children']) || ! is_array($node['children'])) {
            return null;
        }
        foreach (array_keys($node['children']) as $i) {
            $child = $node['children'][$i];
            if (! is_array($child)) {
                continue;
            }
            if (($child['_id'] ?? null) === $id) {
                array_splice($node['children'], $i, 1);
                if ($node['children'] === []) {
                    unset($node['children']);
                }

                return $child;
            }
            $found = $this->detach($node['children'][$i], $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function find(array $node, string $id): ?array
    {
        if (($node['_id'] ?? null) === $id) {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $found = $this->find($child, $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function contains(array $node, string $id): bool
    {
        if (($node['_id'] ?? null) === $id) {
            return true;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && $this->contains($child, $id)) {
                return true;
            }
        }

        return false;
    }
}
