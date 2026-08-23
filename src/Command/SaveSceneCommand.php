<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\DroppedPropertyDetector;
use PHPolygon\Editor\Scene\PrefabFlattener;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Write the active scene to disk.
 *
 * `flatten: true` expands prefab references into the entities they produce
 * instead of writing the references. It is a projection of the document, never
 * a change to it: the document keeps its references, so the editor still shows
 * instances and a later plain save restores the compact form. Use it for a
 * build that should not re-run every prefab's build() at start-up, or for a
 * scene handed somewhere the prefab sources are not.
 */
class SaveSceneCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $data = $doc->toArray();
        $sceneName = is_string($data['name'] ?? null) ? $data['name'] : 'untitled';

        $flatten = ($this->args['flatten'] ?? false) === true;
        $flattened = null;
        if ($flatten) {
            /** @var list<array<string, mixed>> $entities */
            $entities = is_array($data['entities'] ?? null) ? array_values($data['entities']) : [];
            $flattened = PrefabFlattener::flatten($entities);
            $data['entities'] = $flattened['entities'];
        }
        $scenesDir = $context->getScenesDir();

        if (! is_dir($scenesDir)) {
            mkdir($scenesDir, 0755, true);
        }

        // A scene loaded from an exported JSON snapshot is saved back as JSON,
        // not regenerated as a PHP class. PHP scenes stay the canonical source.
        $jsonPath = Path::join($scenesDir, $sceneName.'.scene.json');
        if (is_file($jsonPath)) {
            file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $doc->markClean();

            return $this->withFlattenReport(
                ['saved' => $jsonPath, 'dirty' => false, 'format' => 'json'],
                $flattened,
            );
        }

        $className = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $sceneName)));
        $path = Path::join($scenesDir, $className.'.php');
        $generated = $context->transpiler->fromArray($data);
        file_put_contents($path, $generated);
        $doc->markClean();

        // Saving is the point where a component the generator cannot express
        // loses its values. Report it rather than let the next scene load come
        // back with an empty mesh graph or heightmap and no explanation.
        $dropped = DroppedPropertyDetector::detect($data, $generated);

        return $this->withFlattenReport([
            'saved' => $path,
            'dirty' => false,
            'format' => 'php',
            'dropped' => $dropped,
            'warning' => DroppedPropertyDetector::describe($dropped),
        ], $flattened);
    }

    /**
     * Report what flattening did, including what it could NOT do: an instance
     * whose prefab cannot be built here stays a reference, and a file that
     * silently kept one would be a nasty surprise for whoever asked for a
     * self-contained scene.
     *
     * @param  array<string, mixed>  $result
     * @param  array{entities: list<array<string, mixed>>, expanded: int, skipped: list<string>}|null  $flattened
     * @return array<string, mixed>
     */
    private function withFlattenReport(array $result, ?array $flattened): array
    {
        if ($flattened === null) {
            return $result;
        }

        $result['flattened'] = $flattened['expanded'];
        $result['notFlattened'] = $flattened['skipped'];

        if ($flattened['skipped'] !== []) {
            $names = implode(', ', array_slice($flattened['skipped'], 0, 3));
            $more = count($flattened['skipped']) - 3;
            $result['warning'] = trim(
                ($result['warning'] ?? '').' Kept as prefab references (their prefabs cannot be built here): '
                .$names.($more > 0 ? " and {$more} more" : '').'.'
            );
        }

        return $result;
    }
}
