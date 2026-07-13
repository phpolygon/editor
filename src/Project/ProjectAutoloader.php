<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

use PHPolygon\Editor\Support\Path;

/**
 * Registers PSR-4 autoloaders for an opened project's own namespaces.
 *
 * The editor process boots only its own Composer autoloader, so a project's
 * classes — scene base classes, components, systems, and everything a scene
 * references — are otherwise unloadable. Without this, requiring a scene file
 * that `extends` a project base class fatals with "class not found".
 *
 * Shared engine classes keep resolving through the editor's own Composer
 * autoloader, which is registered first; the autoloaders added here only fire
 * for a project's own namespace prefixes.
 */
final class ProjectAutoloader
{
    /** @var array<string, true> Already-registered "prefix|baseDir" keys (idempotency). */
    private array $registered = [];

    /**
     * @param  array<string, string>  $psr4Roots  namespace prefix => path relative to the project root
     */
    public function register(string $projectDir, array $psr4Roots): void
    {
        foreach ($psr4Roots as $prefix => $relativePath) {
            $prefix = ltrim($prefix, '\\');
            if ($prefix === '') {
                continue;
            }

            $baseDir = Path::join($projectDir, $relativePath);
            $key = $prefix.'|'.$baseDir;
            if (isset($this->registered[$key]) || ! is_dir($baseDir)) {
                continue;
            }
            $this->registered[$key] = true;

            spl_autoload_register(
                static function (string $class) use ($prefix, $baseDir): void {
                    if (! str_starts_with($class, $prefix)) {
                        return;
                    }

                    $relative = substr($class, strlen($prefix));
                    $file = Path::join($baseDir, str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php');
                    if (is_file($file)) {
                        require $file;
                    }
                },
            );
        }
    }
}
