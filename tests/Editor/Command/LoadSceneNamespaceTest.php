<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\Scene\SceneClassResolver;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * Guards the scene-class namespace derivation, which previously produced an
 * invalid name like "App\/Scene" on Windows because the manifest's
 * forward-slash scenesPath ("src/Scene") was not reconciled with the OS
 * backslash separator, breaking scene loading.
 */
class LoadSceneNamespaceTest extends TestCase
{
    /**
     * @param  array<string, string>  $psr4Roots
     */
    private function namespaceFor(string $projectDir, array $psr4Roots, string $sceneRelative): string
    {
        $manifest = new ProjectManifest(
            name: 'Test',
            version: '1.0.0',
            engineVersion: '*',
            scenesPath: 'src/Scene',
            assetsPath: 'assets',
            psr4Roots: $psr4Roots,
            entryScene: '',
        );

        $context = new EditorContext(
            manifest: $manifest,
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $projectDir,
        );

        $sep = DIRECTORY_SEPARATOR;
        $file = $context->projectDir.$sep.str_replace('/', $sep, $sceneRelative);

        return SceneClassResolver::namespaceFor($file, $context);
    }

    public function test_derives_namespace_for_scene_in_subdirectory(): void
    {
        $ns = $this->namespaceFor('D:\\proj\\game', ['App\\' => 'src'], 'src/Scene/Level1.php');
        $this->assertSame('App\\Scene', $ns);
    }

    public function test_derives_namespace_for_deeply_nested_scene(): void
    {
        $ns = $this->namespaceFor('D:\\proj\\game', ['App\\' => 'src'], 'src/Scene/World/Level1.php');
        $this->assertSame('App\\Scene\\World', $ns);
    }

    public function test_derives_namespace_for_scene_directly_in_root(): void
    {
        $ns = $this->namespaceFor('D:\\proj\\game', ['App\\' => 'src'], 'src/Level1.php');
        $this->assertSame('App', $ns);
    }

    public function test_handles_unix_style_project_dir(): void
    {
        $ns = $this->namespaceFor('/home/dev/game', ['App\\' => 'src'], 'src/Scene/Level1.php');
        $this->assertSame('App\\Scene', $ns);
    }

    public function test_returns_empty_when_no_root_matches(): void
    {
        $ns = $this->namespaceFor('D:\\proj\\game', ['App\\' => 'lib'], 'src/Scene/Level1.php');
        $this->assertSame('', $ns);
    }
}
