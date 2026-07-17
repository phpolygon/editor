<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist a generated engine shader: the vertex + fragment GLSL as
 * `assets/shaders/<name>.vert.glsl` / `<name>.frag.glsl` (real GLSL files the
 * engine's shader pipeline compiles/transpiles — OpenGL directly, vio at
 * runtime) plus the authoring graph as `<name>.shader.json` so it can be
 * reopened in the editor.
 *
 * A game registers the pair with
 *   Shader::register('id', new ShaderDefinition('…/id.vert.glsl', '…/id.frag.glsl'))
 * and references it via `new Material(shader: 'id')`.
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

        $vertex = is_string($this->args['vertex'] ?? null) ? $this->args['vertex'] : '';
        $fragment = is_string($this->args['fragment'] ?? null) ? $this->args['fragment'] : '';
        if (trim($vertex) === '' || trim($fragment) === '') {
            throw new RuntimeException('Shader vertex/fragment GLSL is empty');
        }

        $dir = Path::join($context->getAssetsDir(), self::SHADERS_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create shaders directory: {$dir}");
        }

        $vertPath = Path::join($dir, $sanitized.'.vert.glsl');
        $fragPath = Path::join($dir, $sanitized.'.frag.glsl');
        if (file_put_contents($vertPath, $vertex) === false || file_put_contents($fragPath, $fragment) === false) {
            throw new RuntimeException("Failed to write shader files in: {$dir}");
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
            'vertexPath' => self::SHADERS_SUBDIR.'/'.$sanitized.'.vert.glsl',
            'fragmentPath' => self::SHADERS_SUBDIR.'/'.$sanitized.'.frag.glsl',
            'relativePath' => self::SHADERS_SUBDIR.'/'.$sanitized.'.frag.glsl',
        ];
    }
}
