<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\EntityFormatter;
use PHPolygon\Editor\Scene\PrefabBaseline;
use RuntimeException;

/**
 * What a prefab produces on its own, so the inspector can tell an instance's
 * overrides from the values it merely inherits.
 *
 * Accepts either a prefab class directly, or an entity name whose prefab
 * reference is looked up in the active scene.
 *
 * `available: false` means the prefab could not be built here (its build()
 * needs more than a headless engine). The inspector then shows the instance
 * without override marks rather than guessing.
 *
 * args: { class?: string, entity?: string }
 */
class GetPrefabBaselineCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $class = is_string($this->args['class'] ?? null) ? $this->args['class'] : '';

        if ($class === '') {
            $class = $this->prefabOfEntity($context);
        }

        if ($class === '') {
            throw new RuntimeException("Missing 'class' or 'entity' argument");
        }

        $baseline = PrefabBaseline::for($class);
        if ($baseline === null) {
            return ['class' => $class, 'available' => false, 'components' => []];
        }

        return [
            'class' => $class,
            'available' => true,
            // Same nested {_class, properties} shape the inspector consumes for
            // an entity's own components.
            'components' => EntityFormatter::nestComponents([
                ['name' => 'baseline', 'components' => array_values($baseline)],
            ])[0]['components'],
        ];
    }

    private function prefabOfEntity(EditorContext $context): string
    {
        $entityName = is_string($this->args['entity'] ?? null) ? $this->args['entity'] : '';
        if ($entityName === '') {
            return '';
        }

        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $entity = $doc->getEntity($entityName);
        if ($entity === null) {
            throw new RuntimeException("Entity not found: {$entityName}");
        }

        return is_string($entity['prefab'] ?? null) ? $entity['prefab'] : '';
    }
}
