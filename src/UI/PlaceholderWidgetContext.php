<?php

declare(strict_types=1);

namespace PHPolygon\Editor\UI;

use PHPolygon\UI\Widget\WidgetContext;

/**
 * The {@see WidgetContext} the editor binds a tree to when previewing it
 * "unfilled". Value bindings resolve to a readable placeholder — the binding
 * path in braces, e.g. `{selectedClient.companyName}` — so the author sees both
 * the structure and what each slot is wired to. Collections used by repeaters
 * (their paths are known up front) resolve to a handful of blank sample rows so
 * lists show a representative shape. Writes and actions are inert in preview.
 */
final class PlaceholderWidgetContext implements WidgetContext
{
    /** @param  list<string>  $collectionPaths  paths a Repeater iterates */
    public function __construct(
        private array $collectionPaths,
        private int $sampleRows = 3,
    ) {}

    public function get(string $path): mixed
    {
        if (in_array($path, $this->collectionPaths, true)) {
            return array_fill(0, $this->sampleRows, new \stdClass);
        }

        return '{'.$path.'}';
    }

    public function set(string $path, mixed $value): void
    {
        // inert in preview
    }

    public function call(string $action, array $args = []): void
    {
        // inert in preview
    }
}
