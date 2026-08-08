<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;

/**
 * List saved shader assets under `assets/shaders/`, sorted by name.
 *
 * Only shaders that kept their authoring graph (`<name>.shader.json`, written
 * alongside the GLSL by {@see SaveShaderCommand}) are listed: hand-written GLSL
 * has no graph to reopen in the node editor.
 */
class ListShaderAssetsCommand implements CommandInterface
{
    private const SHADERS_SUBDIR = 'shaders';

    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $dir = Path::join($context->getAssetsDir(), self::SHADERS_SUBDIR);
        $shaders = [];

        if (is_dir($dir)) {
            foreach (glob(Path::join($dir, '*.shader.json')) ?: [] as $file) {
                $base = basename($file, '.shader.json');
                $shaders[] = ['name' => $base, 'path' => self::SHADERS_SUBDIR.'/'.$base.'.shader.json'];
            }
            usort($shaders, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }

        return ['shaders' => $shaders];
    }
}
