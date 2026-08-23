<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\EntityFormatter;
use PHPolygon\Editor\Scene\PrefabBaseline;
use PHPolygon\Editor\Scene\PrefabClassFile;
use PHPolygon\Editor\Scene\PrefabClassGenerator;
use PHPolygon\Editor\SceneDocument;
use RuntimeException;

/**
 * Open a prefab's own contents for editing.
 *
 * The prefab's build() is run and its subtree becomes the active document, so
 * it is edited with the same hierarchy, inspector and gizmo as a scene. Saving
 * goes back through {@see CreatePrefabClassCommand}, which regenerates the
 * class from the edited tree.
 *
 * This REPLACES the active document — the caller is expected to have saved the
 * scene first, exactly as it would before loading another one. The response
 * carries `editingPrefab` so the editor knows to save as a prefab rather than
 * writing a scene file.
 *
 * `writable: false` warns up front that the class has been edited by hand: the
 * tree still opens for inspection, but regenerating it would discard that work
 * ({@see PrefabClassGenerator::isRegenerable()}).
 *
 * args: { class: string }
 */
class LoadPrefabClassCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $class = is_string($this->args['class'] ?? null) ? $this->args['class'] : '';
        if ($class === '') {
            throw new RuntimeException("Missing 'class' argument");
        }

        $root = PrefabBaseline::contentOf($class, $this->rootName($class));
        if ($root === null) {
            throw new RuntimeException(
                "Cannot build {$class} here, so its contents cannot be opened. ".
                'A prefab whose build() needs the running game can only be edited as code.'
            );
        }

        $document = new SceneDocument([
            'name' => $this->rootName($class),
            'entities' => [$root],
        ]);
        $context->setActiveDocument($document);

        return [
            'editingPrefab' => $class,
            'name' => $this->rootName($class),
            'writable' => PrefabClassFile::isRegenerable($context, $class),
            'entities' => EntityFormatter::nestComponents([$root]),
        ];
    }

    /** The prefab's short class name, used as the root entity's name. */
    private function rootName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
