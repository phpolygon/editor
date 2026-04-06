import { post } from './api';
import type {
    ComponentListResponse,
    SchemaResponse,
    SceneData,
    HierarchyResponse,
    ComponentSchemaDTO,
    EntityNode,
} from '@/types';

function cmd<T = unknown>(command: string, args: Record<string, unknown> = {}): Promise<T> {
    return post<T>('/command', { command, args });
}

export function listComponents(grouped = true): Promise<ComponentListResponse> {
    return cmd<ComponentListResponse>('list_components', { grouped });
}

export function getComponentSchema(className: string): Promise<SchemaResponse> {
    return cmd<SchemaResponse>('get_component_schema', { className });
}

export function loadScene(sceneName: string): Promise<SceneData> {
    return cmd<SceneData>('load_scene', { sceneName });
}

export function saveScene(): Promise<{ saved: boolean }> {
    return cmd<{ saved: boolean }>('save_scene');
}

export function listScenes(): Promise<{ scenes: string[] }> {
    return cmd<{ scenes: string[] }>('list_scenes');
}

export function createEntity(
    name: string,
    parent: string | null = null,
): Promise<EntityNode> {
    return cmd<EntityNode>('create_entity', { name, parent });
}

export function deleteEntity(name: string): Promise<{ deleted: boolean }> {
    return cmd<{ deleted: boolean }>('delete_entity', { name });
}

export function addComponent(
    entity: string,
    component: string,
): Promise<{ added: boolean }> {
    return cmd<{ added: boolean }>('add_component', { entity, component });
}

export function removeComponent(
    entity: string,
    component: string,
): Promise<{ removed: boolean }> {
    return cmd<{ removed: boolean }>('remove_component', { entity, component });
}

export function updateProperty(
    entity: string,
    component: string,
    property: string,
    value: unknown,
): Promise<{ updated: boolean }> {
    return cmd<{ updated: boolean }>('update_property', {
        entity,
        component,
        property,
        value,
    });
}

export function getEntityHierarchy(): Promise<HierarchyResponse> {
    return cmd<HierarchyResponse>('get_entity_hierarchy');
}

export function undo(): Promise<{ undone: boolean }> {
    return cmd<{ undone: boolean }>('undo');
}

export function redo(): Promise<{ redone: boolean }> {
    return cmd<{ redone: boolean }>('redo');
}

export function renameEntity(
    oldName: string,
    newName: string,
): Promise<{ renamed: boolean }> {
    return cmd<{ renamed: boolean }>('rename_entity', { oldName, newName });
}

export function reparentEntity(
    entity: string,
    newParent: string | null,
): Promise<{ reparented: boolean }> {
    return cmd<{ reparented: boolean }>('reparent_entity', { entity, newParent });
}
