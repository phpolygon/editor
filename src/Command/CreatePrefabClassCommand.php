<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Registry\PrefabScanner;
use PHPolygon\Editor\Scene\PrefabClassGenerator;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Save an entity subtree as a PHP prefab CLASS in the open project.
 *
 * The reference-carrying half of the prefab story: a scene that uses the result
 * stores `prefab: <FQCN>` plus override components, and the engine rebuilds the
 * geometry from `build()` on load. Scenes stay compact and readable instead of
 * carrying a copy of the subtree per placement.
 *
 * Contrast {@see SavePrefabCommand}, which writes an `assets/prefabs/*.json`
 * snapshot that {@see SpawnPrefabCommand} inlines as a one-off copy.
 *
 * Nothing has to be registered afterwards: the palette finds the class through
 * {@see PrefabScanner}, and at runtime the engine
 * resolves the reference straight through the autoloader.
 *
 * Regenerating is guarded: a file that no longer matches its `@generated` hash
 * was edited by hand, and overwriting it would discard exactly the logic that
 * makes a code prefab worth having. Pass `overwrite: true` to insist.
 *
 * args: { entityName: string, className?: string, namespace?: string,
 *         overwrite?: bool }
 */
class CreatePrefabClassCommand implements CommandInterface
{
    private const SUBNAMESPACE = 'Prefab';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
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

        if (is_string($entity['prefab'] ?? null) && $entity['prefab'] !== '') {
            throw new RuntimeException(
                "'{$entityName}' is already a prefab instance. Edit ".$entity['prefab'].' instead of nesting it.'
            );
        }

        $className = $this->className(
            is_string($this->args['className'] ?? null) && $this->args['className'] !== ''
                ? $this->args['className']
                : $entityName
        );
        if ($className === '') {
            throw new RuntimeException('Prefab name has no usable characters for a class name');
        }

        [$rootNamespace, $rootPath] = $this->psr4Root($context);
        $namespace = is_string($this->args['namespace'] ?? null) && $this->args['namespace'] !== ''
            ? trim($this->args['namespace'], '\\')
            : rtrim($rootNamespace, '\\').'\\'.self::SUBNAMESPACE;

        $source = (new PrefabClassGenerator)->generate($entity, $namespace, $className);

        $dir = Path::join($context->projectDir, $rootPath, self::SUBNAMESPACE);
        $file = Path::join($dir, $className.'.php');

        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if ($existing !== null && ($this->args['overwrite'] ?? false) !== true
            && ! PrefabClassGenerator::isRegenerable($existing)
        ) {
            throw new RuntimeException(
                "{$className}.php has been edited by hand — regenerating it would discard those changes. "
                .'Save under a different name, or pass overwrite to replace it.'
            );
        }

        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create prefab directory: {$dir}");
        }
        if (file_put_contents($file, $source) === false) {
            throw new RuntimeException("Failed to write prefab class: {$file}");
        }

        return [
            'created' => true,
            'class' => $namespace.'\\'.$className,
            'className' => $className,
            'namespace' => $namespace,
            'path' => $file,
            'replaced' => $existing !== null,
        ];
    }

    /**
     * The PSR-4 root the prefab class is written into.
     *
     * @return array{0: string, 1: string} [namespace prefix, relative path]
     */
    private function psr4Root(EditorContext $context): array
    {
        foreach ($context->manifest->psr4Roots as $namespace => $path) {
            return [(string) $namespace, (string) $path];
        }

        throw new RuntimeException(
            'The project declares no PSR-4 root, so there is nowhere to put a prefab class.'
        );
    }

    /** A StudlyCase class name derived from a free-form prefab name. */
    private function className(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $studly = implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            $parts,
        ));

        // A leading digit is not a valid identifier.
        return preg_match('/^[A-Za-z_]/', $studly) === 1 ? $studly : '';
    }
}
