<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\EditorServer;
use App\Mcp\Tools\EditorCommandTool;
use App\Mcp\Tools\OpenProjectTool;
use Tests\TestCase;

/**
 * Exercises the MCP tools the same way a stdio client would: through the
 * server's tool() test helper. The fixture project is opened, then editor
 * commands run against it — proving the tools drive the command bus and that
 * project state survives between tool calls within a process.
 */
class EditorServerTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = base_path('tests/e2e/fixtures/sample-project');
    }

    public function test_open_project_opens_the_fixture(): void
    {
        EditorServer::tool(OpenProjectTool::class, ['dir' => $this->fixture])
            ->assertOk()
            ->assertSee('"opened": true')
            ->assertSee('E2E Sample Project');
    }

    public function test_open_project_errors_for_missing_directory(): void
    {
        EditorServer::tool(OpenProjectTool::class, ['dir' => $this->fixture.'/does-not-exist'])
            ->assertHasErrors();
    }

    public function test_editor_command_lists_scenes_after_opening(): void
    {
        EditorServer::tool(OpenProjectTool::class, ['dir' => $this->fixture])->assertOk();

        EditorServer::tool(EditorCommandTool::class, [
            'command' => 'list_scenes',
            'arguments' => '{}',
        ])
            ->assertOk()
            ->assertSee('MainScene');
    }

    public function test_editor_command_reports_component_registry(): void
    {
        EditorServer::tool(OpenProjectTool::class, ['dir' => $this->fixture])->assertOk();

        EditorServer::tool(EditorCommandTool::class, [
            'command' => 'list_components',
            'arguments' => '{"grouped":false}',
        ])
            ->assertOk()
            ->assertSee('Transform');
    }

    public function test_editor_command_errors_for_unknown_command(): void
    {
        EditorServer::tool(OpenProjectTool::class, ['dir' => $this->fixture])->assertOk();

        EditorServer::tool(EditorCommandTool::class, [
            'command' => 'nope_not_a_real_command',
            'arguments' => '{}',
        ])
            ->assertHasErrors();
    }

    public function test_editor_command_rejects_invalid_json_arguments(): void
    {
        EditorServer::tool(EditorCommandTool::class, [
            'command' => 'list_scenes',
            'arguments' => 'this is not json',
        ])
            ->assertHasErrors();
    }

    public function test_editor_command_requires_a_command_name(): void
    {
        EditorServer::tool(EditorCommandTool::class, [
            'command' => '',
            'arguments' => '{}',
        ])
            ->assertHasErrors();
    }
}
