<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Write several component properties — across one or more entities — as ONE
 * undoable step.
 *
 * {@see UpdatePropertyCommand} handles a single field, which is right for an
 * inspector edit but wrong for a gizmo drag: that rewrites position, rotation
 * and scale together, and three separate commands leave three undo entries, so
 * one ctrl+Z undoes only part of the drag. The `edits` shape also covers a
 * multi-entity transform in a single request instead of 3xN round-trips.
 *
 * args: { edits: [ { entity: string, component: string,
 *                    properties: { <name>: <value>, ... } }, ... ] }
 */
class UpdatePropertiesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{updated: int} */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $raw = $this->args['edits'] ?? null;
        if (! is_array($raw) || $raw === []) {
            throw new RuntimeException("Missing 'edits' argument");
        }

        $edits = [];
        foreach (array_values($raw) as $i => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("edits[{$i}] must be an object");
            }

            $entity = is_string($entry['entity'] ?? null) ? $entry['entity'] : '';
            $component = is_string($entry['component'] ?? null) ? $entry['component'] : '';
            if ($entity === '' || $component === '') {
                throw new RuntimeException("edits[{$i}] is missing 'entity' or 'component'");
            }

            $properties = $entry['properties'] ?? null;
            if (! is_array($properties)) {
                throw new RuntimeException("edits[{$i}] is missing a 'properties' object");
            }

            // Only string keys are component property names; a JSON array would
            // silently write "0", "1", ... onto the component.
            $clean = [];
            foreach ($properties as $property => $value) {
                if (! is_string($property) || $property === '') {
                    throw new RuntimeException("edits[{$i}] has a non-string property name");
                }
                $clean[$property] = $value;
            }

            if ($clean === []) {
                continue;
            }

            $edits[] = ['entity' => $entity, 'component' => $component, 'properties' => $clean];
        }

        $doc->applyPropertyEdits($edits);

        return ['updated' => count($edits)];
    }
}
