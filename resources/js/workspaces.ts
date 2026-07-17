import type { Component } from 'vue';
import { Boxes, LayoutTemplate, PanelsTopLeft } from 'lucide-vue-next';

import HierarchyPanel from '@/components/hierarchy/HierarchyPanel.vue';
import InspectorPanel from '@/components/inspector/InspectorPanel.vue';
import SceneViewPanel from '@/components/scene/SceneViewPanel.vue';
import UiHierarchyPanel from '@/components/ui/UiHierarchyPanel.vue';
import UiInspectorPanel from '@/components/ui/UiInspectorPanel.vue';
import UiViewportPanel from '@/components/ui/UiViewportPanel.vue';
import PanelElementList from '@/components/panel/PanelElementList.vue';
import PanelInspector from '@/components/panel/PanelInspector.vue';
import PanelLayoutCanvas from '@/components/panel/PanelLayoutCanvas.vue';

/**
 * Data-driven registry of editing workspaces. Each workspace declares its
 * toolbar tab (label + icon) and which components fill the three panel zones,
 * so adding a new workspace (audio synth, mesh editor, material graph …) is a
 * single entry here instead of edits scattered across `v-if` chains in
 * `Toolbar.vue` and `EditorLayout.vue`.
 *
 * `showAssetBrowser` controls the shared bottom-center panel; tool workspaces
 * that want a full-height center can set it to `false`.
 */
export interface WorkspaceDef {
    id: string;
    label: string;
    icon: Component;
    /** Left sidebar (spans both rows). */
    left: Component;
    /** Center-top viewport. */
    center: Component;
    /** Right sidebar (spans both rows). */
    right: Component;
    /** Show the shared asset browser under the viewport (default true). */
    showAssetBrowser?: boolean;
}

export const WORKSPACES: readonly WorkspaceDef[] = [
    {
        id: 'scene',
        label: 'Scene',
        icon: Boxes,
        left: HierarchyPanel,
        center: SceneViewPanel,
        right: InspectorPanel,
    },
    {
        id: 'ui',
        label: 'UI',
        icon: LayoutTemplate,
        left: UiHierarchyPanel,
        center: UiViewportPanel,
        right: UiInspectorPanel,
    },
    {
        id: 'panel',
        label: 'Panels',
        icon: PanelsTopLeft,
        left: PanelElementList,
        center: PanelLayoutCanvas,
        right: PanelInspector,
    },
];

export type WorkspaceId = (typeof WORKSPACES)[number]['id'];

const BY_ID = new Map(WORKSPACES.map((w) => [w.id, w]));

export function getWorkspace(id: string): WorkspaceDef {
    return BY_ID.get(id) ?? WORKSPACES[0];
}
