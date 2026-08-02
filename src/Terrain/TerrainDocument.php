<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Terrain;

use PHPolygon\Component\Terrain;
use PHPolygon\Terrain\HeightmapData;
use RuntimeException;

/**
 * In-memory model of a `.terrain.json` asset: the heightmap plus the texture
 * layers and scatter definitions painted on top of it.
 *
 * This is the editor's authoring format. The heightmap payload is byte-for-byte
 * the same base64 uint16 encoding the engine's {@see Terrain}
 * stores inline, so publishing a terrain into a scene is a copy rather than a
 * conversion, and a terrain can be round-tripped back into the editor from a
 * scene without loss.
 *
 * Splat and density payloads are grid-aligned with the heightmap: one byte per
 * grid sample per layer (splat) or per scatter set (density), row-major with Z
 * as the outer axis. Weights are stored as raw bytes rather than normalised
 * floats because painting is inherently 8-bit — a brush writes coverage, and
 * 256 levels of coverage is past what is visible after blending.
 */
final class TerrainDocument
{
    public const VERSION = 1;

    /** @param list<TerrainLayer> $layers
     *  @param list<TerrainScatter> $scatter */
    private function __construct(
        public string $name,
        public int $gridWidth,
        public int $gridDepth,
        public float $sizeX,
        public float $sizeZ,
        public float $minHeight,
        public float $maxHeight,
        public string $heights,
        public int $chunkSize,
        public string $materialId,
        public array $layers,
        public string $splat,
        public array $scatter,
    ) {}

    public static function create(
        string $name,
        int $gridWidth = 129,
        int $gridDepth = 129,
        float $sizeX = 256.0,
        float $sizeZ = 256.0,
        float $minHeight = 0.0,
        float $maxHeight = 50.0,
        int $chunkSize = 32,
    ): self {
        [$gridWidth, $gridDepth] = self::assertGrid($gridWidth, $gridDepth);
        [$minHeight, $maxHeight] = self::assertHeightRange($minHeight, $maxHeight);

        return new self(
            $name,
            $gridWidth,
            $gridDepth,
            self::assertExtent($sizeX, 'sizeX'),
            self::assertExtent($sizeZ, 'sizeZ'),
            $minHeight,
            $maxHeight,
            HeightmapData::flat($gridWidth, $gridDepth, $sizeX, $sizeZ, $minHeight, $maxHeight)->encode(),
            max(1, $chunkSize),
            '',
            [],
            '',
            [],
        );
    }

    /**
     * Parse a decoded `.terrain.json` payload.
     *
     * Unknown keys are ignored and missing ones fall back to defaults, so an
     * asset written by an older editor build still loads. Structurally invalid
     * data (a payload whose length contradicts the declared grid) is rejected
     * rather than silently reinterpreted — that mismatch means the file is
     * corrupt, and guessing would destroy the user's sculpt on the next save.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $fallbackName = 'terrain'): self
    {
        $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : $fallbackName;

        [$gridWidth, $gridDepth] = self::assertGrid(
            self::intOr($data['gridWidth'] ?? null, 129),
            self::intOr($data['gridDepth'] ?? null, 129),
        );
        [$minHeight, $maxHeight] = self::assertHeightRange(
            self::floatOr($data['minHeight'] ?? null, 0.0),
            self::floatOr($data['maxHeight'] ?? null, 50.0),
        );

        $sizeX = self::assertExtent(self::floatOr($data['sizeX'] ?? null, 256.0), 'sizeX');
        $sizeZ = self::assertExtent(self::floatOr($data['sizeZ'] ?? null, 256.0), 'sizeZ');
        $samples = $gridWidth * $gridDepth;

        $heights = is_string($data['heights'] ?? null) ? $data['heights'] : '';
        if ($heights !== '' && self::decodedLength($heights) !== $samples * 2) {
            throw new RuntimeException(
                "Terrain '{$name}': heightmap payload does not match its {$gridWidth}x{$gridDepth} grid"
            );
        }
        if ($heights === '') {
            $heights = HeightmapData::flat($gridWidth, $gridDepth, $sizeX, $sizeZ, $minHeight, $maxHeight)->encode();
        }

        $layers = [];
        foreach (is_array($data['layers'] ?? null) ? $data['layers'] : [] as $layer) {
            if (is_array($layer)) {
                $layers[] = TerrainLayer::fromArray($layer);
            }
        }

        $splat = is_string($data['splat'] ?? null) ? $data['splat'] : '';
        if ($splat !== '' && self::decodedLength($splat) !== $samples * count($layers)) {
            throw new RuntimeException(
                "Terrain '{$name}': splat payload does not match ".count($layers)." layers on a {$gridWidth}x{$gridDepth} grid"
            );
        }

        $scatter = [];
        foreach (is_array($data['scatter'] ?? null) ? $data['scatter'] : [] as $set) {
            if (is_array($set)) {
                $scatter[] = TerrainScatter::fromArray($set, $samples);
            }
        }

        return new self(
            $name,
            $gridWidth,
            $gridDepth,
            $sizeX,
            $sizeZ,
            $minHeight,
            $maxHeight,
            $heights,
            max(1, self::intOr($data['chunkSize'] ?? null, 32)),
            is_string($data['materialId'] ?? null) ? $data['materialId'] : '',
            $layers,
            $splat,
            $scatter,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => self::VERSION,
            'gridWidth' => $this->gridWidth,
            'gridDepth' => $this->gridDepth,
            'sizeX' => $this->sizeX,
            'sizeZ' => $this->sizeZ,
            'minHeight' => $this->minHeight,
            'maxHeight' => $this->maxHeight,
            'heights' => $this->heights,
            'chunkSize' => $this->chunkSize,
            'materialId' => $this->materialId,
            'layers' => array_map(static fn (TerrainLayer $l): array => $l->toArray(), $this->layers),
            'splat' => $this->splat,
            'scatter' => array_map(static fn (TerrainScatter $s): array => $s->toArray(), $this->scatter),
        ];
    }

    /** Decode the heightmap payload into the engine's heightmap type. */
    public function toHeightmap(): HeightmapData
    {
        return HeightmapData::decode(
            $this->heights,
            $this->gridWidth,
            $this->gridDepth,
            $this->sizeX,
            $this->sizeZ,
            $this->minHeight,
            $this->maxHeight,
        );
    }

    /** Number of height samples in the grid. */
    public function sampleCount(): int
    {
        return $this->gridWidth * $this->gridDepth;
    }

    /**
     * Byte length a base64 payload decodes to, without allocating the result
     * for the common case of a well-formed string.
     */
    private static function decodedLength(string $encoded): int
    {
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? -1 : strlen($decoded);
    }

    /** @return array{int, int} */
    private static function assertGrid(int $gridWidth, int $gridDepth): array
    {
        // The upper bound is a guard against a mistyped resolution allocating
        // gigabytes: 1025^2 samples is already ~1M vertices.
        if ($gridWidth < 2 || $gridDepth < 2 || $gridWidth > 1025 || $gridDepth > 1025) {
            throw new RuntimeException("Terrain grid must be between 2x2 and 1025x1025, got {$gridWidth}x{$gridDepth}");
        }

        return [$gridWidth, $gridDepth];
    }

    /** @return array{float, float} */
    private static function assertHeightRange(float $minHeight, float $maxHeight): array
    {
        if ($maxHeight <= $minHeight) {
            throw new RuntimeException("Terrain maxHeight ({$maxHeight}) must be greater than minHeight ({$minHeight})");
        }

        return [$minHeight, $maxHeight];
    }

    private static function assertExtent(float $value, string $label): float
    {
        if ($value <= 0.0) {
            throw new RuntimeException("Terrain {$label} must be positive, got {$value}");
        }

        return $value;
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    private static function floatOr(mixed $value, float $default): float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : $default);
    }
}
