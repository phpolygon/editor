<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\UI\PanelLayout;
use RuntimeException;

/**
 * Create a new, empty panel layout and make it active.
 */
class CreatePanelLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? trim($this->args['name']) : '';
        if ($name === '' || preg_replace('/[^A-Za-z0-9_\-]/', '', $name) !== $name) {
            throw new RuntimeException('Invalid layout name (use letters, digits, underscore or hyphen)');
        }

        $dir = $context->getPanelLayoutsDir();
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create UI directory: {$dir}");
        }

        $path = Path::join($dir, $name.'.layout.json');
        if (is_file($path)) {
            throw new RuntimeException("Panel layout already exists: {$name}");
        }

        $layout = new PanelLayout([], $name);
        $layout->saveFile($path);
        $context->setActivePanelLayout($layout);

        return $layout->toArray();
    }
}
