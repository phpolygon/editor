<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

/**
 * Map a widget event (e.g. 'click', 'change') to a context action, or remove it.
 *
 * args: { id, event, action }. A null/empty `action` removes the mapping.
 */
class SetWidgetEventCommand implements CommandInterface
{
    /** @param  array<string, mixed>  $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveWidgetDocument();
        if ($doc === null) {
            throw new RuntimeException('No active UI layout');
        }

        $id = is_string($this->args['id'] ?? null) ? $this->args['id'] : '';
        $event = is_string($this->args['event'] ?? null) ? $this->args['event'] : '';
        if ($event === '') {
            throw new RuntimeException("Missing 'event' argument");
        }
        $action = is_string($this->args['action'] ?? null) ? $this->args['action'] : null;

        $doc->setEvent($id, $event, $action);
        $context->persistActiveWidgetDocument();

        return $doc->toArray();
    }
}
