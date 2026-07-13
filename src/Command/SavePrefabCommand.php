<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class SavePrefabCommand implements CommandInterface
{
    private const PREFABS_SUBDIR = 'prefabs';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $entityName = is_string($this->args['entityName'] ?? null) ? $this->args['entityName'] : '';
        if ($entityName === '') {
            throw new RuntimeException("Missing 'entityName' argument");
        }

        $entity = $doc->getEntity($entityName);
        if ($entity === null) {
            throw new RuntimeException("Entity not found: {$entityName}");
        }

        $prefabName = is_string($this->args['prefabName'] ?? null) && $this->args['prefabName'] !== ''
            ? $this->args['prefabName']
            : $entityName;

        $sanitized = $this->sanitizeFilename($prefabName);
        if ($sanitized === '') {
            throw new RuntimeException("Invalid prefab name: {$prefabName}");
        }

        $prefabsDir = $context->getAssetsDir().DIRECTORY_SEPARATOR.self::PREFABS_SUBDIR;
        if (! is_dir($prefabsDir) && ! mkdir($prefabsDir, 0o755, true) && ! is_dir($prefabsDir)) {
            throw new RuntimeException("Failed to create prefabs directory: {$prefabsDir}");
        }

        $filePath = $prefabsDir.DIRECTORY_SEPARATOR.$sanitized.'.prefab.json';
        $relativePath = self::PREFABS_SUBDIR.'/'.$sanitized.'.prefab.json';

        $payload = [
            'name' => $sanitized,
            'root' => $this->stripTransientFields($entity),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode prefab as JSON');
        }
        if (file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write prefab file: {$filePath}");
        }

        return [
            'saved' => true,
            'name' => $sanitized,
            'path' => $filePath,
            'relativePath' => $relativePath,
        ];
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    private function stripTransientFields(array $entity): array
    {
        unset($entity['expanded']);
        if (isset($entity['children']) && is_array($entity['children'])) {
            /** @var list<array<string, mixed>> $children */
            $children = $entity['children'];
            $entity['children'] = array_map(fn (array $c) => $this->stripTransientFields($c), $children);
        }

        return $entity;
    }
}
