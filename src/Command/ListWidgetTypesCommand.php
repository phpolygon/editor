<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\UI\WidgetCatalog;

/**
 * The palette of widget types the editor can add to a UI layout.
 */
class ListWidgetTypesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        return ['types' => (new WidgetCatalog)->types()];
    }
}
