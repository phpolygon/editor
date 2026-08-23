<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\ComponentOverrides;

/**
 * Keeps a prefab instance's authored components down to what it actually
 * overrides.
 *
 * A scene stores a prefab instance as a reference plus the components it
 * authors on top. Left alone that set only ever grows: setting a value back to
 * what the prefab already produces would still be recorded, so the scene fills
 * with overrides that change nothing and "what did I customise here?" becomes
 * unanswerable.
 *
 * This is the diffing half {@see ComponentOverrides}
 * leaves to the editor — the engine applies overrides at load, the editor
 * decides which ones are worth storing.
 */
final class PrefabOverrides
{
    /**
     * Mark the edits that need their component attached first.
     *
     * A prefab instance shows the prefab's values in the inspector without
     * carrying the components — they come from build(). Overriding one of those
     * values therefore has nowhere to land until the component exists, and the
     * edit would silently do nothing.
     *
     * Every component on an instance qualifies, whether or not the prefab's
     * baseline could be read: the runtime applies an override by class and
     * appends one the baseline lacks, and an edit the user asked for must not
     * vanish just because the editor could not build the prefab to check.
     *
     * @param  list<array{entity: string, component: string, properties: array<string, mixed>}>  $edits
     * @return list<array{entity: string, component: string, properties: array<string, mixed>, create?: bool}>
     */
    public static function markCreatable(SceneDocument $document, array $edits): array
    {
        $marked = [];
        foreach ($edits as $edit) {
            $entity = $document->getEntity($edit['entity']);

            if (is_string($entity['prefab'] ?? null) && $entity['prefab'] !== '') {
                $edit['create'] = true;
            }

            $marked[] = $edit;
        }

        return $marked;
    }

    /**
     * Drop values that match the prefab from the named entities.
     *
     * Folded into the caller's undo step ({@see SceneDocument::setEntityComponents()}
     * with `undoable: false`): an inspector edit and the pruning it triggers are
     * one action.
     *
     * @param  list<string>  $entityNames
     */
    public static function prune(SceneDocument $document, array $entityNames): void
    {
        foreach (array_unique($entityNames) as $entityName) {
            $entity = $document->getEntity($entityName);
            if ($entity === null) {
                continue;
            }

            $prefabClass = is_string($entity['prefab'] ?? null) ? $entity['prefab'] : '';
            if ($prefabClass === '') {
                continue;
            }

            /** @var list<array<string, mixed>> $components */
            $components = is_array($entity['components'] ?? null) ? array_values($entity['components']) : [];
            $stripped = PrefabBaseline::stripMatching($components, $prefabClass);

            if ($stripped !== $components) {
                $document->setEntityComponents($entityName, $stripped, undoable: false);
            }
        }
    }
}
