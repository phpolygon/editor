import { ref } from 'vue';

/**
 * Promise-based replacement for `window.prompt` / `window.confirm`, rendered by
 * a single global `<DialogHost>` (mounted once, like `<ToastContainer>`). Call
 * sites `await` a value instead of blocking the main thread with a native
 * dialog:
 *
 *   const name = await useDialog().prompt({ title: 'Prefab name', value: sel });
 *   if (name === null) return;               // cancelled
 *
 *   if (await useDialog().confirm({ title: 'Discard changes?', danger: true })) …
 */

interface PromptOptions {
    title: string;
    message?: string;
    value?: string;
    placeholder?: string;
    confirmLabel?: string;
    cancelLabel?: string;
}

interface ConfirmOptions {
    title: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
}

interface DialogState {
    kind: 'prompt' | 'confirm';
    title: string;
    message?: string;
    placeholder?: string;
    value: string;
    confirmLabel: string;
    cancelLabel: string;
    danger: boolean;
}

const active = ref<DialogState | null>(null);
let resolver: ((value: string | boolean | null) => void) | null = null;

function settle(value: string | boolean | null) {
    const resolve = resolver;
    active.value = null;
    resolver = null;
    resolve?.(value);
}

function prompt(opts: PromptOptions): Promise<string | null> {
    return new Promise((resolve) => {
        resolver = resolve as (v: string | boolean | null) => void;
        active.value = {
            kind: 'prompt',
            title: opts.title,
            message: opts.message,
            placeholder: opts.placeholder,
            value: opts.value ?? '',
            confirmLabel: opts.confirmLabel ?? 'OK',
            cancelLabel: opts.cancelLabel ?? 'Cancel',
            danger: false,
        };
    });
}

function confirm(opts: ConfirmOptions): Promise<boolean> {
    return new Promise((resolve) => {
        resolver = resolve as (v: string | boolean | null) => void;
        active.value = {
            kind: 'confirm',
            title: opts.title,
            message: opts.message,
            value: '',
            confirmLabel: opts.confirmLabel ?? 'Confirm',
            cancelLabel: opts.cancelLabel ?? 'Cancel',
            danger: opts.danger ?? false,
        };
    });
}

/** Called by the host UI. */
function accept() {
    if (!active.value) return;
    settle(active.value.kind === 'prompt' ? active.value.value : true);
}

function dismiss() {
    if (!active.value) return;
    settle(active.value.kind === 'prompt' ? null : false);
}

export function useDialog() {
    return { prompt, confirm };
}

export function useDialogHost() {
    return { active, accept, dismiss };
}
