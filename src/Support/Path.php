<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Support;

/**
 * Cross-platform path helpers.
 *
 * Project manifests store paths with forward slashes (e.g. "src/Scene"), but
 * on Windows the filesystem and PHP functions like {@see dirname()} produce
 * backslashes. Mixing the two silently breaks string operations on paths —
 * prefix checks, `trim()` against a separator, and separator-to-namespace
 * rewriting. Everything the editor derives from a path must therefore run
 * through {@see normalize()} / {@see join()} first so a single, OS-native
 * separator is in play.
 */
final class Path
{
    /**
     * Rewrite every slash and backslash to the current OS separator.
     */
    public static function normalize(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Join path segments with the OS separator, normalizing each segment and
     * collapsing the separators between them. The first non-empty segment
     * keeps any leading separator (so absolute and UNC paths survive); inner
     * segments are trimmed on both sides. Empty segments are skipped.
     */
    public static function join(string ...$segments): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $result = null;

        foreach ($segments as $segment) {
            $segment = self::normalize($segment);
            if ($segment === '') {
                continue;
            }

            if ($result === null) {
                // Preserve a leading separator (absolute / UNC) but drop a
                // trailing one so the join below does not double it.
                $result = rtrim($segment, $sep);
                if ($result === '') {
                    $result = $segment; // segment was only separators, e.g. "/"
                }

                continue;
            }

            $result .= $sep.trim($segment, $sep);
        }

        return $result ?? '';
    }

    /**
     * Return the portable (forward-slash) form of a path. Use for values that
     * are stored or sent to the frontend as logical asset keys rather than
     * touched by the OS filesystem, so they read the same on every platform.
     */
    public static function toPortable(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
