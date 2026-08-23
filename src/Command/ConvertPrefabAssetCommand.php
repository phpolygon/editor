<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Scene\PrefabBaseline;
use PHPolygon\Editor\Scene\PrefabClassFile;
use PHPolygon\Editor\Scene\PrefabClassGenerator;
use RuntimeException;

/**
 * Turn a legacy `assets/prefabs/*.prefab.json` snapshot into a PHP prefab
 * class.
 *
 * JSON prefabs are copies: {@see SpawnPrefabCommand} inlines the whole subtree
 * into a scene, so editing the prefab afterwards reaches nothing that was
 * already placed. A class is a reference — scenes point at it and the engine
 * rebuilds the geometry on load. This is the migration path between the two.
 *
 * The JSON file is left alone. Scenes that already inlined a copy of it keep
 * their entities; converting changes what NEW placements get, not what old
 * ones became.
 *
 * args: { path: string (relative to assets/), className?: string,
 *         namespace?: string, overwrite?: bool }
 */
class ConvertPrefabAssetCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $relative = is_string($this->args['path'] ?? null) ? $this->args['path'] : '';
        if ($relative === '') {
            throw new RuntimeException("Missing 'path' argument");
        }

        $root = $this->readPrefabRoot($context, $relative);

        $className = $this->className(
            is_string($this->args['className'] ?? null) && $this->args['className'] !== ''
                ? $this->args['className']
                : (is_string($root['name'] ?? null) ? $root['name'] : '')
        );
        if ($className === '') {
            throw new RuntimeException('The prefab name has no usable characters for a class name');
        }

        $namespace = is_string($this->args['namespace'] ?? null) && $this->args['namespace'] !== ''
            ? trim($this->args['namespace'], '\\')
            : $this->defaultNamespace($context);

        $file = PrefabClassFile::pathFor($context, $namespace.'\\'.$className);
        if ($file === null) {
            throw new RuntimeException(
                "No PSR-4 root covers {$namespace}, so there is nowhere to write the prefab class."
            );
        }

        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if ($existing !== null && ($this->args['overwrite'] ?? false) !== true
            && ! PrefabClassGenerator::isRegenerable($existing)
        ) {
            throw new RuntimeException(
                "{$className}.php has been edited by hand — converting would discard those changes. "
                .'Use a different class name, or pass overwrite to replace it.'
            );
        }

        $source = (new PrefabClassGenerator)->generate($root, $namespace, $className);

        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create prefab directory: {$dir}");
        }
        if (file_put_contents($file, $source) === false) {
            throw new RuntimeException("Failed to write prefab class: {$file}");
        }

        PrefabBaseline::forget($namespace.'\\'.$className);

        return [
            'converted' => true,
            'from' => $relative,
            'class' => $namespace.'\\'.$className,
            'className' => $className,
            'path' => $file,
            'replaced' => $existing !== null,
        ];
    }

    /**
     * The prefab file's root node.
     *
     * @return array<string, mixed>
     */
    private function readPrefabRoot(EditorContext $context, string $relative): array
    {
        $assetsDir = realpath($context->getAssetsDir());
        if ($assetsDir === false) {
            throw new RuntimeException('Assets directory not found');
        }

        $absolute = realpath($assetsDir.DIRECTORY_SEPARATOR.$relative);
        // The path comes from a request, so it must not be able to reach
        // outside the project's assets.
        if ($absolute === false || ! is_file($absolute)
            || ! str_starts_with($absolute, $assetsDir.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException("Prefab file not found: {$relative}");
        }

        $decoded = json_decode((string) file_get_contents($absolute), true);
        if (! is_array($decoded) || ! is_array($decoded['root'] ?? null)) {
            throw new RuntimeException("Invalid prefab format: {$relative}");
        }

        /** @var array<string, mixed> $root */
        $root = $decoded['root'];
        if (! is_string($root['name'] ?? null) && is_string($decoded['name'] ?? null)) {
            $root['name'] = $decoded['name'];
        }

        return $root;
    }

    private function defaultNamespace(EditorContext $context): string
    {
        foreach ($context->manifest->psr4Roots as $namespace => $path) {
            return rtrim((string) $namespace, '\\').'\\'.PrefabClassFile::SUBNAMESPACE;
        }

        throw new RuntimeException(
            'The project declares no PSR-4 root, so there is nowhere to put a prefab class.'
        );
    }

    private function className(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $studly = implode('', array_map(static fn (string $part): string => ucfirst($part), $parts));

        return preg_match('/^[A-Za-z_]/', $studly) === 1 ? $studly : '';
    }
}
