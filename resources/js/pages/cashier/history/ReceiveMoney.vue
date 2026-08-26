<script setup lang="ts">
import CashierOperationsPage from '@/components/cashier/operations/CashierOperationsPage.vue';

defineProps<{
    role: 'cashier';
    announcement?: string | null;
    notificationCount?: number;
    notes: number[];
    mainVault: Record<string, number>;
    availableVault: Record<string, number>;
    vaultTotal: number;
    vaultLogs: any[];
    floats: any[];
    tellers: any[];
    transactions: any[];
    pendingCashIns: any[];
}>();
</script>

<template>
    <CashierOperationsPage
        v-bind="$props"
        section="teller-entry-history-receive-money"
    />
</template>
