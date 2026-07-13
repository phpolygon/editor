<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Remove a widget (and its subtree) from the active UI layout.
 */
class RemoveWidgetCommand implements CommandInterface
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
        $doc->removeWidget($id);
        $context->persistActiveWidgetDocument();

        return $doc->toArray();
    }
}
