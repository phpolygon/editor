import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { ComponentData, EntityNode } from '@/types';
import * as commands from '@/bridge/commands';

/** A prefab's own components, or null when it could not be built. */
type Baseline = ComponentData[] | null;

/** One component as the inspector shows it for a prefab instance. */
export interface MergedComponent {
    data: ComponentData;
    /** Property names the instance overrides; empty for a plain entity. */
    overridden: Set<string>;
    /** True when the component exists only because the prefab produces it. */
    inherited: boolean;
}

/**
 * Deep value equality across the JSON round-trip, mirroring the backend's rule:
 * the document holds decoded JSON and the baseline freshly serialized PHP, so
 * an int 1 and a float 1.0 are the same value.
 */
function sameValue(a: unknown, b: unknown): boolean {
    if (typeof a === 'number' && typeof b === 'number') return Math.abs(a - b) < 1e-9;
    if (Array.isArray(a) && Array.isArray(b)) {
        return a.length === b.length && a.every((v, i) => sameValue(v, b[i]));
    }
    if (a && b && typeof a === 'object' && typeof b === 'object') {
        const ka = Object.keys(a as object);
        const kb = Object.keys(b as object);
        if (ka.length !== kb.length) return false;
        return ka.every((k) =>
            Object.prototype.hasOwnProperty.call(b, k)
            && sameValue((a as Record<string, unknown>)[k], (b as Record<string, unknown>)[k]),
        );
    }
    return a === b;
}

export const usePrefabStore = defineStore('prefab', () => {
    const baselines = ref(new Map<string, Baseline>());
    const loading = ref(new Set<string>());

    /**
     * Fetch what a prefab produces on its own. Cached per class: it only
     * changes when the prefab's source does.
     */
    async function loadBaseline(prefabClass: string): Promise<Baseline> {
        if (baselines.value.has(prefabClass)) return baselines.value.get(prefabClass)!;
        if (loading.value.has(prefabClass)) return null;

        loading.value.add(prefabClass);
        try {
            const result = await commands.getPrefabBaseline({ class: prefabClass });
            const baseline = result.available ? result.components : null;
            baselines.value.set(prefabClass, baseline);
            return baseline;
        } catch {
            // A prefab the editor cannot build is a normal case; the inspector
            // then shows the instance without override marks.
            baselines.value.set(prefabClass, null);
            return null;
        } finally {
            loading.value.delete(prefabClass);
        }
    }

    /** Drop a cached baseline — the prefab's source changed. */
    function forget(prefabClass?: string) {
        if (prefabClass) baselines.value.delete(prefabClass);
        else baselines.value.clear();
    }

    /**
     * What the inspector renders for an entity.
     *
     * A prefab instance stores only its overrides, so showing its components
     * alone would hide everything the prefab contributes — the inspector would
     * be near-empty for a fully-featured object. The prefab's components are
     * merged in, with authored values winning, and each property is marked as
     * overridden or inherited.
     */
    function componentsFor(entity: EntityNode | null): MergedComponent[] {
        if (!entity) return [];

        const authored = entity.components ?? [];
        const plain = authored.map((data) => ({
            data,
            overridden: new Set<string>(),
            inherited: false,
        }));

        if (!entity.prefab) return plain;

        const baseline = baselines.value.get(entity.prefab);
        if (!baseline) return plain;

        const merged: MergedComponent[] = [];
        const seen = new Set<string>();

        for (const base of baseline) {
            seen.add(base._class);
            const override = authored.find((c) => c._class === base._class);
            if (!override) {
                merged.push({ data: base, overridden: new Set(), inherited: true });
                continue;
            }

            const properties = { ...base.properties };
            const overridden = new Set<string>();
            for (const [name, value] of Object.entries(override.properties ?? {})) {
                properties[name] = value;
                if (!sameValue(base.properties?.[name], value)) overridden.add(name);
            }

            merged.push({
                data: { _class: base._class, properties },
                overridden,
                inherited: false,
            });
        }

        // Components the instance adds that the prefab does not have: every
        // value on them is an override.
        for (const component of authored) {
            if (seen.has(component._class)) continue;
            merged.push({
                data: component,
                overridden: new Set(Object.keys(component.properties ?? {})),
                inherited: false,
            });
        }

        return merged;
    }

    async function revert(entity: string, component: string, property?: string) {
        await commands.revertPrefabOverride(entity, component, property);
    }

    return { baselines, loadBaseline, forget, componentsFor, revert };
});
