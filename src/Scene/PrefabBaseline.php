<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\Editor\Registry\PrefabScanner;
use PHPolygon\Editor\Support\HeadlessEngine;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\PrefabInterface;
use PHPolygon\Scene\SceneBuilder;
use Throwable;

/**
 * What a prefab's `build()` produces on its own — the values an instance is
 * measured against to decide what it actually overrides.
 *
 * Placing a prefab stores a reference plus authored components. Without a
 * baseline the editor cannot tell an intentional override ("this lamp is red")
 * from a value that simply matches the prefab, so every field looks edited and
 * the scene accumulates overrides that change nothing.
 *
 * The prefab is built IN-PROCESS: a prefab class is autoloadable (that is how
 * the palette finds it, see {@see PrefabScanner}),
 * and {@see HeadlessEngine} supplies the facades its build() may touch. A
 * prefab whose build() needs more than that yields no baseline rather than
 * taking the command down — the editor then simply shows no override marks.
 */
final class PrefabBaseline
{
    /** Placement is instance data, never an override. See {@see isPlacement()}. */
    private const TRANSFORM_CLASSES = [
        'PHPolygon\\Component\\Transform2D',
        'PHPolygon\\Component\\Transform3D',
    ];

    /** @var array<string, array<string, array<string, mixed>>|null> */
    private static array $cache = [];

    /**
     * The prefab's own root components, keyed by class, in document shape
     * (`{_class, <prop>: <value>}`), or null when it cannot be built.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public static function for(string $prefabClass): ?array
    {
        if (array_key_exists($prefabClass, self::$cache)) {
            return self::$cache[$prefabClass];
        }

        return self::$cache[$prefabClass] = self::build($prefabClass);
    }

    /**
     * Forget cached baselines.
     *
     * A baseline only changes when the prefab's source does — which happens
     * whenever the editor regenerates a prefab class, so that path clears this.
     */
    public static function forget(?string $prefabClass = null): void
    {
        if ($prefabClass === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$prefabClass]);
    }

    /**
     * Whether a component carries an instance's placement rather than an
     * overridable value.
     *
     * A transform on a prefab instance is build() INPUT: the transpiler turns
     * it into `->at(...)`, and the engine feeds it back in on load. Diffing it
     * away when it happens to sit at the origin would leave the instance with
     * no transform at all — nothing for the gizmo to grab and nothing for an
     * inspector edit to write into.
     */
    public static function isPlacement(string $componentClass): bool
    {
        return in_array($componentClass, self::TRANSFORM_CLASSES, true);
    }

    /**
     * The prefab's whole subtree in document shape — root entity, components
     * and nested children.
     *
     * Where {@see for()} answers "what does an instance inherit", this answers
     * "what IS the prefab", which is what editing one needs: the tree is loaded
     * as a document, changed like any other, and written back out as a class.
     *
     * @return array<string, mixed>|null
     */
    public static function contentOf(string $prefabClass, ?string $rootName = null): ?array
    {
        $declaration = self::declarationFor($prefabClass);
        if ($declaration === null) {
            return null;
        }

        $tree = self::treeOf($declaration, new AttributeSerializer);
        if ($rootName !== null && $rootName !== '') {
            $tree['name'] = $rootName;
        }

        return $tree;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private static function build(string $prefabClass): ?array
    {
        $declaration = self::declarationFor($prefabClass);
        if ($declaration === null) {
            return null;
        }

        $serializer = new AttributeSerializer;
        $baseline = [];
        foreach ($declaration->getComponents() as $component) {
            if (! $component instanceof ComponentInterface) {
                continue;
            }
            $data = $serializer->toArray($component);
            $class = is_string($data['_class'] ?? null) ? $data['_class'] : $component::class;
            $baseline[$class] = $data;
        }

        return $baseline;
    }

    /** Run the prefab's build() once, or null when it cannot run here. */
    private static function declarationFor(string $prefabClass): ?EntityDeclaration
    {
        if (! class_exists($prefabClass) || ! is_subclass_of($prefabClass, PrefabInterface::class)) {
            return null;
        }

        try {
            HeadlessEngine::ensure();

            $builder = new SceneBuilder;
            /** @var PrefabInterface $prefab */
            $prefab = new $prefabClass;

            return $builder->prefabInstance($prefab, 'baseline');
        } catch (Throwable) {
            // A build() that needs more than a headless engine (game registries,
            // assets, live world state) is a normal case, not an error.
            return null;
        }
    }

    /**
     * One declaration and its descendants as a document entity node.
     *
     * @return array<string, mixed>
     */
    private static function treeOf(EntityDeclaration $declaration, AttributeSerializer $serializer): array
    {
        $components = [];
        foreach ($declaration->getComponents() as $component) {
            if ($component instanceof ComponentInterface) {
                $components[] = $serializer->toArray($component);
            }
        }

        $node = ['name' => $declaration->getName(), 'components' => $components];

        $children = [];
        foreach ($declaration->getChildren() as $child) {
            $children[] = self::treeOf($child, $serializer);
        }
        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * Strip the values an instance shares with its prefab.
     *
     * Returns the authored components with every property that equals the
     * baseline removed, and components left carrying nothing but `_class`
     * dropped entirely — so the saved scene lists only real overrides.
     *
     * @param  list<array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    public static function stripMatching(array $components, string $prefabClass): array
    {
        $baseline = self::for($prefabClass);
        if ($baseline === null) {
            return array_values($components);
        }

        $stripped = [];
        foreach ($components as $component) {
            $class = is_string($component['_class'] ?? null) ? $component['_class'] : '';
            if ($class === '' || self::isPlacement($class) || ! isset($baseline[$class])) {
                $stripped[] = $component;

                continue;
            }

            $reduced = ['_class' => $class];
            foreach ($component as $property => $value) {
                if ($property === '_class') {
                    continue;
                }
                if (array_key_exists($property, $baseline[$class]) && self::equals($baseline[$class][$property], $value)) {
                    continue;
                }
                $reduced[$property] = $value;
            }

            if (count($reduced) > 1) {
                $stripped[] = $reduced;
            }
        }

        return $stripped;
    }

    /**
     * Value equality across the JSON round-trip.
     *
     * The document holds decoded JSON, the baseline freshly serialized PHP, so
     * an int 1 and a float 1.0 describe the same value. `==` handles that for
     * scalars; nested maps are compared key-wise so order does not matter.
     */
    private static function equals(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! self::equals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return $a === $b;
    }
}
