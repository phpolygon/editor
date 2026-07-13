<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Set a single property on a widget in the active UI layout.
 */
class UpdateWidgetPropertyCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc === null) {
            throw new RuntimeException('No active UI layout');
        }

        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : '';
        $property = is_string($this->args['property'] ?? null) ? $this->args['property'] : '';
        if ($property === '') {
            throw new RuntimeException("Missing 'property' argument");
        }

        $doc->updateProperty($id, $property, $this->args['value'] ?? null);
        $context->persistActiveWidgetDocument();

        return $doc->toArray();
    }
}
