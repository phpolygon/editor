<?php

declare(strict_types=1);

namespace PHPolygon\Editor\UI;

use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\HBox;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Spacer;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetSerializer;
use RuntimeException;

/**
 * The palette of widget types the editor can create, and the default
 * serialized node for each. Mirrors the ComponentRegistry's role for ECS
 * components: it tells the frontend what can be added and produces a sane
 * starting node when the user drops a new widget into the tree.
 *
 * Default nodes are produced by serializing a freshly constructed widget, so
 * the catalog never drifts from the engine's own constructor defaults.
 */
final class WidgetCatalog
{
    /** @var array<string, class-string> short type name => widget FQCN */
    private const SUPPORTED = [
        'Panel' => Panel::class,
        'VBox' => VBox::class,
        'HBox' => HBox::class,
        'Label' => Label::class,
        'Button' => Button::class,
        'Spacer' => Spacer::class,
    ];

    /** Types that accept children in the editor's tree. */
    private const CONTAINERS = ['Panel', 'VBox', 'HBox'];

    private WidgetSerializer $serializer;

    public function __construct(?WidgetSerializer $serializer = null)
    {
        $this->serializer = $serializer ?? new WidgetSerializer;
    }

    /**
     * @return list<array{type: string, class: class-string, container: bool, schema: list<array{name: string, kind: string, default: mixed}>}>
     */
    public function types(): array
    {
        $out = [];
        foreach (self::SUPPORTED as $type => $class) {
            $out[] = [
                'type' => $type,
                'class' => $class,
                'container' => in_array($type, self::CONTAINERS, true),
                'schema' => $this->schema($type),
            ];
        }

        return $out;
    }

    /**
     * Editable-property descriptors for a widget type, derived from the full
     * (non-compact) serialization of a default instance. Drives the inspector,
     * which must show every property even when a layout omits ones still at
     * their default.
     *
     * @return list<array{name: string, kind: string, default: mixed}>
     */
    public function schema(string $type): array
    {
        $class = self::SUPPORTED[$type] ?? throw new RuntimeException("Unknown widget type: {$type}");

        $fields = [];
        foreach ($this->serializer->toArray(new $class, compact: false) as $key => $value) {
            if ($key === '_widget' || $key === 'children') {
                continue;
            }
            $fields[] = ['name' => $key, 'kind' => $this->kindOf($value), 'default' => $value];
        }

        return $fields;
    }

    private function kindOf(mixed $value): string
    {
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value)) {
            if (isset($value['r'], $value['g'], $value['b'])) {
                return 'color';
            }
            if (isset($value['top'], $value['right'], $value['bottom'], $value['left'])) {
                return 'edgeinsets';
            }
            if (array_key_exists('fillWidth', $value)) {
                return 'sizing';
            }
            if (isset($value['x'], $value['y'], $value['width'], $value['height'])) {
                return 'rect';
            }
            if (isset($value['x'], $value['y'])) {
                return 'vec2';
            }
        }

        return 'other';
    }

    public function isSupported(string $type): bool
    {
        return isset(self::SUPPORTED[$type]);
    }

    public function isContainer(string $type): bool
    {
        return in_array($type, self::CONTAINERS, true);
    }

    /**
     * A fresh serialized node for the given type, carrying the engine's
     * constructor defaults (no editor `_id` — the document assigns that).
     *
     * @return array<string, mixed>
     */
    public function defaultNode(string $type): array
    {
        $class = self::SUPPORTED[$type] ?? throw new RuntimeException("Unknown widget type: {$type}");

        return $this->serializer->toArray(new $class);
    }
}
