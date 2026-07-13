<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Rename an element of the active panel layout.
 */
class RenameLayoutElementCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $layout = $context->getActivePanelLayout();
        if ($layout === null) {
            throw new RuntimeException('No active panel layout');
        }

        $oldId = is_string($this->args['oldId'] ?? null) ? $this->args['oldId'] : '';
        $newId = is_string($this->args['newId'] ?? null) ? trim($this->args['newId']) : '';
        if (! $layout->has($oldId)) {
            throw new RuntimeException("Element not found: {$oldId}");
        }
        if ($newId === '') {
            throw new RuntimeException('New element id must not be empty');
        }
        if ($oldId !== $newId && $layout->has($newId)) {
            throw new RuntimeException("Element already exists: {$newId}");
        }

        $layout->rename($oldId, $newId);
        $context->persistActivePanelLayout();

        return ['renamed' => $newId] + $layout->toArray();
    }
}
