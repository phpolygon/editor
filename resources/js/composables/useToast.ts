import { ref } from 'vue';

export interface Toast {
    id: number;
    message: string;
    type: 'success' | 'error' | 'info';
}

const toasts = ref<Toast[]>([]);
let nextId = 0;

export function useToast() {
    function addToast(message: string, type: Toast['type'] = 'info', duration = 3000) {
        const id = nextId++;
        toasts.value.push({ id, message, type });
        if (duration > 0) {
            setTimeout(() => removeToast(id), duration);
        }
    }

    function removeToast(id: number) {
        const idx = toasts.value.findIndex((t) => t.id === id);
        if (idx !== -1) toasts.value.splice(idx, 1);
    }

    return { toasts, addToast, removeToast };
}
