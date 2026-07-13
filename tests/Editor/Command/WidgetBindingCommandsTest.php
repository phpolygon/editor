<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\SetWidgetBindingCommand;
use PHPolygon\Editor\Command\SetWidgetEventCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\UI\WidgetDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPUnit\Framework\TestCase;

class WidgetBindingCommandsTest extends TestCase
{
    private EditorContext $ctx;

    private string $labelId;

    private string $buttonId;

    protected function setUp(): void
    {
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
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: sys_get_temp_dir(),
        );

        $doc = new WidgetDocument('t', [
            '_widget' => Panel::class,
            'children' => [
                ['_widget' => Label::class, 'text' => 'hello'],
                ['_widget' => Button::class, 'label' => 'OK'],
            ],
        ]);
        $this->ctx->setActiveWidgetDocument($doc);

        $children = $doc->toArray()['root']['children'];
        $this->labelId = $children[0]['_id'];
        $this->buttonId = $children[1]['_id'];
    }

    public function test_set_and_clear_value_binding(): void
    {
        $result = (new SetWidgetBindingCommand([
            'id' => $this->labelId, 'property' => 'text', 'path' => 'selectedClient.companyName',
        ]))->execute($this->ctx);

        $this->assertSame(
            ['$bind' => 'selectedClient.companyName'],
            $result['root']['children'][0]['text'],
        );

        // Clearing (null path) removes the binding entirely.
        $cleared = (new SetWidgetBindingCommand([
            'id' => $this->labelId, 'property' => 'text', 'path' => null,
        ]))->execute($this->ctx);

        $this->assertArrayNotHasKey('text', $cleared['root']['children'][0]);
    }

    public function test_set_and_clear_event_action(): void
    {
        $result = (new SetWidgetEventCommand([
            'id' => $this->buttonId, 'event' => 'click', 'action' => 'confirmSetup',
        ]))->execute($this->ctx);

        $this->assertSame(['click' => 'confirmSetup'], $result['root']['children'][1]['$on']);

        $cleared = (new SetWidgetEventCommand([
            'id' => $this->buttonId, 'event' => 'click', 'action' => '',
        ]))->execute($this->ctx);

        $this->assertArrayNotHasKey('$on', $cleared['root']['children'][1]);
    }
}
