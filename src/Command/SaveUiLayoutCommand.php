<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist the active UI layout to its `*.ui.json` file.
 */
class SaveUiLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc === null) {
            throw new RuntimeException('No active UI layout');
        }

        $dir = $context->getUiDir();
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create UI directory: {$dir}");
        }

        $path = Path::join($dir, $doc->getName().'.ui.json');
        $json = json_encode($doc->toFileArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new RuntimeException("Failed to write UI layout: {$path}");
        }

        return ['saved' => $path, 'name' => $doc->getName()];
    }
}
