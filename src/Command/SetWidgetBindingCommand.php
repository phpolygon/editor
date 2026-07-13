<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Bind a widget property to a context path, or clear the binding.
 *
 * args: { id, property, path }. A null/absent `path` clears the binding, so the
 * property returns to its literal value / default.
 */
class SetWidgetBindingCommand implements CommandInterface
{
    /** @param  array<string, mixed>  $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
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
        $path = is_string($this->args['path'] ?? null) ? $this->args['path'] : null;

        $doc->setBinding($id, $property, $path);
        $context->persistActiveWidgetDocument();

        return $doc->toArray();
    }
}
