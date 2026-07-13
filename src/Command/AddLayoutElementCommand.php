<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Add a named element (a design-space rectangle) to the active panel layout.
 */
class AddLayoutElementCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $layout = $context->getActivePanelLayout();
        if ($layout === null) {
            throw new RuntimeException('No active panel layout');
        }

        $id = is_string($this->args['id'] ?? null) ? trim($this->args['id']) : '';
        if ($id === '') {
            throw new RuntimeException("Missing element 'id'");
        }
        if ($layout->has($id)) {
            throw new RuntimeException("Element already exists: {$id}");
        }

        $num = fn (string $k, float $d): float => is_numeric($this->args[$k] ?? null) ? (float) $this->args[$k] : $d;
        $layout->set($id, [
            'x' => $num('x', 100.0),
            'y' => $num('y', 100.0),
            'width' => $num('width', 200.0),
            'height' => $num('height', 60.0),
        ]);
        $context->persistActivePanelLayout();

        return ['added' => $id] + $layout->toArray();
    }
}
