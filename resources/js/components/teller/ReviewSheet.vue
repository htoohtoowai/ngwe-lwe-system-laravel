<script setup lang="ts">
import { useLocale } from '@/lib/i18n';
import MoneyText from './MoneyText.vue';

export interface ReviewLine {
    label: string;
    value: number | string;
    signed?: 'credit' | 'debit' | null;
    emphasize?: boolean;
    kind?: 'money' | 'text';
}

defineProps<{
    open: boolean;
    title: string;
    lines: ReviewLine[];
    confirmLabel: string;
    consequence: string;
    busy?: boolean;
}>();

const { t } = useLocale();

const emit = defineEmits<{ confirm: []; close: [] }>();
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 grid place-items-end bg-ink-950/60 p-0 sm:place-items-center sm:p-4"
        @keydown.esc="emit('close')"
    >
        <div
            class="w-full rounded-t-2xl border border-ink-800 bg-white shadow-2xl sm:max-w-md sm:rounded-counter"
        >
            <header class="border-b border-paper-edge px-5 py-4">
                <p class="field-label">
                    {{ t('component.checkBeforeCommit') }}
                </p>
                <h2
                    class="mt-0.5 font-display text-lg font-semibold text-ink-900"
                >
                    {{ title }}
                </h2>
            </header>

            <dl class="divide-y divide-dashed divide-paper-edge px-5">
                <div
                    v-for="line in lines"
                    :key="line.label"
                    class="flex items-baseline justify-between gap-3 py-2.5"
                    :class="line.emphasize ? 'font-semibold' : ''"
                >
                    <dt
                        class="text-sm"
                        :class="
                            line.emphasize ? 'text-ink-900' : 'text-ink-700'
                        "
                    >
                        {{ line.label }}
                    </dt>
                    <dd>
                        <span
                            v-if="line.kind === 'text'"
                            class="text-right text-sm font-semibold text-ink-900"
                        >
                            {{ line.value }}
                        </span>
                        <MoneyText
                            v-else
                            :value="line.value"
                            :signed="line.signed ?? null"
                            :class="line.emphasize ? 'text-lg' : 'text-sm'"
                        />
                    </dd>
                </div>
            </dl>

            <p
                class="border-t border-paper-edge bg-paper px-5 py-3 text-xs leading-relaxed text-ink-700/80"
            >
                {{ consequence }}
            </p>

            <footer class="flex gap-2 border-t border-paper-edge px-5 py-3">
                <button
                    type="button"
                    @click="emit('close')"
                    class="flex-1 rounded-counter border border-paper-edge py-3 text-sm font-medium text-ink-800 transition hover:border-ink-700"
                >
                    {{ t('common.backToEdit') }}
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    @click="emit('confirm')"
                    class="flex-1 rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:opacity-40"
                >
                    {{ busy ? t('common.recording') : confirmLabel }}
                </button>
            </footer>
        </div>
    </div>
</template>
