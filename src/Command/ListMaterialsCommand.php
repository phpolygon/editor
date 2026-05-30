<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Rendering\MaterialRegistry;

class ListMaterialsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $ids = MaterialRegistry::ids();
        sort($ids);

        return ['materials' => $ids];
    }
}
