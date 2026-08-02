<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Terrain;

use RuntimeException;

/**
 * One scattered object set on a terrain — trees, grass, rocks.
 *
 * Placements are never stored. What the asset holds is a painted density grid
 * plus a seed and the rules that filter candidates, and the actual instance
 * transforms are derived from those. That keeps the asset small (one byte per
 * grid sample instead of a transform per instance) and, more importantly,
 * keeps scatter *stable*: the same seed and density always produce the same
 * forest, so sculpting a hill re-drapes the trees already on it instead of
 * reshuffling every tree on the map.
 */
final class TerrainScatter
{
    public function __construct(
        public string $id,
        public string $name,
        public string $meshId = '',
        public string $materialId = '',
        public int $seed = 1337,
        /** Instances per world unit squared at full painted density. */
        public float $density = 0.05,
        /** Painted density, one byte per grid sample; empty means none painted. */
        public string $densityMap = '',
        public float $minHeight = 0.0,
        public float $maxHeight = 1.0,
        public float $minSlope = 0.0,
        public float $maxSlope = 30.0,
        public float $minScale = 0.8,
        public float $maxScale = 1.2,
        /** Tilt instances to follow the surface normal instead of standing upright. */
        public bool $alignToNormal = false,
        /** Random yaw applied to each instance, in degrees. */
        public float $randomYaw = 360.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int  $sampleCount  Grid samples the density map must cover.
     */
    public static function fromArray(array $data, int $sampleCount): self
    {
        $id = is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : uniqid('scatter_');
        $densityMap = is_string($data['densityMap'] ?? null) ? $data['densityMap'] : '';

        if ($densityMap !== '') {
            $decoded = base64_decode($densityMap, true);
            if ($decoded === false || strlen($decoded) !== $sampleCount) {
                throw new RuntimeException("Scatter set '{$id}': density map does not match the terrain grid");
            }
        }

        return new self(
            $id,
            is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : $id,
            is_string($data['meshId'] ?? null) ? $data['meshId'] : '',
            is_string($data['materialId'] ?? null) ? $data['materialId'] : '',
            is_int($data['seed'] ?? null) ? $data['seed'] : 1337,
            self::floatOr($data['density'] ?? null, 0.05),
            $densityMap,
            self::floatOr($data['minHeight'] ?? null, 0.0),
            self::floatOr($data['maxHeight'] ?? null, 1.0),
            self::floatOr($data['minSlope'] ?? null, 0.0),
            self::floatOr($data['maxSlope'] ?? null, 30.0),
            self::floatOr($data['minScale'] ?? null, 0.8),
            self::floatOr($data['maxScale'] ?? null, 1.2),
            is_bool($data['alignToNormal'] ?? null) ? $data['alignToNormal'] : false,
            self::floatOr($data['randomYaw'] ?? null, 360.0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'meshId' => $this->meshId,
            'materialId' => $this->materialId,
            'seed' => $this->seed,
            'density' => $this->density,
            'densityMap' => $this->densityMap,
            'minHeight' => $this->minHeight,
            'maxHeight' => $this->maxHeight,
            'minSlope' => $this->minSlope,
            'maxSlope' => $this->maxSlope,
            'minScale' => $this->minScale,
            'maxScale' => $this->maxScale,
            'alignToNormal' => $this->alignToNormal,
            'randomYaw' => $this->randomYaw,
        ];
    }

    private static function floatOr(mixed $value, float $default): float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : $default);
    }
}
