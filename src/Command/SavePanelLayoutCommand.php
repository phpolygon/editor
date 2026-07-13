<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist the active panel layout to its `*.layout.json` file.
 */
class SavePanelLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $layout = $context->getActivePanelLayout();
        if ($layout === null) {
            throw new RuntimeException('No active panel layout');
        }

        $dir = $context->getPanelLayoutsDir();
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create UI directory: {$dir}");
        }

        $path = Path::join($dir, $layout->getName().'.layout.json');
        $layout->saveFile($path);

        return ['saved' => $path, 'name' => $layout->getName()];
    }
}
