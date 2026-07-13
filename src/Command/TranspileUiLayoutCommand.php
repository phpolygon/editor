<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\SceneClassResolver;
use PHPolygon\Editor\Support\Path;
use PHPolygon\UI\Widget\WidgetCodeGenerator;
use RuntimeException;

/**
 * Transpile the active UI layout (the editor-authored widget tree) into a PHP
 * `*Layout` class with a static `build(): Widget` factory — the zero-parse
 * runtime artifact. The `.ui.json` stays the editable source.
 */
class TranspileUiLayoutCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc === null) {
            throw new RuntimeException('No active UI layout');
        }

        $base = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $doc->getName())));
        $className = $base.'Layout';

        $uiDir = $context->getUiDir();
        $namespace = SceneClassResolver::namespaceFor(Path::join($uiDir, $className.'.php'), $context);

        /** @var array<string, mixed> $root */
        $root = $doc->toFileArray()['root'] ?? [];
        $php = (new WidgetCodeGenerator)->generate($root, $className, $namespace);

        if (! is_dir($uiDir) && ! mkdir($uiDir, 0o755, true) && ! is_dir($uiDir)) {
            throw new RuntimeException("Failed to create UI directory: {$uiDir}");
        }

        $path = Path::join($uiDir, $className.'.php');
        if (file_put_contents($path, $php) === false) {
            throw new RuntimeException("Failed to write UI layout PHP: {$path}");
        }

        return ['path' => $path, 'className' => $className, 'php' => $php];
    }
}
