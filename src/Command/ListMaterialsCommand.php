<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Rendering\MaterialRegistry;

class ListMaterialsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $ids = array_values(array_unique(array_merge(
            MaterialRegistry::ids(),
            ProjectAssetCache::materialIds($context->projectDir),
        )));
        sort($ids);

        return ['materials' => $ids];
    }
}
