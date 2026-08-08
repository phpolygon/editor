<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

/**
 * Finds component values that the scene document holds but the generated PHP
 * scene does not carry.
 *
 * The transpiler builds each component from its constructor. A component that
 * declares its state as public `#[Property]` fields instead — ProceduralMesh,
 * RawMesh, Terrain, TerrainScatter, InstancedTerrain — therefore came out as a
 * bare `new Component()` with every value dropped: an edited mesh graph or a
 * sculpted heightmap was saved and silently came back empty.
 *
 * Engine versions that emit those values write something other than the bare
 * form, so this detector reports nothing there and needs no version check: it
 * compares the document against what was actually generated.
 */
final class DroppedPropertyDetector
{
    /**
     * @param  array<string, mixed>  $sceneData  The document as passed to the transpiler.
     * @param  string  $generated  The PHP source the transpiler produced.
     * @return list<array{entity: string, component: string, properties: list<string>}>
     */
    public static function detect(array $sceneData, string $generated): array
    {
        $entities = is_array($sceneData['entities'] ?? null) ? $sceneData['entities'] : [];

        return self::walk($entities, $generated);
    }

    /**
     * Human-readable one-liner for the editor's warning, or null when nothing
     * was dropped.
     *
     * @param  list<array{entity: string, component: string, properties: list<string>}>  $dropped
     */
    public static function describe(array $dropped): ?string
    {
        if ($dropped === []) {
            return null;
        }

        $parts = array_map(
            static fn (array $d): string => $d['entity'].' · '.self::shortName($d['component']).' ('.implode(', ', $d['properties']).')',
            array_slice($dropped, 0, 3),
        );
        $more = count($dropped) - count($parts);

        return 'The PHP scene format did not save: '.implode('; ', $parts)
            .($more > 0 ? " and {$more} more" : '')
            .'. Update the engine so these components are written with their values.';
    }

    /**
     * @param  list<mixed>  $entities
     * @return list<array{entity: string, component: string, properties: list<string>}>
     */
    private static function walk(array $entities, string $generated): array
    {
        $dropped = [];

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $name = is_string($entity['name'] ?? null) ? $entity['name'] : '';
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];

            foreach ($components as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $class = is_string($component['_class'] ?? null) ? $component['_class'] : null;
                if ($class === null) {
                    continue;
                }

                // The generator emitted the empty form for this class, so any
                // value the document carries for it did not make it into the
                // file. Matched as a finished argument (`new X())` / `new X(),`)
                // so that `$c = new X();` inside a hydrating closure — how an
                // engine that does carry the values writes it — does not count.
                if (preg_match('/new\s+'.preg_quote(self::shortName($class), '/').'\(\)\s*[),]/', $generated) !== 1) {
                    continue;
                }

                $properties = self::valuedProperties($component);
                if ($properties !== []) {
                    $dropped[] = ['entity' => $name, 'component' => $class, 'properties' => $properties];
                }
            }

            $children = is_array($entity['children'] ?? null) ? $entity['children'] : [];
            if ($children !== []) {
                $dropped = [...$dropped, ...self::walk($children, $generated)];
            }
        }

        return $dropped;
    }

    /**
     * Property names carrying something worth saving. An empty string/array is
     * indistinguishable from an untouched default and is not worth a warning.
     *
     * @param  array<string, mixed>  $component
     * @return list<string>
     */
    private static function valuedProperties(array $component): array
    {
        $names = [];
        foreach ($component as $key => $value) {
            if ($key === '_class' || ! is_string($key)) {
                continue;
            }
            if ($value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }
            $names[] = $key;
        }

        return $names;
    }

    private static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
