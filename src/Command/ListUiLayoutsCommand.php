<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

/**
 * List the UI layouts (`*.ui.json`) in the project's UI directory.
 */
class ListUiLayoutsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $dir = $context->getUiDir();
        $layouts = [];

        if (is_dir($dir)) {
            foreach (new \DirectoryIterator($dir) as $file) {
                if ($file->isDot() || ! $file->isFile()) {
                    continue;
                }
                $filename = $file->getFilename();
                if (str_ends_with($filename, '.ui.json')) {
                    $layouts[] = substr($filename, 0, -strlen('.ui.json'));
                }
            }
            sort($layouts);
        }

        return ['layouts' => $layouts];
    }
}
