<script setup lang="ts">
import TransactionListPage from '@/components/admin/transactions/TransactionListPage.vue';
import type { AdminTransactionPageProps } from '@/types/page-props';

defineProps<AdminTransactionPageProps>();
</script>
<template><TransactionListPage v-bind="$props" title="Cash In Transactions" transaction-type="cash_in" /></template>
