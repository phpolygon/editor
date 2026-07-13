<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Move a widget under a new parent (optionally at a specific index) in the
 * active UI layout.
 */
class ReparentWidgetCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc === null) {
            throw new RuntimeException('No active UI layout');
        }

        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : '';
        $newParentId = is_string($this->args['newParentId'] ?? null) ? $this->args['newParentId'] : '';
        $index = isset($this->args['index']) && is_numeric($this->args['index'])
            ? (int) $this->args['index']
            : null;

        $doc->reparentWidget($id, $newParentId, $index);
        $context->persistActiveWidgetDocument();

        return $doc->toArray();
    }
}
