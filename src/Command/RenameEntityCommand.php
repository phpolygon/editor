<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class RenameEntityCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $oldName = is_string($this->args['oldName'] ?? null) ? $this->args['oldName'] : null;
        $newName = is_string($this->args['newName'] ?? null) ? $this->args['newName'] : null;

        if ($oldName === null || $newName === null) {
            throw new RuntimeException("Missing 'oldName' or 'newName' argument");
        }

        if ($doc->getEntity($oldName) === null) {
            throw new RuntimeException("Entity not found: {$oldName}");
        }

        // Renaming onto an existing name would leave one of the two entities
        // unaddressable, so the new name is made unique instead. Renaming an
        // entity to what it already is stays a no-op rather than becoming
        // "Name_2".
        $finalName = $oldName === $newName ? $newName : $doc->uniqueName($newName);

        $doc->renameEntity($oldName, $finalName);

        return ['oldName' => $oldName, 'newName' => $finalName];
    }
}
