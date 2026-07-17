<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Persist a generated sound effect (base64-encoded WAV from the wave
 * synthesizer) into the project's assets under `audio/<name>.wav`. Mirrors
 * {@see SavePrefabCommand}: sanitize the name, ensure the subdir, write the
 * bytes, return absolute + portable relative paths.
 */
class SaveAudioCommand implements CommandInterface
{
    private const AUDIO_SUBDIR = 'audio';

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
            throw new RuntimeException("Invalid audio name: {$name}");
        }

        $binary = $this->decodeData(is_string($this->args['data'] ?? null) ? $this->args['data'] : '');

        $dir = Path::join($context->getAssetsDir(), self::AUDIO_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create audio directory: {$dir}");
        }

        $filePath = Path::join($dir, $sanitized.'.wav');
        $relativePath = self::AUDIO_SUBDIR.'/'.$sanitized.'.wav';

        if (file_put_contents($filePath, $binary) === false) {
            throw new RuntimeException("Failed to write audio file: {$filePath}");
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
        // Accept a bare base64 string or a data URL ("data:audio/wav;base64,…").
        if (str_contains($data, ',')) {
            $data = substr($data, strpos($data, ',') + 1);
        }

        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Invalid or empty audio data');
        }

        return $binary;
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
    }
}
