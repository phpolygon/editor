<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneManager;
use ReflectionClass;

/**
 * Resolves a scene PHP file to its fully-qualified class name and decides
 * whether that class is actually an editable scene.
 *
 * The editor discovers scenes by scanning a directory, unlike the engine's
 * runtime {@see SceneManager}, which only ever instantiates
 * scenes from an explicit registry. Scanning means the folder may also contain
 * abstract base scenes, traits, enums, or static helper classes co-located
 * with the scenes (e.g. a boot-sequence renderer). Those must never be listed
 * or instantiated as scenes — hence the {@see isLoadableScene()} gate.
 */
final class SceneClassResolver
{
    /**
     * The fully-qualified class name a scene file is expected to declare,
     * derived from the filename and the project's PSR-4 roots. This does not
     * require the file or check that the class exists.
     */
    public static function classFor(string $sceneFile, EditorContext $context): string
    {
        $file = Path::normalize($sceneFile);
        $sceneName = basename($file, '.php');
        $namespace = self::namespaceFor($file, $context);

        return $namespace === '' ? $sceneName : $namespace.'\\'.$sceneName;
    }

    /**
     * A concrete, no-argument-constructible {@see Scene} subclass is the only
     * thing the editor can load or list. Abstract base scenes, non-Scene
     * helper classes, and scenes whose constructor requires arguments (or is
     * not public) are rejected — instantiating them would fatal.
     */
    public static function isLoadableScene(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Scene::class)) {
            return false;
        }

        $ref = new ReflectionClass($class);
        if (! $ref->isInstantiable()) {
            return false;
        }

        $constructor = $ref->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0;
    }

    /**
     * Recover the namespace of a scene file from the project's PSR-4 roots.
     * Returns '' when no root contains the file.
     */
    public static function namespaceFor(string $sceneFile, EditorContext $context): string
    {
        $file = Path::normalize($sceneFile);

        foreach ($context->manifest->psr4Roots as $ns => $path) {
            $root = Path::join($context->projectDir, $path);
            if ($root === '' || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relativeDir = trim(
                substr(Path::normalize(dirname($file)), strlen($root)),
                DIRECTORY_SEPARATOR,
            );
            $ns = rtrim($ns, '\\');

            return $relativeDir === ''
                ? $ns
                : $ns.'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativeDir);
        }

        return '';
    }
}
