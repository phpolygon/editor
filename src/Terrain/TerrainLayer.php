<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Terrain;

/**
 * One texture layer of a terrain splat map.
 *
 * A layer pairs a material with the rules that decide where it appears. The
 * rules are authoring aids, not runtime state: "apply by rules" fills the
 * layer's splat channel from height and slope, and the artist then paints
 * corrections by hand. Keeping the rules in the asset means that fill can be
 * re-run after sculpting without the artist re-entering the thresholds.
 *
 * Slopes are stored in degrees from horizontal (0 = flat ground, 90 = a cliff
 * face) because that is what the brush UI shows.
 */
final class TerrainLayer
{
    public function __construct(
        public string $id,
        public string $name,
        public string $materialId = '',
        public float $uvScale = 16.0,
        public float $minHeight = 0.0,
        public float $maxHeight = 1.0,
        public float $minSlope = 0.0,
        public float $maxSlope = 90.0,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : uniqid('layer_');

        return new self(
            $id,
            is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : $id,
            is_string($data['materialId'] ?? null) ? $data['materialId'] : '',
            self::floatOr($data['uvScale'] ?? null, 16.0),
            self::floatOr($data['minHeight'] ?? null, 0.0),
            self::floatOr($data['maxHeight'] ?? null, 1.0),
            self::floatOr($data['minSlope'] ?? null, 0.0),
            self::floatOr($data['maxSlope'] ?? null, 90.0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'materialId' => $this->materialId,
            'uvScale' => $this->uvScale,
            'minHeight' => $this->minHeight,
            'maxHeight' => $this->maxHeight,
            'minSlope' => $this->minSlope,
            'maxSlope' => $this->maxSlope,
        ];
    }

    private static function floatOr(mixed $value, float $default): float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : $default);
    }
}
