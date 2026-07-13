<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Inspector;

class PropertySchema
{
    public function __construct(
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly mixed $default,
        public readonly ?string $editorHint,
        public readonly ?string $description,
        /** @var array<string, float>|null */
        public readonly ?array $range,
        /**
         * For array properties of nested #[Serializable] objects, the fully
         * qualified class name of the element (from #[Property(type: ...)]).
         * Drives the inspector's nested object-array editor.
         */
        public readonly ?string $elementType = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'displayName' => $this->displayName,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'editorHint' => $this->editorHint,
            'description' => $this->description,
            'range' => $this->range,
            'elementType' => $this->elementType,
        ], fn ($v) => $v !== null);
    }
}
