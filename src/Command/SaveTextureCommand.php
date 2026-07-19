<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist an imported texture (base64-encoded PNG, e.g. a glTF base-colour map
 * baked out during mesh import) into the project's assets under
 * `textures/<name>.png`. Mirrors {@see SaveAudioCommand}: sanitize the name,
 * ensure the subdir, write the bytes, return absolute + portable relative paths.
 * The relative path is what a material's `albedoTexture` references.
 */
class SaveTextureCommand implements CommandInterface
{
    private const TEXTURES_SUBDIR = 'textures';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($name === '') {
            throw new RuntimeException("Missing 'name' argument");
        }

        $sanitized = $this->sanitizeFilename($name);
        if ($sanitized === '') {
            throw new RuntimeException("Invalid texture name: {$name}");
        }

        $binary = $this->decodeData(is_string($this->args['data'] ?? null) ? $this->args['data'] : '');

        $dir = Path::join($context->getAssetsDir(), self::TEXTURES_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create textures directory: {$dir}");
        }

        $filePath = Path::join($dir, $sanitized.'.png');
        $relativePath = self::TEXTURES_SUBDIR.'/'.$sanitized.'.png';

        if (file_put_contents($filePath, $binary) === false) {
            throw new RuntimeException("Failed to write texture file: {$filePath}");
        }

        return [
            'saved' => true,
            'name' => $sanitized,
            'path' => $filePath,
            'relativePath' => $relativePath,
        ];
    }

    private function decodeData(string $data): string
    {
        // Accept a bare base64 string or a data URL ("data:image/png;base64,…").
        if (str_contains($data, ',')) {
            $data = substr($data, strpos($data, ',') + 1);
        }

        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Invalid or empty texture data');
        }

        return $binary;
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
    }
}
