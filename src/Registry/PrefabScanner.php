<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Registry;

use FilesystemIterator;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Scene\PrefabInterface;
use PHPolygon\Scene\Transpiler\JsonSceneLoader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * Finds a project's PHP prefab classes by scanning its PSR-4 roots.
 *
 * A prefab reference in a scene is a class-string, and the engine's
 * {@see JsonSceneLoader} resolves it straight
 * through the autoloader — so nothing has to be *registered* for a prefab to
 * work at runtime. Only the editor's palette needed a list, and it used to get
 * one exclusively from the game's `prefabsCommand`. That left a class the
 * editor had just generated invisible until someone wired it into the game.
 *
 * Scanning closes that gap without touching the project: the palette shows what
 * is on disk. A game that declares `prefabsCommand` still wins where the two
 * overlap — it knows about variants and dynamically registered names, which no
 * amount of file scanning can discover.
 */
final class PrefabScanner
{
    /**
     * @param  array<string, string>  $psr4Roots  Namespace prefix => relative path.
     * @return list<array{name: string, class: string}> Sorted by display name.
     */
    public function scan(string $projectDir, array $psr4Roots): array
    {
        if ($projectDir === '') {
            return [];
        }

        $found = [];
        foreach ($psr4Roots as $namespace => $relativePath) {
            $directory = Path::join($projectDir, $relativePath);
            foreach ($this->classesIn($directory, (string) $namespace) as $class) {
                $found[$class] = ['name' => $this->displayName($class), 'class' => $class];
            }
        }

        $prefabs = array_values($found);
        usort($prefabs, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $prefabs;
    }

    /**
     * @return list<class-string<PrefabInterface>>
     */
    private function classesIn(string $directory, string $namespace): array
    {
        $directory = Path::normalize($directory);
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $classes = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Normalise so the prefix strip works whether the iterator reports
            // '/' or '\' (mixed on Windows).
            $relative = str_replace(
                Path::normalize($directory).DIRECTORY_SEPARATOR,
                '',
                Path::normalize($file->getPathname()),
            );
            $class = rtrim($namespace, '\\').'\\'.str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relative,
            );

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->implementsInterface(PrefabInterface::class)) {
                continue;
            }

            /** @var class-string<PrefabInterface> $class */
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * The prefab's own name, falling back to the short class name.
     *
     * `getName()` is project code; a prefab whose implementation throws must
     * degrade to a usable palette entry rather than taking the whole list down.
     */
    private function displayName(string $class): string
    {
        try {
            /** @var class-string<PrefabInterface> $class */
            $name = $class::getName();
            if ($name !== '') {
                return $name;
            }
        } catch (Throwable) {
            // fall through to the short name
        }

        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
