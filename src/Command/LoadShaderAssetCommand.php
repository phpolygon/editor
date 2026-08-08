<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Load a saved shader's authoring graph (`assets/shaders/<name>.shader.json`)
 * back into the shader editor — the counterpart to {@see SaveShaderCommand},
 * which writes the graph next to the generated GLSL for exactly this.
 *
 * The name is sanitized and resolved inside the shaders dir, so it can't
 * traverse outside the project.
 */
class LoadShaderAssetCommand implements CommandInterface
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

        $file = Path::join($context->getAssetsDir(), self::SHADERS_SUBDIR, $sanitized.'.shader.json');
        if (! is_file($file)) {
            throw new RuntimeException("Shader graph not found: {$sanitized}");
        }

        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid shader JSON: {$sanitized}");
        }

        return [
            'name' => $sanitized,
            'graph' => $data,
        ];
    }
}
