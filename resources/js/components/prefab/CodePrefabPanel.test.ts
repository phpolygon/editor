import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    listCodePrefabs: vi.fn(),
    spawnCodePrefab: vi.fn().mockResolvedValue({ spawned: 'Terminal', prefab: 'X', parent: null }),
    // used transitively by the scene store (spawnCodePrefab -> refreshHierarchy + expandPreview)
    getEntityHierarchy: vi.fn().mockResolvedValue({ entities: [] }),
    expandScene: vi.fn().mockResolvedValue({ name: 't', entities: [], expanded: false }),
}));

import CodePrefabPanel from './CodePrefabPanel.vue';
import { listCodePrefabs, spawnCodePrefab } from '@/bridge/commands';

const mockList = listCodePrefabs as unknown as Mock;
const mockSpawn = spawnCodePrefab as unknown as Mock;

const terminalEntry = {
    name: 'Terminal',
    class: 'CodeRescue\\Prefab\\TerminalPrefabDef',
    variants: ['php', 'rust'],
    variantComponent: 'CodeRescue\\Component\\TerminalDesign',
    variantProperty: 'variant',
};

describe('CodePrefabPanel', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        mockList.mockReset();
        mockSpawn.mockClear();
    });

    it('lists code prefabs with a variant picker after mount', async () => {
        mockList.mockResolvedValue({ prefabs: [terminalEntry] });
        const wrapper = mount(CodePrefabPanel);
        await flushPromises();

        expect(wrapper.find('[data-testid="code-prefab-Terminal"]').exists()).toBe(true);
        const options = wrapper.find('[data-testid="code-prefab-variant-Terminal"]').findAll('option');
        expect(options.map((o) => o.text())).toEqual(['php', 'rust']);
    });

    it('shows an empty state when the game exposes no code prefabs', async () => {
        mockList.mockResolvedValue({ prefabs: [] });
        const wrapper = mount(CodePrefabPanel);
        await flushPromises();

        expect(wrapper.find('[data-testid="code-prefab-empty"]').exists()).toBe(true);
    });

    it('spawns a prefab reference with the chosen variant component', async () => {
        mockList.mockResolvedValue({ prefabs: [terminalEntry] });
        const wrapper = mount(CodePrefabPanel);
        await flushPromises();

        await wrapper.find('[data-testid="code-prefab-variant-Terminal"]').setValue('rust');
        await wrapper.find('[data-testid="code-prefab-spawn-Terminal"]').trigger('click');
        await flushPromises();

        expect(mockSpawn).toHaveBeenCalledTimes(1);
        const [prefabClass, options] = mockSpawn.mock.calls[0];
        expect(prefabClass).toBe('CodeRescue\\Prefab\\TerminalPrefabDef');
        expect(options.name).toBe('Terminal');

        const design = options.components.find(
            (c: { _class: string }) => c._class === 'CodeRescue\\Component\\TerminalDesign',
        );
        expect(design.variant).toBe('rust');
        expect(
            options.components.some((c: { _class: string }) => c._class === 'PHPolygon\\Component\\Transform3D'),
        ).toBe(true);
    });
});
