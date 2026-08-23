<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\PrefabBaseline;
use RuntimeException;

/**
 * Give an overridden value back to the prefab.
 *
 * Reverting is not "write the prefab's value" — it is removing the override, so
 * the instance follows the prefab again and keeps following it when the prefab
 * changes later. Writing the value back would leave a frozen copy that silently
 * stops tracking.
 *
 * Without `property`, the whole component's overrides go. Placement transforms
 * are the exception: they are instance data rather than an override, so
 * reverting one restores the prefab's own placement values instead of removing
 * the component and leaving the instance with nothing to position it by.
 *
 * args: { entity: string, component: string, property?: string }
 */
class RevertPrefabOverrideCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $entityName = is_string($this->args['entity'] ?? null) ? $this->args['entity'] : '';
        $componentClass = is_string($this->args['component'] ?? null) ? $this->args['component'] : '';
        if ($entityName === '' || $componentClass === '') {
            throw new RuntimeException("Missing 'entity' or 'component' argument");
        }

        $entity = $doc->getEntity($entityName);
        if ($entity === null) {
            throw new RuntimeException("Entity not found: {$entityName}");
        }

        $prefabClass = is_string($entity['prefab'] ?? null) ? $entity['prefab'] : '';
        if ($prefabClass === '') {
            throw new RuntimeException("'{$entityName}' is not a prefab instance");
        }

        $baseline = PrefabBaseline::for($prefabClass);
        if ($baseline === null) {
            throw new RuntimeException(
                "Cannot read what {$prefabClass} produces on its own, so there is nothing to revert to."
            );
        }

        $property = is_string($this->args['property'] ?? null) && $this->args['property'] !== ''
            ? $this->args['property']
            : null;

        /** @var list<array<string, mixed>> $components */
        $components = is_array($entity['components'] ?? null) ? array_values($entity['components']) : [];
        $reverted = [];
        $changed = false;

        foreach ($components as $component) {
            if (($component['_class'] ?? '') !== $componentClass) {
                $reverted[] = $component;

                continue;
            }

            $changed = true;

            if (PrefabBaseline::isPlacement($componentClass)) {
                $reverted[] = $this->placementFromBaseline($component, $baseline[$componentClass] ?? null);

                continue;
            }

            if ($property === null) {
                continue; // drop the component: the prefab's version applies again
            }

            unset($component[$property]);
            if (count($component) > 1) {
                $reverted[] = $component;
            }
        }

        if (! $changed) {
            throw new RuntimeException("'{$entityName}' has no {$componentClass} to revert");
        }

        $doc->setEntityComponents($entityName, $reverted);

        return [
            'entity' => $entityName,
            'component' => $componentClass,
            'property' => $property,
            'reverted' => true,
        ];
    }

    /**
     * A placement transform reset to the prefab's own values, keeping the
     * component in place.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>|null  $baseline
     * @return array<string, mixed>
     */
    private function placementFromBaseline(array $component, ?array $baseline): array
    {
        if ($baseline === null) {
            return $component;
        }

        $restored = ['_class' => $component['_class']];
        foreach ($baseline as $key => $value) {
            if ($key === '_class') {
                continue;
            }
            $restored[$key] = $value;
        }

        return $restored;
    }
}
