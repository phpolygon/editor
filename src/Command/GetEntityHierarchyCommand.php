<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\EntityFormatter;
use RuntimeException;

class GetEntityHierarchyCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        // Return full, nested components (same shape as load_scene) so the
        // inspector and viewport keep working after a hierarchy refresh.
        return ['entities' => EntityFormatter::nestComponents($doc->getEntities())];
    }
}
