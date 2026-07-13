<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Editor\UI\PlaceholderWidgetContext;
use PHPolygon\Editor\UI\RecordingRenderer2D;
use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Repeater;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetBinder;
use PHPolygon\UI\Widget\WidgetSerializer;
use RuntimeException;

/**
 * Render a widget tree to a flat draw list for the editor's WYSIWYG canvas.
 *
 * Runs the tree's real engine layout + draw against a {@see RecordingRenderer2D},
 * bound to a {@see PlaceholderWidgetContext} so bindings show as readable
 * placeholders and repeaters show sample rows. The result is a list of vector
 * primitives the browser replays — the "see the panel unfilled" view.
 *
 * Renders the active widget document when there is one; otherwise loads the
 * named `*.ui.json` from the project's UI directory.
 */
class RenderUiLayoutCommand implements CommandInterface
{
    /** @param  array<string, mixed>  $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $width = $this->floatArg('width', 1280.0);
        $height = $this->floatArg('height', 720.0);

        $data = $this->resolveTreeData($context);
        $root = (new WidgetSerializer)->fromArray($data);

        // Bind to placeholders (paths for values, N blank rows per repeater).
        $collectionPaths = [];
        $this->collectRepeaterPaths($root, $collectionPaths);
        (new WidgetBinder)->bind($root, new PlaceholderWidgetContext($collectionPaths));

        // Real engine layout + draw, captured as vector primitives.
        $style = UIStyle::dark();
        $recorder = new RecordingRenderer2D((int) $width, (int) $height);
        $root->measure($width, $height, $style);
        $root->setBounds(new Rect(0, 0, $width, $height));
        $root->layout($style);
        $recorder->setFont($style->fontName);
        $root->draw($recorder, $style);

        return [
            'width' => $width,
            'height' => $height,
            'primitives' => $recorder->getPrimitives(),
        ];
    }

    /**
     * The widget-tree array to render: the active document, or a named layout.
     *
     * @return array<string, mixed>
     */
    private function resolveTreeData(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc !== null) {
            return $doc->toFileArray();
        }

        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($name === '') {
            throw new RuntimeException('No active UI layout and no "name" given to render');
        }
        $path = Path::join($context->getUiDir(), $name.'.ui.json');
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("UI layout not found or invalid: {$name}");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Collect the collection paths of every repeater in the (unexpanded) tree so
     * the placeholder context knows which paths to answer with sample rows.
     *
     * @param  list<string>  $paths
     */
    private function collectRepeaterPaths(Widget $widget, array &$paths): void
    {
        if ($widget instanceof Repeater && $widget->each !== '') {
            $paths[] = $widget->each;
        }
        foreach ($widget->getChildren() as $child) {
            $this->collectRepeaterPaths($child, $paths);
        }
    }

    private function floatArg(string $key, float $default): float
    {
        $v = $this->args[$key] ?? null;

        return is_numeric($v) ? (float) $v : $default;
    }
}
