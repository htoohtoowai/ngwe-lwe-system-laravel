<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        confirmLabel?: string;
        busyLabel?: string;
        busy?: boolean;
    }>(),
    {
        confirmLabel: 'Delete',
        busyLabel: 'Deleting...',
        busy: false,
    },
);

const emit = defineEmits<{
    cancel: [];
    confirm: [];
}>();
const confirmButton = ref<HTMLButtonElement | null>(null);

function cancel(): void {
    if (!props.busy) {
        emit('cancel');
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (props.open && event.key === 'Escape') {
        cancel();
    }
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            await nextTick();
            confirmButton.value?.focus();
        }
    },
);

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[90] grid place-items-center bg-ink/60 p-4 backdrop-blur-sm"
            @click.self="cancel"
        >
            <section
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="confirm-action-title"
                aria-describedby="confirm-action-description"
                class="w-full max-w-md rounded-field border border-line bg-card p-5 shadow-2xl sm:p-6"
            >
                <h2
                    id="confirm-action-title"
                    class="text-lg font-black text-ink"
                >
                    {{ title }}
                </h2>
                <p
                    id="confirm-action-description"
                    class="mt-2 text-sm leading-6 font-semibold text-slate"
                >
                    {{ description }}
                </p>
                <div
                    class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        class="bank-button bank-button-secondary"
                        :disabled="busy"
                        @click="cancel"
                    >
                        Cancel
                    </button>
                    <button
                        ref="confirmButton"
                        type="button"
                        class="bank-button bank-button-danger"
                        :disabled="busy"
                        @click="emit('confirm')"
                    >
                        {{ busy ? busyLabel : confirmLabel }}
                    </button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
