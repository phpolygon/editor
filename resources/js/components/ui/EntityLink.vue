<script setup lang="ts">
import { Link2, Unlink, CornerUpLeft } from 'lucide-vue-next';
import Button from './Button.vue';

/**
 * Panel-header control for a workspace that is editing content belonging to a
 * scene entity: it names the entity, offers a way to detach, and carries the
 * action that writes the work back.
 *
 * Every authoring workspace can be entered from an entity's inspector, so this
 * keeps that state readable the same way everywhere — without it, a workspace
 * gives no sign that what you are editing has somewhere to return to.
 */
withDefaults(
    defineProps<{
        /** Name of the linked entity. */
        entity: string;
        /** Label for the write-back action. */
        applyLabel?: string;
        /** Overrides the chip's tooltip. */
        hint?: string;
        disabled?: boolean;
    }>(),
    { applyLabel: 'Apply to Entity', hint: undefined, disabled: false },
);

defineEmits<{ apply: []; unlink: [] }>();
</script>

<template>
    <div class="flex items-center gap-1.5">
        <span
            class="flex items-center gap-1.5 h-7 pl-2 pr-0.5 rounded-md bg-editor-input border border-editor-border text-xs max-w-[12rem]"
            :title="hint ?? `Editing content of entity “${entity}”`"
        >
            <Link2 :size="13" :stroke-width="2" class="shrink-0 text-editor-accent" />
            <span class="truncate">{{ entity }}</span>
            <button
                class="p-1 rounded text-editor-muted hover:text-editor-text hover:bg-editor-elevated"
                title="Stop editing this entity's content"
                data-testid="unlink-entity"
                @click="$emit('unlink')"
            >
                <Unlink :size="12" :stroke-width="2" />
            </button>
        </span>
        <Button
            variant="primary"
            :icon="CornerUpLeft"
            :disabled="disabled"
            :title="`Write this back to ${entity}`"
            data-testid="apply-to-entity"
            @click="$emit('apply')"
        >
            {{ applyLabel }}
        </Button>
    </div>
</template>
