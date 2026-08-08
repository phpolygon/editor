<script setup lang="ts">
import { computed, ref, type Component } from 'vue';
import { MousePointerClick, Shapes, Palette, Sparkles, Mountain } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import ComponentSection from './ComponentSection.vue';
import AddComponentMenu from './AddComponentMenu.vue';
import Button from '@/components/ui/Button.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useSelectionStore } from '@/stores/selection';
import { useSceneStore } from '@/stores/scene';
import { useComponentsStore } from '@/stores/components';
import { useEditorStore } from '@/stores/editor';
import { useMeshEditorStore } from '@/stores/meshEditor';
import { useMaterialEditorStore } from '@/stores/materialEditor';
import { useShaderEditorStore } from '@/stores/shaderEditor';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import {
    resolveEntityMaterial,
    resolveEntityMesh,
    resolveEntityShader,
    resolveEntityTerrain,
} from '@/scene/entityAssets';
import type { WorkspaceId } from '@/workspaces';
import { useToast } from '@/composables/useToast';

const selectionStore = useSelectionStore();
const sceneStore = useSceneStore();
const componentsStore = useComponentsStore();
const editorStore = useEditorStore();
const meshEditorStore = useMeshEditorStore();
const materialEditorStore = useMaterialEditorStore();
const shaderEditorStore = useShaderEditorStore();
const terrainEditorStore = useTerrainEditorStore();
const { addToast } = useToast();

const selectedEntityData = computed(() => {
    if (!selectionStore.selectedEntity) return null;
    return sceneStore.findEntity(selectionStore.selectedEntity);
});

// What each authoring workspace could take over for this entity.
const meshTarget = computed(() => resolveEntityMesh(selectedEntityData.value));
const materialTarget = computed(() => resolveEntityMaterial(selectedEntityData.value));
const shaderTarget = computed(() => resolveEntityShader(selectedEntityData.value));
const terrainTarget = computed(() => resolveEntityTerrain(selectedEntityData.value));

interface WorkspaceJump {
    id: WorkspaceId;
    label: string;
    icon: Component;
    hint: string;
    open: (() => Promise<unknown>) | null;
}

const jumps = computed<WorkspaceJump[]>(() => {
    const mesh = meshTarget.value;
    const material = materialTarget.value;
    const shader = shaderTarget.value;
    const terrain = terrainTarget.value;

    return [
        {
            id: 'mesh',
            label: 'Mesh',
            icon: Shapes,
            hint: !mesh
                ? 'This entity has no ProceduralMesh or MeshRenderer component'
                : mesh.kind === 'graph'
                  ? 'Edit this entity’s procedural mesh graph in the Mesh workspace'
                  : mesh.meshId
                    ? `Edit mesh “${mesh.meshId}” in the Mesh workspace`
                    : 'Build this entity’s mesh in the Mesh workspace',
            open: mesh ? () => meshEditorStore.openForEntity(mesh) : null,
        },
        {
            id: 'material',
            label: 'Material',
            icon: Palette,
            hint: !material
                ? 'No component on this entity references a material'
                : material.materialId
                  ? `Edit material “${material.materialId}” in the Material workspace`
                  : 'Create this entity’s material in the Material workspace',
            open: material ? () => materialEditorStore.openForEntity(material) : null,
        },
        {
            id: 'shader',
            label: 'Shader',
            icon: Sparkles,
            // A shader is referenced by the material, not by the entity, so
            // there is nothing to open until a material is assigned.
            hint: shader
                ? `Edit the shader of material “${shader.materialId}”`
                : 'Assign a material first — an entity’s shader comes from its material',
            open: shader ? () => shaderEditorStore.openForEntity(shader) : null,
        },
        {
            id: 'terrain',
            label: 'Terrain',
            icon: Mountain,
            hint: terrain
                ? 'Sculpt this entity’s terrain in the Terrain workspace'
                : 'This entity has no Terrain component',
            open: terrain ? () => terrainEditorStore.openForEntity(terrain) : null,
        },
    ];
});

const opening = ref(false);

/**
 * Hand this entity's content over to an authoring workspace.
 *
 * The content is loaded before switching so a failure leaves the user where
 * they are, and so the workspace never briefly shows what was open before.
 */
async function go(jump: WorkspaceJump) {
    if (!jump.open || opening.value) return;

    opening.value = true;
    try {
        await jump.open();
        editorStore.setWorkspace(jump.id);
    } catch (e: any) {
        addToast(e?.message ?? `Failed to open the ${jump.label} workspace`, 'error');
    } finally {
        opening.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader :title="selectionStore.selectedEntity ? `Inspector - ${selectionStore.selectedEntity}` : 'Inspector'" />

        <div class="flex-1 overflow-y-auto">
            <template v-if="selectedEntityData">
                <div class="p-1.5 border-b border-editor-border">
                    <p class="mb-1 px-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">
                        Edit in workspace
                    </p>
                    <div class="grid grid-cols-2 gap-1">
                        <!-- The title sits on the wrapper: a disabled button
                             takes no pointer events, so it could not explain
                             why it is disabled. -->
                        <div v-for="jump in jumps" :key="jump.id" :title="jump.hint">
                            <Button
                                :icon="jump.icon"
                                :disabled="!jump.open || opening"
                                block
                                :data-testid="`edit-in-${jump.id}`"
                                @click="go(jump)"
                            >
                                {{ jump.label }}
                            </Button>
                        </div>
                    </div>
                </div>

                <ComponentSection
                    v-for="comp in selectedEntityData.components"
                    :key="comp._class"
                    :entity-name="selectedEntityData.name"
                    :component-data="comp"
                    :schema="componentsStore.schemasCache.get(comp._class) ?? null"
                />

                <div class="p-1 border-t border-editor-border">
                    <AddComponentMenu :entity-name="selectedEntityData.name" />
                </div>
            </template>
            <EmptyState
                v-else
                :icon="MousePointerClick"
                title="Nothing selected"
                hint="Select an entity in the hierarchy or viewport to inspect and edit its components."
                compact
            />
        </div>
    </div>
</template>
