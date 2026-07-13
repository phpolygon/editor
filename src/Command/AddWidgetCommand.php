<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\UI\WidgetCatalog;
use RuntimeException;

/**
 * Add a new widget of the given type under a parent widget in the active
 * UI layout.
 */
class AddWidgetCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc === null) {
            throw new RuntimeException('No active UI layout');
        }

        $parentId = is_string($this->args['parentId'] ?? null) ? $this->args['parentId'] : '';
        $type = is_string($this->args['type'] ?? null) ? $this->args['type'] : '';

        $catalog = new WidgetCatalog;
        if (! $catalog->isSupported($type)) {
            throw new RuntimeException("Unknown widget type: {$type}");
        }

        $id = $doc->addWidget($parentId, $catalog->defaultNode($type));
        $context->persistActiveWidgetDocument();

        return ['added' => $id] + $doc->toArray();
    }
}
