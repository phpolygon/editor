<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\MaterialRegistry;
use RuntimeException;

class GetMaterialCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : null;
        if ($id === null || $id === '') {
            throw new RuntimeException("Missing 'id' argument");
        }

        $material = MaterialRegistry::get($id);
        if ($material === null) {
            throw new RuntimeException("Unknown material: {$id}");
        }

        return [
            'id' => $id,
            'albedo' => $this->colorToArray($material->albedo),
            'roughness' => $material->roughness,
            'metallic' => $material->metallic,
            'emission' => $this->colorToArray($material->emission),
            'alpha' => $material->alpha,
            'shader' => $material->shader,
            'albedoTexture' => $material->albedoTexture,
            'clearcoat' => $material->clearcoat,
            'clearcoatRoughness' => $material->clearcoatRoughness,
            'normalIntensity' => $material->normalIntensity,
            'useEnvironmentMap' => $material->useEnvironmentMap,
            'normalPattern' => $material->normalPattern,
            'surfacePattern' => $material->surfacePattern,
        ];
    }

    /** @return array{r: float, g: float, b: float, a: float} */
    private function colorToArray(Color $color): array
    {
        return ['r' => $color->r, 'g' => $color->g, 'b' => $color->b, 'a' => $color->a];
    }
}
