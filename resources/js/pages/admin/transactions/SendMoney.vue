<script setup lang="ts">
import TransactionListPage from '@/components/admin/transactions/TransactionListPage.vue';
defineProps<{
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    rows: any[];
}>();
</script>
<template>
    <TransactionListPage
        v-bind="$props"
        title="Send Money Transactions"
        transaction-type="send_money"
    />
</template>
