<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\CreateGameProjectCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class CreateGameProjectCommandTest extends TestCase
{
    private string $parentDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->parentDir = sys_get_temp_dir().'/phpolygon-editor-newproj-'.uniqid();
        mkdir($this->parentDir);

        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Host',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
            ),
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: $this->parentDir,
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->parentDir);
    }

    public function testScaffoldsCompleteProject(): void
    {
        $result = $this->create(['name' => 'My Cool Game']);

        $this->assertTrue($result['created']);
        $this->assertSame('my-cool-game', $result['slug']);
        $this->assertSame('App', $result['namespace']);
        $this->assertSame('App\\Game', $result['bootClass']);
        $this->assertSame('MainScene', $result['entryScene']);
        $this->assertFalse($result['steam']['enabled']);

        $dir = $result['projectDir'];
        foreach ([
            'composer.json', 'build.json', 'game.php', '.gitignore',
            'src/Game.php', 'src/Scene/MainScene.php', ProjectLoader::MANIFEST_FILE,
        ] as $f) {
            $this->assertFileExists($dir.'/'.$f, "missing {$f}");
        }
        foreach (['src/Scene', 'assets/meshes', 'assets/materials', 'assets/shaders', 'assets/audio', 'ui', 'resources'] as $d) {
            $this->assertDirectoryExists($dir.'/'.$d);
        }
    }

    public function testBuildJsonIsRunnable(): void
    {
        $result = $this->create(['name' => 'Runner', 'version' => '2.1.0']);
        $build = json_decode((string) file_get_contents($result['projectDir'].'/build.json'), true);

        $this->assertSame('Runner', $build['name']);
        $this->assertSame('2.1.0', $build['version']);
        // run is the runtime hook of the packaged build.
        $this->assertSame('\\App\\Game::start();', $build['run']);
        $this->assertContains('vio', $build['php']['extensions']);
        $this->assertSame('com.phpolygon.runner', $build['identifier']);
    }

    public function testGeneratedPhpIsSyntacticallyValid(): void
    {
        $result = $this->create(['name' => 'Syntax Check', 'sceneName' => 'Level One']);
        foreach (['game.php', 'src/Game.php', 'src/Scene/LevelOne.php'] as $f) {
            $path = $result['projectDir'].'/'.$f;
            exec('php -l '.escapeshellarg($path), $out, $code);
            $this->assertSame(0, $code, "syntax error in {$f}: ".implode("\n", $out));
        }
    }

    public function testBootClassAndGamePhpReferenceSameClass(): void
    {
        $result = $this->create(['name' => 'Wired']);
        $gamePhp = (string) file_get_contents($result['projectDir'].'/game.php');
        $gameClass = (string) file_get_contents($result['projectDir'].'/src/Game.php');

        $this->assertStringContainsString('\\App\\Game::start();', $gamePhp);
        $this->assertStringContainsString('namespace App;', $gameClass);
        $this->assertStringContainsString('public static function start(): void', $gameClass);
        $this->assertStringContainsString('$engine->run();', $gameClass);
    }

    public function testComposerRequiresEngineAndExtVio(): void
    {
        $result = $this->create(['name' => 'Deps', 'engineConstraint' => '^0.36']);
        $composer = json_decode((string) file_get_contents($result['projectDir'].'/composer.json'), true);

        $this->assertSame('*', $composer['require']['ext-vio']);
        $this->assertSame('^0.36', $composer['require']['phpolygon/phpolygon']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['App\\']);
    }

    public function testManifestUsesSceneAndMode(): void
    {
        $result = $this->create(['name' => 'Twine', 'mode' => '2d', 'sceneName' => 'Start']);
        $manifest = (new ProjectLoader())->load($result['projectDir']);

        $this->assertSame('Twine', $manifest->name);
        $this->assertSame('Start', $manifest->entryScene);
        $this->assertSame('2d', $manifest->defaultMode);
        $this->assertSame(['App\\' => 'src'], $manifest->psr4Roots);
    }

    public function testTwoDModeSetsIs3DFalse(): void
    {
        $result = $this->create(['name' => 'Flat', 'mode' => '2d']);
        $gameClass = (string) file_get_contents($result['projectDir'].'/src/Game.php');

        $this->assertStringContainsString('is3D:   false', $gameClass);
    }

    public function testExplicitNamespaceIsRespected(): void
    {
        $result = $this->create(['name' => 'Custom NS', 'namespace' => 'Acme\\Arcade']);

        $this->assertSame('Acme\\Arcade', $result['namespace']);
        $this->assertSame('Acme\\Arcade\\Game', $result['bootClass']);
        $build = json_decode((string) file_get_contents($result['projectDir'].'/build.json'), true);
        $this->assertSame('\\Acme\\Arcade\\Game::start();', $build['run']);
    }

    public function testSteamFilesWhenEnabled(): void
    {
        $result = $this->create([
            'name' => 'Steamed',
            'steam' => [
                'enabled' => true,
                'appId' => '480',
                'steamUser' => 'devlogin',
                'uploadTarget' => 'full',
                'setLive' => 'beta',
                'depots' => ['windows' => '481', 'linux' => '482', 'macos' => '483'],
            ],
        ]);

        $this->assertTrue($result['steam']['enabled']);
        $dir = $result['projectDir'];
        $this->assertSame("480\n", file_get_contents($dir.'/steam_appid.txt'));

        $sb = json_decode((string) file_get_contents($dir.'/steam-build.json'), true);
        $this->assertSame('devlogin', $sb['steamUser']);
        $this->assertSame('480', $sb['uploads']['full']['appId']);
        $this->assertSame('beta', $sb['uploads']['full']['setLive']);
        $this->assertSame('481', $sb['uploads']['full']['depots']['windows-x86_64']);
        $this->assertSame('483', $sb['uploads']['full']['depots']['macos-universal']);
    }

    public function testNoSteamFilesByDefault(): void
    {
        $result = $this->create(['name' => 'Plain']);
        $this->assertFileDoesNotExist($result['projectDir'].'/steam_appid.txt');
        $this->assertFileDoesNotExist($result['projectDir'].'/steam-build.json');
    }

    public function testSteamRequiresNumericAppId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->create(['name' => 'BadSteam', 'steam' => ['enabled' => true, 'appId' => 'not-a-number']]);
    }

    public function testThrowsForMissingName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->create([]);
    }

    public function testThrowsForExistingTarget(): void
    {
        $this->create(['name' => 'Twice']);
        $this->expectException(\RuntimeException::class);
        $this->create(['name' => 'Twice']);
    }

    public function testThrowsForInvalidNamespace(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->create(['name' => 'Bad', 'namespace' => '1Invalid']);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(array $args): array
    {
        return (new CreateGameProjectCommand(['parentDir' => $this->parentDir, ...$args]))->execute($this->context);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$f;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
