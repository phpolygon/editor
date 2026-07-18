<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use PHPolygon\Editor\Command\EditorCommandBus;

#[Name('editor_command')]
#[Description(<<<'TXT'
Execute any PHPolygon editor command through the command bus. Open a project first with editor_open_project.

Arguments:
- command: the snake_case command name.
- arguments: a JSON object (as a string) with the command's args. Default "{}".

Common commands (name → args):
  Scene/Entity/Component: list_scenes, create_scene {name}, load_scene {sceneName}, save_scene,
    create_entity {name,parent?}, delete_entity {name}, rename_entity {oldName,newName},
    add_component {entity,component}, remove_component {entity,component},
    update_property {entity,component,property,value}, get_entity_hierarchy,
    list_components {grouped?}, get_component_schema {className}, undo, redo.
  Mesh: save_mesh {name,nodes,output} or {name,raw}, evaluate_procedural_mesh {nodes,output,meshId?},
    list_mesh_assets, load_mesh_asset {name}, list_meshes, get_mesh {id}.
  Material/Shader: save_material {material}, get_material {id}, list_material_assets,
    save_shader {name,vertex,fragment,graph?}.
  Audio: save_audio {name,data} (data = base64 WAV).
  UI: list_widget_types, create_ui_layout {name,rootType?}, add_widget {parentId,type},
    update_widget_property {id,property,value}, set_widget_binding {id,property,path},
    set_widget_event {id,event,action}, save_ui_layout, render_ui_layout {width?,height?}.
Returns the command result as JSON, or an error message on failure.
TXT)]
class EditorCommandTool extends Tool
{
    public function handle(Request $request): Response
    {
        $command = (string) $request->get('command', '');
        if ($command === '') {
            return Response::error('Missing "command".');
        }

        $rawArgs = $request->get('arguments', '{}');
        if (is_array($rawArgs)) {
            $args = $rawArgs;
        } else {
            $args = json_decode((string) $rawArgs, true);
            if (! is_array($args)) {
                return Response::error('"arguments" must be a JSON object, e.g. {"name":"Player"}.');
            }
        }

        try {
            $result = app(EditorCommandBus::class)->dispatch($command, $args);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The snake_case command name, e.g. list_scenes, create_entity, save_mesh.')
                ->required(),
            'arguments' => $schema->string()
                ->description('JSON object with the command arguments, e.g. {"name":"Player"}. Defaults to {}.'),
        ];
    }
}
