<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use RuntimeException;

/**
 * Create a 2D entity: a {@see Transform2D} plus a
 * {@see SpriteRenderer}. This is the 2D counterpart to
 * {@see CreatePrimitiveCommand} — it gives the 2D viewport something visible
 * (a 64×64 white quad by default) to place and edit.
 */
class CreateSpriteCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $baseName = is_string($this->args['name'] ?? null) && $this->args['name'] !== ''
            ? $this->args['name']
            : 'Sprite';
        $parent = is_string($this->args['parent'] ?? null) ? $this->args['parent'] : null;

        $name = $this->uniqueName($doc, $baseName);

        $doc->addEntity($name, $parent);

        $doc->addComponent($name, 'PHPolygon\\Component\\Transform2D', [
            'position' => ['x' => 0.0, 'y' => 0.0],
            'rotation' => 0.0,
            'scale' => ['x' => 1.0, 'y' => 1.0],
        ]);

        $doc->addComponent($name, 'PHPolygon\\Component\\SpriteRenderer', [
            'textureId' => '',
            'color' => ['r' => 1.0, 'g' => 1.0, 'b' => 1.0, 'a' => 1.0],
            'layer' => 0,
            'flipX' => false,
            'flipY' => false,
            'opacity' => 1.0,
            'width' => 64,
            'height' => 64,
        ]);

        return [
            'created' => $name,
            'parent' => $parent,
        ];
    }

    private function uniqueName(SceneDocument $doc, string $base): string
    {
        if ($doc->getEntity($base) === null) {
            return $base;
        }
        $i = 2;
        while ($doc->getEntity($base.'_'.$i) !== null) {
            $i++;
        }

        return $base.'_'.$i;
    }
}
