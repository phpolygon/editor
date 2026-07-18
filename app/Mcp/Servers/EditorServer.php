<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\EditorCommandTool;
use App\Mcp\Tools\OpenProjectTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('PHPolygon Editor')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
This server drives the PHPolygon game editor through its command bus.

Workflow:
1. Call editor_open_project with the absolute path to a game project (the folder
   containing phpolygon.project.json). Do this once before anything else.
2. Use editor_command to run any editor command (create scenes/entities/components,
   build meshes/materials/shaders, save audio, edit UI layouts). Pass the command
   name and a JSON `arguments` object.

Notes:
- Most commands need an active document: after opening a project, run create_scene
  or load_scene (or create_ui_layout/load_ui_layout for UI) before scene/ui edits.
- Never guess component property names — call get_component_schema first.
- Discover what exists with the list_* commands (list_scenes, list_components,
  list_mesh_assets, list_material_assets, list_widget_types, ...).
- Assets are written into the project's assets/ directories; scenes into the scenes dir.
TXT)]
class EditorServer extends Server
{
    protected array $tools = [
        OpenProjectTool::class,
        EditorCommandTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
