<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Scaffold a complete, buildable + runnable PHPolygon game project: editor
 * manifest, composer.json, build.json, a dev entry (game.php), a boot class
 * (src/Game.php that starts the engine), a starter scene, and — when requested —
 * the Steam files (steam_appid.txt + steam-build.json).
 *
 * The project is created but NOT opened and its dependencies are NOT installed
 * (that is a separate, network-bound step). Everything is derived from args so a
 * setup wizard can drive it.
 *
 * Critical runnability links (per engine build spec): build.json.run points at
 * the PSR-4 boot class (\App\Game::start()), and game.php calls the same class,
 * so `php game.php` (dev) and the packaged PHAR start identically.
 */
class CreateGameProjectCommand implements CommandInterface
{
    private const ENGINE_REPO = 'https://github.com/phpolygon/phpolygon';

    private const DEFAULT_ENGINE_CONSTRAINT = '^0.35';

    /** @var list<string> */
    private const DEFAULT_EXTENSIONS = ['vio', 'glfw', 'mbstring', 'zip', 'phar'];

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $name = $this->stringArg('name');
        if ($name === '') {
            throw new RuntimeException('Project name is required');
        }

        $parentDir = $this->stringArg('parentDir');
        if ($parentDir === '' || ! is_dir($parentDir)) {
            throw new RuntimeException("Parent directory not found: {$parentDir}");
        }

        $slug = $this->slugify($name);
        if ($slug === '') {
            throw new RuntimeException('Project name has no usable characters for a folder name');
        }

        $namespace = $this->stringArg('namespace') !== ''
            ? $this->normalizeNamespace($this->stringArg('namespace'))
            : 'App';

        $mode = ($this->args['mode'] ?? null) === '2d' ? '2d' : '3d';
        $is3D = $mode === '3d';
        $identifier = $this->stringArg('identifier') !== ''
            ? $this->stringArg('identifier')
            : 'com.phpolygon.'.preg_replace('/[^a-z0-9]/', '', strtolower($slug));
        $version = $this->stringArg('version') !== '' ? $this->stringArg('version') : '1.0.0';
        $sceneName = $this->className($this->stringArg('sceneName') !== '' ? $this->stringArg('sceneName') : 'MainScene');
        $width = $this->intArg('width', 1280);
        $height = $this->intArg('height', 720);
        $engineConstraint = $this->stringArg('engineConstraint') !== ''
            ? $this->stringArg('engineConstraint')
            : self::DEFAULT_ENGINE_CONSTRAINT;
        $extensions = $this->extensions();
        $threading = ($this->args['threading'] ?? false) === true;

        $projectDir = Path::join($parentDir, $slug);
        if (is_dir($projectDir) || is_file($projectDir)) {
            throw new RuntimeException("Target already exists: {$projectDir}");
        }

        foreach ($this->layout($projectDir) as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $bootFqcn = $namespace.'\\Game';
        $sceneFqcn = $namespace.'\\Scene\\'.$sceneName;

        $this->writeComposerJson($projectDir, $slug, $name, $namespace, $version, $engineConstraint);
        $this->writeBuildJson($projectDir, $name, $identifier, $version, $extensions, $threading, $bootFqcn);
        $this->write($projectDir, 'game.php', $this->gamePhp($bootFqcn));
        $this->write($projectDir, 'src/Game.php', $this->gameClass($namespace, $name, $sceneName, $is3D, $width, $height));
        $this->write($projectDir, 'src/Scene/'.$sceneName.'.php', $this->sceneClass($namespace, $sceneName));
        $this->write($projectDir, '.gitignore', "/vendor/\n/build/\n/.phpolygon/\n");

        (new ProjectLoader)->save(
            new ProjectManifest(
                name: $name,
                version: $version,
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [$namespace.'\\' => 'src'],
                entryScene: $sceneName,
                defaultMode: $mode,
            ),
            $projectDir,
        );

        $steam = $this->maybeWriteSteam($projectDir);

        return [
            'created' => true,
            'projectDir' => $projectDir,
            'name' => $name,
            'namespace' => $namespace,
            'slug' => $slug,
            'identifier' => $identifier,
            'version' => $version,
            'entryScene' => $sceneName,
            'bootClass' => $bootFqcn,
            'steam' => $steam,
            'needsComposerInstall' => true,
        ];
    }

    /** @return list<string> */
    private function layout(string $projectDir): array
    {
        return [
            $projectDir,
            Path::join($projectDir, 'src'),
            Path::join($projectDir, 'src/Scene'),
            Path::join($projectDir, 'assets/meshes'),
            Path::join($projectDir, 'assets/materials'),
            Path::join($projectDir, 'assets/shaders'),
            Path::join($projectDir, 'assets/audio'),
            Path::join($projectDir, 'ui'),
            Path::join($projectDir, 'resources'),
        ];
    }

    private function writeComposerJson(string $projectDir, string $slug, string $name, string $namespace, string $version, string $engineConstraint): void
    {
        $composer = [
            'name' => 'phpolygon-game/'.$slug,
            'description' => $name.' — a PHPolygon game',
            'type' => 'project',
            'version' => $version,
            'require' => [
                'php' => '>=8.5',
                'ext-vio' => '*',
                'phpolygon/phpolygon' => $engineConstraint,
            ],
            'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
            'repositories' => [['type' => 'vcs', 'url' => self::ENGINE_REPO]],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];
        $this->write($projectDir, 'composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  list<string>  $extensions
     */
    private function writeBuildJson(string $projectDir, string $name, string $identifier, string $version, array $extensions, bool $threading, string $bootFqcn): void
    {
        $build = [
            'name' => $name,
            'identifier' => $identifier,
            'version' => $version,
            'entry' => 'game.php',
            'run' => '\\'.$bootFqcn.'::start();',
            'php' => [
                'extensions' => array_values($extensions),
                'threading' => $threading,
            ],
            'phar' => [
                'exclude' => ['**/tests', '**/docs', '**/.git', '**/examples'],
            ],
        ];
        $this->write($projectDir, 'build.json', json_encode($build, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function gamePhp(string $bootFqcn): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        // Dev entry point: `php game.php`. The packaged build starts the same
        // class via build.json "run".
        require_once __DIR__.'/vendor/autoload.php';

        \\{$bootFqcn}::start();

        PHP;
    }

    private function gameClass(string $namespace, string $title, string $sceneName, bool $is3D, int $width, int $height): string
    {
        $is3DLiteral = $is3D ? 'true' : 'false';
        $systems = $is3D
            ? <<<'PHP'
                    $commandList = $engine->commandList3D ?? new RenderCommandList();
                        $engine->world->addSystem(new Transform3DSystem());
                        $engine->world->addSystem(new Camera3DSystem($commandList, self::VIEW_W, self::VIEW_H));
                        $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, $commandList));
                PHP
            : <<<'PHP'
                    // 2D systems — adjust to your game's needs.
                        $engine->world->addSystem(new Transform3DSystem());
                PHP;
        $use3D = $is3D
            ? "use PHPolygon\\Rendering\\RenderCommandList;\nuse PHPolygon\\System\\Camera3DSystem;\nuse PHPolygon\\System\\Renderer3DSystem;\n"
            : '';

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use PHPolygon\\Engine;
        use PHPolygon\\EngineConfig;
        use PHPolygon\\Event\\SceneLoaded;
        use PHPolygon\\Scene\\SceneBuilder;
        use PHPolygon\\System\\Transform3DSystem;
        {$use3D}use {$namespace}\\Scene\\{$sceneName};

        /**
         * Game boot class. Called by game.php (dev) and build.json "run" (packaged).
         */
        final class Game
        {
            public const VIEW_W = {$width};
            public const VIEW_H = {$height};

            public static function start(): void
            {
                \$engine = new Engine(new EngineConfig(
                    title:  '{$title}',
                    width:  self::VIEW_W,
                    height: self::VIEW_H,
                    is3D:   {$is3DLiteral},
                    skipSplash: true,
                    firstLaunchCalibration: false,
                ));

                \$engine->onInit(function () use (\$engine): void {
                    \$builder = new SceneBuilder();
                    \$scene = new {$sceneName}();
                    \$scene->build(\$builder);
                    \$builder->materialize(\$engine->world);
                    \$engine->events->dispatch(new SceneLoaded('{$sceneName}', \$scene));

        {$systems}
                    // Launched from the editor's Play button: mirror the live
                    // world into the snapshot it hands us, so the editor can
                    // show what is actually running. Unset otherwise — a
                    // standalone or packaged run never syncs.
                    \$editorSync = getenv('PHPOLYGON_EDITOR_SYNC');
                    if (is_string(\$editorSync) && \$editorSync !== '') {
                        // Stream mode publishes movement, which the default
                        // reconcile mode cannot: moving an entity changes
                        // component values without changing the world's
                        // structure. Guarded so the project also runs against
                        // engine versions that predate the mode.
                        if (enum_exists(\\PHPolygon\\EditorSyncMode::class)) {
                            \$engine->enableEditorSync(\$editorSync, 0.5, \\PHPolygon\\EditorSyncMode::Stream);
                        } else {
                            \$engine->enableEditorSync(\$editorSync);
                        }
                    }
                });

                \$engine->run();
            }
        }

        PHP;
    }

    private function sceneClass(string $namespace, string $sceneName): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Scene;

        use PHPolygon\\Component\\Transform3D;
        use PHPolygon\\Math\\Vec3;
        use PHPolygon\\Scene\\Scene;
        use PHPolygon\\Scene\\SceneBuilder;

        class {$sceneName} extends Scene
        {
            public function getName(): string
            {
                return '{$sceneName}';
            }

            public function build(SceneBuilder \$builder): void
            {
                \$builder->entity('CameraRig')
                    ->with(new Transform3D(position: new Vec3(0, 2, 5)));
            }
        }

        PHP;
    }

    /**
     * Steam files, when the `steam` arg is provided.
     *
     * @return array{enabled: bool, appId?: string}
     */
    private function maybeWriteSteam(string $projectDir): array
    {
        $steam = $this->args['steam'] ?? null;
        if (! is_array($steam) || ($steam['enabled'] ?? false) !== true) {
            return ['enabled' => false];
        }

        $appId = isset($steam['appId']) ? trim((string) $steam['appId']) : '';
        if ($appId === '' || ! ctype_digit($appId)) {
            throw new RuntimeException('Steam mode requires a numeric App ID');
        }

        $steamUser = isset($steam['steamUser']) ? trim((string) $steam['steamUser']) : '';
        $target = isset($steam['uploadTarget']) && trim((string) $steam['uploadTarget']) !== ''
            ? trim((string) $steam['uploadTarget'])
            : 'full';
        $setLive = isset($steam['setLive']) ? trim((string) $steam['setLive']) : '';
        $depots = is_array($steam['depots'] ?? null) ? $steam['depots'] : [];

        // Dev handshake file: just the App ID.
        $this->write($projectDir, 'steam_appid.txt', $appId."\n");

        // Upload config consumed by docker/steam-upload.sh.
        $steamBuild = [
            'steamUser' => $steamUser,
            'uploads' => [
                $target => [
                    'appId' => $appId,
                    'buildType' => 'full',
                    'setLive' => $setLive,
                    'depots' => [
                        'windows-x86_64' => (string) ($depots['windows'] ?? $depots['windows-x86_64'] ?? ''),
                        'linux-x86_64' => (string) ($depots['linux'] ?? $depots['linux-x86_64'] ?? ''),
                        'macos-universal' => (string) ($depots['macos'] ?? $depots['macos-universal'] ?? ''),
                    ],
                ],
            ],
        ];
        $this->write($projectDir, 'steam-build.json', json_encode($steamBuild, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return ['enabled' => true, 'appId' => $appId];
    }

    private function write(string $projectDir, string $relative, string $contents): void
    {
        $path = Path::join($projectDir, $relative);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $contents);
    }

    private function stringArg(string $key): string
    {
        return is_string($this->args[$key] ?? null) ? trim((string) $this->args[$key]) : '';
    }

    private function intArg(string $key, int $default): int
    {
        $v = $this->args[$key] ?? null;

        return is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : $default);
    }

    /** @return list<string> */
    private function extensions(): array
    {
        $ext = $this->args['extensions'] ?? null;
        if (! is_array($ext) || $ext === []) {
            return self::DEFAULT_EXTENSIONS;
        }

        return array_values(array_filter(array_map(
            static fn ($e): string => is_string($e) ? trim($e) : '',
            $ext,
        ), static fn (string $e): bool => $e !== ''));
    }

    private function slugify(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '';

        return trim($slug, '-');
    }

    private function className(string $value): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = implode('', array_map(static fn (string $p): string => ucfirst($p), $parts));
        if ($name === '' || ctype_digit($name[0])) {
            $name = 'Scene'.$name;
        }

        return $name;
    }

    private function normalizeNamespace(string $ns): string
    {
        $ns = trim(str_replace('/', '\\', $ns), '\\');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $ns)) {
            throw new RuntimeException("Invalid PHP namespace: {$ns}");
        }

        return $ns;
    }
}
