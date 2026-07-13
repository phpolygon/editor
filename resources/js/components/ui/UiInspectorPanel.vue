<script setup lang="ts">
import { computed } from 'vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { useUiEditorStore } from '@/stores/uiEditor';
import type { WidgetField } from '@/bridge/commands';

const store = useUiEditorStore();
const widget = computed(() => store.selectedWidget);
const shortName = computed(() => widget.value?._widget.split('\\').pop() ?? '');
const fields = computed<WidgetField[]>(() =>
    widget.value ? store.schemaFor(widget.value._widget) : [],
);

function valueOf(name: string, fallback: unknown): unknown {
    const w = widget.value as Record<string, unknown> | null;
    const v = w ? w[name] : undefined;
    return v !== undefined ? v : fallback;
}

function set(name: string, value: unknown) {
    if (widget.value) store.updateProperty(widget.value._id, name, value);
}

// ── Bindings: a property is either a literal or `{ $bind: 'context.path' }` ──
function isBound(name: string): boolean {
    const v = (widget.value as Record<string, unknown> | null)?.[name];
    return !!v && typeof v === 'object' && '$bind' in (v as object);
}

function bindPath(name: string): string {
    const v = (widget.value as Record<string, any> | null)?.[name];
    return isBound(name) ? (v.$bind ?? '') : '';
}

function toggleBind(name: string) {
    if (!widget.value) return;
    store.setBinding(widget.value._id, name, isBound(name) ? null : '');
}

function setBind(name: string, e: Event) {
    if (widget.value) store.setBinding(widget.value._id, name, str(e));
}

// ── Events: map an interactive event to a view-model action ──────────────────
const events = computed<string[]>(() => (widget.value ? store.eventsFor(widget.value._widget) : []));

function actionOf(event: string): string {
    const on = ((widget.value as Record<string, any> | null)?.['$on'] ?? {}) as Record<string, string>;
    return on[event] ?? '';
}

function setAction(event: string, e: Event) {
    if (widget.value) store.setEvent(widget.value._id, event, str(e) || null);
}

function setPart(name: string, current: unknown, part: string, partValue: unknown) {
    set(name, { ...(current as Record<string, unknown> ?? {}), [part]: partValue });
}

function num(e: Event): number {
    return parseFloat((e.target as HTMLInputElement).value) || 0;
}

function str(e: Event): string {
    return (e.target as HTMLInputElement).value;
}

function checked(e: Event): boolean {
    return (e.target as HTMLInputElement).checked;
}

function toHex(c: unknown): string {
    const o = (c ?? {}) as { r?: number; g?: number; b?: number };
    const h = (n: number | undefined) => Math.round((n ?? 0) * 255).toString(16).padStart(2, '0');
    return `#${h(o.r)}${h(o.g)}${h(o.b)}`;
}

function fromHex(hex: string, prev: unknown): Record<string, number> {
    const p = (prev ?? {}) as { a?: number };
    return {
        r: parseInt(hex.slice(1, 3), 16) / 255,
        g: parseInt(hex.slice(3, 5), 16) / 255,
        b: parseInt(hex.slice(5, 7), 16) / 255,
        a: p?.a ?? 1,
    };
}

const numInput = 'w-full min-w-0 bg-editor-input border border-editor-border text-xs rounded px-1 py-0.5 focus:border-editor-accent focus:outline-none';
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader :title="widget ? `Widget: ${shortName}` : 'Inspector'" />

        <div v-if="widget" class="flex-1 overflow-auto p-2 space-y-2">
            <div v-for="f in fields" :key="f.name">
                <div class="flex items-center justify-between mb-0.5">
                    <label class="text-[11px] text-editor-muted" :title="f.name">{{ f.name }}</label>
                    <button
                        class="text-[10px] px-1 rounded font-mono leading-none border border-transparent"
                        :class="isBound(f.name) ? 'bg-editor-accent text-white' : 'text-editor-muted hover:bg-editor-hover'"
                        :title="isBound(f.name) ? 'Bound to data — click for a literal value' : 'Bind to a data path'"
                        @click="toggleBind(f.name)"
                    >
                        {&nbsp;}
                    </button>
                </div>

                <!-- bound: edit the context path instead of a literal -->
                <input
                    v-if="isBound(f.name)"
                    :class="`${numInput} font-mono`"
                    placeholder="context.path"
                    :value="bindPath(f.name)"
                    @change="setBind(f.name, $event)"
                />

                <!-- scalars -->
                <input
                    v-else-if="f.kind === 'string'"
                    :class="numInput"
                    :value="valueOf(f.name, f.default)"
                    @change="set(f.name, str($event))"
                />
                <input
                    v-else-if="f.kind === 'int' || f.kind === 'float'"
                    type="number"
                    step="any"
                    :class="numInput"
                    :value="valueOf(f.name, f.default)"
                    @change="set(f.name, num($event))"
                />
                <label v-else-if="f.kind === 'bool'" class="inline-flex items-center gap-1 text-xs">
                    <input type="checkbox" :checked="valueOf(f.name, f.default) as boolean" @change="set(f.name, checked($event))" />
                </label>
                <input
                    v-else-if="f.kind === 'color'"
                    type="color"
                    class="h-6 w-12 bg-transparent"
                    :value="toHex(valueOf(f.name, f.default))"
                    @change="set(f.name, fromHex(str($event), valueOf(f.name, f.default)))"
                />

                <!-- vec2 -->
                <div v-else-if="f.kind === 'vec2'" class="grid grid-cols-2 gap-1">
                    <input v-for="k in ['x', 'y']" :key="k" type="number" step="any" :class="numInput"
                        :placeholder="k"
                        :value="(valueOf(f.name, f.default) as any)[k]"
                        @change="setPart(f.name, valueOf(f.name, f.default), k, num($event))" />
                </div>

                <!-- edge insets -->
                <div v-else-if="f.kind === 'edgeinsets'" class="grid grid-cols-4 gap-1">
                    <input v-for="k in ['top', 'right', 'bottom', 'left']" :key="k" type="number" step="any" :class="numInput"
                        :title="k"
                        :value="(valueOf(f.name, f.default) as any)[k]"
                        @change="setPart(f.name, valueOf(f.name, f.default), k, num($event))" />
                </div>

                <!-- sizing (width/height + fill toggles; min/max preserved) -->
                <div v-else-if="f.kind === 'sizing'" class="space-y-1">
                    <div class="grid grid-cols-2 gap-1">
                        <input type="number" step="any" :class="numInput" placeholder="w"
                            :value="(valueOf(f.name, f.default) as any).width"
                            @change="setPart(f.name, valueOf(f.name, f.default), 'width', num($event))" />
                        <input type="number" step="any" :class="numInput" placeholder="h"
                            :value="(valueOf(f.name, f.default) as any).height"
                            @change="setPart(f.name, valueOf(f.name, f.default), 'height', num($event))" />
                    </div>
                    <div class="flex gap-3 text-[11px]">
                        <label class="inline-flex items-center gap-1"><input type="checkbox"
                            :checked="(valueOf(f.name, f.default) as any).fillWidth"
                            @change="setPart(f.name, valueOf(f.name, f.default), 'fillWidth', checked($event))" /> fill W</label>
                        <label class="inline-flex items-center gap-1"><input type="checkbox"
                            :checked="(valueOf(f.name, f.default) as any).fillHeight"
                            @change="setPart(f.name, valueOf(f.name, f.default), 'fillHeight', checked($event))" /> fill H</label>
                    </div>
                </div>

                <span v-else class="text-[10px] text-editor-muted">{{ JSON.stringify(valueOf(f.name, f.default)) }}</span>
            </div>

            <!-- Events → view-model actions -->
            <div v-if="events.length" class="pt-2 mt-1 border-t border-editor-border">
                <div class="text-[11px] text-editor-muted mb-1">Events</div>
                <div v-for="ev in events" :key="ev" class="mb-1.5">
                    <label class="block text-[10px] text-editor-muted mb-0.5">on {{ ev }} → action</label>
                    <input
                        :class="`${numInput} font-mono`"
                        placeholder="viewModelMethod"
                        :value="actionOf(ev)"
                        @change="setAction(ev, $event)"
                    />
                </div>
            </div>
        </div>
        <div v-else class="p-3 text-xs text-editor-muted">Select a widget to edit its properties</div>
    </div>
</template>
