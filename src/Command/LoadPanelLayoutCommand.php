<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\UI\PanelLayout;
use RuntimeException;

/**
 * Load a panel layout from disk and make it active.
 */
class LoadPanelLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($name === '') {
            throw new RuntimeException("Missing 'name' argument");
        }

        $path = Path::join($context->getPanelLayoutsDir(), $name.'.layout.json');
        if (! is_file($path)) {
            throw new RuntimeException("Panel layout not found: {$name}");
        }

        $layout = PanelLayout::loadFile($path);
        $context->setActivePanelLayout($layout);

        return $layout->toArray();
    }
}
