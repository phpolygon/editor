<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Remove an element from the active panel layout.
 */
class RemoveLayoutElementCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $layout = $context->getActivePanelLayout();
        if ($layout === null) {
            throw new RuntimeException('No active panel layout');
        }

        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : '';
        if (! $layout->has($id)) {
            throw new RuntimeException("Element not found: {$id}");
        }

        $layout->remove($id);
        $context->persistActivePanelLayout();

        return $layout->toArray();
    }
}
