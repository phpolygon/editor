<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class GetComponentSchemaCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        // The frontend bridge sends `className`; accept `class` too for callers
        // using the shorter key.
        $class = $this->args['className'] ?? $this->args['class'] ?? null;
        if (! is_string($class) || $class === '') {
            throw new RuntimeException("Missing 'className' argument");
        }

        return $context->components->get($class)->toArray();
    }
}
