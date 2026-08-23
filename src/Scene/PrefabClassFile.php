<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Scene;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;

/**
 * Where a prefab class lives on disk, resolved through the project's PSR-4
 * roots.
 *
 * Both writing a prefab and asking whether it may be rewritten need this, and
 * the answer has to be the same in each — a mismatch would either write a
 * second copy somewhere or check the guard against the wrong file.
 */
final class PrefabClassFile
{
    /** Sub-namespace generated prefabs are written into. */
    public const SUBNAMESPACE = 'Prefab';

    /**
     * The file a fully-qualified prefab class maps to, or null when no PSR-4
     * root covers it (a prefab shipped by the engine or a dependency).
     */
    public static function pathFor(EditorContext $context, string $fqcn): ?string
    {
        $fqcn = ltrim($fqcn, '\\');

        foreach ($context->manifest->psr4Roots as $namespace => $relative) {
            $prefix = rtrim((string) $namespace, '\\').'\\';
            if (! str_starts_with($fqcn, $prefix)) {
                continue;
            }

            $tail = substr($fqcn, strlen($prefix));

            return Path::join(
                $context->projectDir,
                (string) $relative,
                str_replace('\\', DIRECTORY_SEPARATOR, $tail).'.php',
            );
        }

        return null;
    }

    /**
     * Whether the editor may rewrite this prefab: the file exists, still
     * carries its generated marker, and matches its hash.
     *
     * A file outside the project (no PSR-4 match) is never rewritable, and
     * neither is one that does not exist yet — "regenerable" is about replacing
     * something, and {@see PrefabClassGenerator::isRegenerable()} answers only
     * for content that is there.
     */
    public static function isRegenerable(EditorContext $context, string $fqcn): bool
    {
        $path = self::pathFor($context, $fqcn);
        if ($path === null || ! is_file($path)) {
            return false;
        }

        return PrefabClassGenerator::isRegenerable((string) file_get_contents($path));
    }
}
