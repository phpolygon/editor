<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Editor\UI\WidgetDocument;
use RuntimeException;

/**
 * Load a UI layout from disk and make it the active document.
 */
class LoadUiLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($name === '') {
            throw new RuntimeException("Missing 'name' argument");
        }

        $path = Path::join($context->getUiDir(), $name.'.ui.json');
        if (! is_file($path)) {
            throw new RuntimeException("UI layout not found: {$name}");
        }

        $raw = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid UI layout JSON: {$name}");
        }

        /** @var array<string, mixed> $data */
        $doc = WidgetDocument::fromFileArray($data);
        $context->setActiveWidgetDocument($doc);

        return $doc->toArray();
    }
}
