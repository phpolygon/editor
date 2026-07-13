<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Editor\UI\WidgetCatalog;
use PHPolygon\Editor\UI\WidgetDocument;
use RuntimeException;

/**
 * Create a new UI layout with a single root container, write it to disk, and
 * make it the active document.
 */
class CreateUiLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? trim($this->args['name']) : '';
        if ($name === '' || preg_replace('/[^A-Za-z0-9_\-]/', '', $name) !== $name) {
            throw new RuntimeException('Invalid layout name (use letters, digits, underscore or hyphen)');
        }

        $rootType = is_string($this->args['rootType'] ?? null) ? $this->args['rootType'] : 'VBox';
        $catalog = new WidgetCatalog;
        if (! $catalog->isSupported($rootType)) {
            throw new RuntimeException("Unknown widget type: {$rootType}");
        }

        $dir = $context->getUiDir();
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create UI directory: {$dir}");
        }

        $path = Path::join($dir, $name.'.ui.json');
        if (is_file($path)) {
            throw new RuntimeException("UI layout already exists: {$name}");
        }

        $doc = new WidgetDocument($name, $catalog->defaultNode($rootType));
        file_put_contents($path, json_encode($doc->toFileArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $context->setActiveWidgetDocument($doc);

        return $doc->toArray();
    }
}
