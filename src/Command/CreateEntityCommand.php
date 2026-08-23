<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class CreateEntityCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $requested = is_string($this->args['name'] ?? null) ? $this->args['name'] : 'NewEntity';
        $parent = is_string($this->args['parent'] ?? null) ? $this->args['parent'] : null;

        // Commands address entities by name, so a duplicate would make one of
        // the two unreachable.
        $name = $doc->uniqueName($requested);

        $doc->addEntity($name, $parent);

        return ['created' => $name, 'parent' => $parent];
    }
}
