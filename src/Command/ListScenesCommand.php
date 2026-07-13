<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\SceneClassResolver;

class ListScenesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $scenesDir = $context->getScenesDir();
        $scenes = [];

        if (is_dir($scenesDir)) {
            $iterator = new \DirectoryIterator($scenesDir);
            foreach ($iterator as $file) {
                if ($file->isDot() || ! $file->isFile()) {
                    continue;
                }

                // Exported JSON snapshots (`<name>.scene.json`) load as data.
                $filename = $file->getFilename();
                if (str_ends_with($filename, '.scene.json')) {
                    $scenes[] = substr($filename, 0, -strlen('.scene.json'));

                    continue;
                }

                // PHP scenes must be concrete, loadable Scene subclasses.
                if ($file->getExtension() === 'php' && $this->isScene($file->getPathname(), $context)) {
                    $scenes[] = $file->getBasename('.php');
                }
            }
            $scenes = array_values(array_unique($scenes));
            sort($scenes);
        }

        return ['scenes' => $scenes];
    }

    /**
     * Only list files that declare a loadable scene. Non-scene helpers that
     * happen to sit in the scenes folder (abstract bases, static renderers)
     * are skipped so they never appear as selectable scenes. A file that
     * fails to load (parse error, missing dependency) is skipped rather than
     * breaking the whole listing.
     */
    private function isScene(string $sceneFile, EditorContext $context): bool
    {
        try {
            require_once $sceneFile;
            $class = SceneClassResolver::classFor($sceneFile, $context);

            return SceneClassResolver::isLoadableScene($class);
        } catch (\Throwable) {
            return false;
        }
    }
}
