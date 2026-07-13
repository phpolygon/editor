<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

/**
 * List the data-driven panel layouts (`*.layout.json`) in the project.
 */
class ListPanelLayoutsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $dir = $context->getPanelLayoutsDir();
        $layouts = [];

        if (is_dir($dir)) {
            foreach (new \DirectoryIterator($dir) as $file) {
                if ($file->isDot() || ! $file->isFile()) {
                    continue;
                }
                $filename = $file->getFilename();
                if (str_ends_with($filename, '.layout.json')) {
                    $layouts[] = substr($filename, 0, -strlen('.layout.json'));
                }
            }
            sort($layouts);
        }

        return ['layouts' => $layouts];
    }
}
