<?php

declare(strict_types=1);

namespace PHPolygon\Editor\UI;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Texture;

/**
 * A headless {@see Renderer2DInterface} that records the
 * real widget `draw()` calls as a flat list of vector primitives instead of
 * rasterising them. The editor runs a widget tree's genuine layout+draw against
 * this, ships the primitives to the browser, and a canvas replays them — so the
 * editor preview is pixel-faithful to the engine without reimplementing any
 * layout or drawing in TypeScript.
 *
 * Text metrics are inherited from {@see NullRenderer2D} (a `len × size × 0.6`
 * estimate), which is enough for the layout pass to place widgets sensibly.
 *
 * @phpstan-type Primitive array<string, mixed>
 */
final class RecordingRenderer2D extends NullRenderer2D
{
    /** @var list<array<string, mixed>> */
    private array $primitives = [];

    /** @return list<array<string, mixed>> */
    public function getPrimitives(): array
    {
        return $this->primitives;
    }

    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
    {
        $this->primitives[] = ['op' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $this->rgba($color)];
    }

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void
    {
        $this->primitives[] = ['op' => 'rectOutline', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $this->rgba($color), 'lineWidth' => $lineWidth];
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
    {
        $this->primitives[] = ['op' => 'roundRect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'radius' => $radius, 'color' => $this->rgba($color)];
    }

    public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void
    {
        $this->primitives[] = ['op' => 'roundRectOutline', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'radius' => $radius, 'color' => $this->rgba($color), 'lineWidth' => $lineWidth];
    }

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void
    {
        $this->primitives[] = ['op' => 'circle', 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'color' => $this->rgba($color)];
    }

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void
    {
        $this->primitives[] = ['op' => 'circleOutline', 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'color' => $this->rgba($color), 'lineWidth' => $lineWidth];
    }

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
    {
        $this->primitives[] = ['op' => 'line', 'x1' => $from->x, 'y1' => $from->y, 'x2' => $to->x, 'y2' => $to->y, 'color' => $this->rgba($color), 'width' => $width];
    }

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void
    {
        $this->primitives[] = ['op' => 'text', 'text' => $text, 'x' => $x, 'y' => $y, 'size' => $size, 'color' => $this->rgba($color), 'align' => 'left'];
    }

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
    {
        $this->primitives[] = ['op' => 'text', 'text' => $text, 'x' => $cx, 'y' => $cy, 'size' => $size, 'color' => $this->rgba($color), 'align' => 'center'];
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $this->primitives[] = ['op' => 'text', 'text' => $text, 'x' => $x, 'y' => $y, 'size' => $size, 'color' => $this->rgba($color), 'align' => 'left', 'breakWidth' => $breakWidth];
    }

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void
    {
        // No texture atlas in the editor preview — mark the sprite's footprint.
        $this->primitives[] = ['op' => 'sprite', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'opacity' => $opacity];
    }

    public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void
    {
        $this->primitives[] = ['op' => 'arc', 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'start' => $startAngle, 'end' => $endAngle, 'color' => $this->rgba($color)];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function rgba(Color $color): array
    {
        return [$color->r, $color->g, $color->b, $color->a];
    }
}
