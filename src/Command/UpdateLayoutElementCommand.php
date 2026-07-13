<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Set properties on an element of the active panel layout (position/size or any
 * data prop like a label/style id).
 */
class UpdateLayoutElementCommand implements CommandInterface
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

        $props = is_array($this->args['props'] ?? null) ? $this->args['props'] : [];
        /** @var array<string, mixed> $props */
        $layout->set($id, $props);
        $context->persistActivePanelLayout();

        return $layout->toArray();
    }
}
