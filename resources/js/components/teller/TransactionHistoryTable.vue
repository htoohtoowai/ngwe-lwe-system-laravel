<script setup lang="ts">
import MoneyText from '@/components/teller/MoneyText.vue';
import StateChip from '@/components/teller/StateChip.vue';
import { useLocale } from '@/lib/i18n';
import type { TransactionHistoryRow } from '@/types/domain';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    rows: TransactionHistoryRow[];
    title: string;
    emptyText?: string;
}>();

const { t } = useLocale();
const perPage = ref(25);
const currentPage = ref(1);
const pageCount = computed(() =>
    Math.max(1, Math.ceil(props.rows.length / perPage.value)),
);
const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;

    return props.rows.slice(start, start + perPage.value);
});
const firstRecord = computed(() =>
    props.rows.length ? (currentPage.value - 1) * perPage.value + 1 : 0,
);
const lastRecord = computed(() =>
    Math.min(currentPage.value * perPage.value, props.rows.length),
);

watch([perPage, () => props.rows.length], () => {
    currentPage.value = Math.min(currentPage.value, pageCount.value);
});

function refNo(id: number): string {
    return `#${String(id).padStart(6, '0')}`;
}

function dateText(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function accountText(row: TransactionHistoryRow): string {
    if (row.to_account_label && row.account_label) {
        return `${row.account_label} -> ${row.to_account_label}`;
    }

    return row.to_account_label ?? row.account_label ?? 'Counter float';
}

function customerText(row: TransactionHistoryRow): string {
    const parts = [row.customer_name, row.customer_phone].filter(Boolean);

    return parts.length ? parts.join(' / ') : (row.note ?? '-');
}
</script>

<template>
    <section
        class="mt-5 overflow-hidden rounded-2xl border border-line bg-card"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3"
        >
            <div>
                <h2 class="text-base font-black text-ink">{{ title }}</h2>
                <p class="mt-0.5 text-xs font-semibold text-slate">
                    {{ rows.length }} {{ t('common.records', 'records') }}
                </p>
            </div>
        </header>

        <div v-if="rows.length === 0" class="px-4 py-8 text-sm text-slate">
            {{ emptyText ?? t('common.noTransactions') }}
        </div>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="bg-mist/70 text-xs font-black text-slate">
                    <tr>
                        <th class="px-4 py-3">Ref</th>
                        <th class="px-4 py-3">{{ t('component.time') }}</th>
                        <th class="px-4 py-3">{{ t('component.account') }}</th>
                        <th class="px-4 py-3">
                            {{ t('transaction.customerName', 'Customer') }}
                        </th>
                        <th class="px-4 py-3 text-right">
                            {{ t('transaction.amount') }}
                        </th>
                        <th class="px-4 py-3 text-right">
                            {{ t('transaction.fee') }}
                        </th>
                        <th class="px-4 py-3 text-right">
                            {{ t('transaction.status') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in paginatedRows" :key="row.id" class="align-top">
                        <td class="money px-4 py-3 font-black">
                            {{ refNo(row.id) }}
                        </td>
                        <td class="px-4 py-3 text-slate">
                            {{ dateText(row.created_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-bold text-ink">
                                {{ accountText(row) }}
                            </p>
                            <p
                                v-if="row.exchange_rate"
                                class="money mt-0.5 text-xs font-semibold text-slate"
                            >
                                {{ t('transaction.rate') }}:
                                {{ row.exchange_rate }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-slate">
                            {{ customerText(row) }}
                        </td>
                        <td class="px-4 py-3 text-right font-black">
                            <MoneyText
                                :value="row.amount"
                                :currency="
                                    row.currency === 'THB' ? 'THB' : 'MMK'
                                "
                            />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <MoneyText :value="row.fee_amount" />
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end">
                                <StateChip :status="row.status" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer
            v-if="rows.length"
            class="mt-4 grid items-center gap-3 px-4 pb-4 text-sm font-semibold text-slate md:grid-cols-3"
        >
            <span>
                Showing {{ firstRecord }} to {{ lastRecord }} of
                {{ rows.length }} entries
            </span>
            <label class="bank-page-size justify-self-center">
                {{ t('common.show', 'Show') }}
                <select
                    v-model.number="perPage"
                    class="bank-page-size-select"
                    @change="currentPage = 1"
                >
                    <option :value="10">10</option>
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
                {{ t('common.entries', 'entries') }}
            </label>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="bank-button bank-button-secondary px-3 py-2"
                    :disabled="currentPage === 1"
                    @click="currentPage--"
                >
                    {{ t('common.previous', 'Previous') }}
                </button>
                <span class="self-center">{{ currentPage }} / {{ pageCount }}</span>
                <button
                    type="button"
                    class="bank-button bank-button-secondary px-3 py-2"
                    :disabled="currentPage === pageCount"
                    @click="currentPage++"
                >
                    {{ t('common.next', 'Next') }}
                </button>
            </div>
        </footer>
    </section>
</template>
