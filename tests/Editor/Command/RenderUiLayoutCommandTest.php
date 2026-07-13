<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\RenderUiLayoutCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Repeater;
use PHPUnit\Framework\TestCase;

class RenderUiLayoutCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $ctx;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_render_'.uniqid();
        mkdir($this->projectDir);
        mkdir($this->projectDir.'/ui');

        $this->ctx = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '1.0.0',
                engineVersion: '*',
                scenesPath: 'src/Scenes',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
                defaultMode: '2d',
                uiPath: 'ui',
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir.'/ui/*') ?: []);
        @rmdir($this->projectDir.'/ui');
        @rmdir($this->projectDir);
    }

    public function test_renders_tree_to_placeholder_primitives(): void
    {
        $tree = [
            '_widget' => Panel::class,
            'children' => [
                ['_widget' => Label::class, 'text' => ['$bind' => 'title']],
                [
                    '_widget' => Repeater::class,
                    '$each' => 'clients',
                    'template' => ['_widget' => Label::class, 'text' => ['$bind' => 'name']],
                ],
            ],
        ];
        file_put_contents($this->projectDir.'/ui/sample.ui.json', json_encode($tree));

        $result = (new RenderUiLayoutCommand(['name' => 'sample', 'width' => 400, 'height' => 300]))
            ->execute($this->ctx);

        $this->assertSame(400.0, $result['width']);
        $this->assertNotEmpty($result['primitives'], 'draw list should not be empty');

        $texts = array_values(array_filter(
            $result['primitives'],
            fn (array $p): bool => ($p['op'] ?? null) === 'text',
        ));
        $labels = array_map(fn (array $p) => $p['text'], $texts);

        // The value binding surfaces as a readable placeholder.
        $this->assertContains('{title}', $labels);
        // The repeater expands to the placeholder context's sample rows (3).
        $this->assertCount(3, array_filter($labels, fn ($t) => $t === '{name}'));
    }
}
