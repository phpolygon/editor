<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\ListScenesCommand;
use PHPolygon\Editor\Command\LoadSceneCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAutoloader;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End-to-end guard for the code-tycoon situation: a scenes folder holding a
 * real Scene subclass (extending an abstract base in another file), an
 * abstract base, and a non-Scene static helper with a private constructor.
 * Only the real scene should list and load; the helper must fail cleanly
 * rather than fatally.
 */
class SceneDiscoveryIntegrationTest extends TestCase
{
    private string $projectDir;

    private string $ns;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_scene_disc_'.uniqid();
        $scenesDir = $this->projectDir.'/src/Scenes';
        mkdir($scenesDir, 0777, true);

        // Unique namespace per run so class definitions never collide.
        $token = 'T'.strtoupper(substr(md5($this->projectDir), 0, 8));
        $this->ns = "Fixture\\{$token}\\Scenes";

        file_put_contents($scenesDir.'/AbstractBase.php',
            "<?php namespace {$this->ns}; use PHPolygon\\Scene\\Scene; use PHPolygon\\Scene\\SceneBuilder; "
            .'abstract class AbstractBase extends Scene { public function build(SceneBuilder $b): void {} }');

        file_put_contents($scenesDir.'/RealScene.php',
            "<?php namespace {$this->ns}; class RealScene extends AbstractBase { public function getName(): string { return 'real_scene'; } }");

        // BootRenderer shape: static helper with a private constructor.
        file_put_contents($scenesDir.'/HelperRenderer.php',
            "<?php namespace {$this->ns}; final class HelperRenderer { private function __construct() {} public static function draw(): void {} }");

        $manifest = new ProjectManifest(
            name: 'Fixture',
            version: '1.0.0',
            engineVersion: '*',
            scenesPath: 'src/Scenes',
            assetsPath: 'assets',
            psr4Roots: ["Fixture\\{$token}\\" => 'src'],
            entryScene: '',
        );

        $this->context = new EditorContext(
            manifest: $manifest,
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );

        (new ProjectAutoloader)->register($this->projectDir, ["Fixture\\{$token}\\" => 'src']);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->projectDir);
    }

    public function test_lists_only_the_real_scene(): void
    {
        $result = (new ListScenesCommand)->execute($this->context);

        // AbstractBase (abstract) and HelperRenderer (non-scene) are excluded.
        $this->assertSame(['RealScene'], $result['scenes']);
    }

    public function test_loads_the_real_scene(): void
    {
        $data = (new LoadSceneCommand(['sceneName' => 'RealScene']))->execute($this->context);

        $this->assertSame('real_scene', $data['name']);
    }

    public function test_loading_a_non_scene_helper_fails_cleanly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a PHPolygon scene');

        (new LoadSceneCommand(['sceneName' => 'HelperRenderer']))->execute($this->context);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
