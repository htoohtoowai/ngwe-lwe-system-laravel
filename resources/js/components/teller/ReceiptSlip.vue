<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { readStoredToken } from '@/lib/auth-token';
import { useLocale } from '@/lib/i18n';
import MoneyText from './MoneyText.vue';
import StateChip from './StateChip.vue';

const { t } = useLocale();

defineProps<{
    txn: {
        id: number;
        type: string;
        amount: string;
        fee_amount: string;
        status: string;
        created_at: string;
        account_label?: string;
        change_given?: string;
        customer_name?: string | null;
        customer_phone?: string | null;
    };
    nextHref: string;
    nextLabel: string;
}>();

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}
</script>

<template>
    <div class="mx-auto max-w-sm">
        <div
            class="relative rounded-counter border border-paper-edge bg-white shadow-sm"
        >
            <div
                class="absolute inset-x-4 top-0 h-px -translate-y-px [background:repeating-linear-gradient(90deg,var(--color-paper-edge)_0_6px,transparent_6px_12px)]"
            />

            <div class="px-6 pt-6 pb-5 text-center">
                <span
                    class="mx-auto grid size-12 place-items-center rounded-full bg-credit/10 text-xl font-semibold text-credit"
                    >OK</span
                >
                <p class="field-label mt-3">{{ t('component.reference') }}</p>
                <p class="money text-3xl font-bold tracking-tight text-ink-950">
                    #{{ String(txn.id).padStart(6, '0') }}
                </p>
                <div class="mt-2 flex justify-center">
                    <StateChip :status="txn.status" />
                </div>
            </div>

            <dl
                class="divide-y divide-dashed divide-paper-edge border-t border-dashed border-paper-edge px-6"
            >
                <div class="flex justify-between py-2.5 text-sm">
                    <dt class="text-ink-700">{{ t('component.type') }}</dt>
                    <dd class="font-medium">{{ txn.type }}</dd>
                </div>
                <div
                    v-if="txn.account_label"
                    class="flex justify-between gap-3 py-2.5 text-sm"
                >
                    <dt class="text-ink-700">{{ t('component.account') }}</dt>
                    <dd class="text-right font-medium">
                        {{ txn.account_label }}
                    </dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-sm text-ink-700">
                        {{ t('transaction.amount') }}
                    </dt>
                    <dd>
                        <MoneyText
                            :value="txn.amount"
                            class="text-lg font-semibold"
                        />
                    </dd>
                </div>
                <div
                    v-if="txn.customer_name || txn.customer_phone"
                    class="flex justify-between gap-3 py-2.5 text-sm"
                >
                    <dt class="text-ink-700">
                        {{ t('transaction.customerName') }}
                    </dt>
                    <dd class="text-right font-medium">
                        {{ txn.customer_name || '-' }}
                        <span
                            v-if="txn.customer_phone"
                            class="block text-xs text-ink-700/70"
                            >{{ txn.customer_phone }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-2.5 text-sm">
                    <dt class="text-ink-700">{{ t('transaction.fee') }}</dt>
                    <dd><MoneyText :value="txn.fee_amount" /></dd>
                </div>
                <div
                    v-if="Number(txn.change_given ?? 0) > 0"
                    class="flex justify-between py-2.5 text-sm"
                >
                    <dt class="text-ink-700">{{ t('dashboard.change') }}</dt>
                    <dd>
                        <MoneyText :value="txn.change_given!" signed="debit" />
                    </dd>
                </div>
                <div class="flex justify-between py-2.5 text-sm">
                    <dt class="text-ink-700">{{ t('component.time') }}</dt>
                    <dd class="money">{{ txn.created_at }}</dd>
                </div>
            </dl>

            <p
                v-if="txn.status === 'PENDING_CASHIER_CONFIRM'"
                class="border-t border-paper-edge bg-held/5 px-6 py-3 text-center text-xs leading-relaxed text-held"
            >
                {{ t('component.cashierConfirmHint') }}
            </p>
        </div>

        <div class="mt-4 flex gap-2">
            <Link
                :href="nextHref"
                :headers="authHeaders()"
                class="flex-1 rounded-counter bg-ink-900 py-3 text-center text-sm font-semibold text-white transition hover:bg-ink-800"
            >
                {{ nextLabel }}
            </Link>
            <Link
                href="/teller"
                :headers="authHeaders()"
                class="rounded-counter border border-paper-edge px-4 py-3 text-sm font-medium text-ink-800 transition hover:border-ink-700"
            >
                {{ t('nav.counter') }}
            </Link>
        </div>
    </div>
</template>
