<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist a generated shader: the GLSL fragment source as
 * `assets/shaders/<name>.frag.glsl` (a real GLSL file the engine's shader
 * pipeline can compile/transpile) plus the authoring graph as
 * `<name>.shader.json` so it can be reopened in the editor.
 */
class SaveShaderCommand implements CommandInterface
{
    private const SHADERS_SUBDIR = 'shaders';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException("Invalid shader name: {$name}");
        }

        $glsl = is_string($this->args['glsl'] ?? null) ? $this->args['glsl'] : '';
        if (trim($glsl) === '') {
            throw new RuntimeException('Shader GLSL is empty');
        }

        $dir = Path::join($context->getAssetsDir(), self::SHADERS_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create shaders directory: {$dir}");
        }

        $glslPath = Path::join($dir, $sanitized.'.frag.glsl');
        if (file_put_contents($glslPath, $glsl) === false) {
            throw new RuntimeException("Failed to write shader: {$glslPath}");
        }

        // Keep the authoring graph alongside so the shader can be reopened.
        if (is_array($this->args['graph'] ?? null)) {
            $graphJson = json_encode($this->args['graph'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($graphJson !== false) {
                file_put_contents(Path::join($dir, $sanitized.'.shader.json'), $graphJson);
            }
        }

        return [
            'saved' => true,
            'name' => $sanitized,
            'path' => $glslPath,
            'relativePath' => self::SHADERS_SUBDIR.'/'.$sanitized.'.frag.glsl',
        ];
    }
}
