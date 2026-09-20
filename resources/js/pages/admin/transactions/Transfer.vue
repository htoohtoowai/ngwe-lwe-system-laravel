<script setup lang="ts">
import TransactionListPage from '@/components/admin/transactions/TransactionListPage.vue';
import type { AdminTransactionPageProps } from '@/types/page-props';

defineProps<AdminTransactionPageProps>();
</script>
<template><TransactionListPage v-bind="$props" title="Transfer Transactions" transaction-type="transfer" /></template>
