<script setup lang="ts">import TransactionListPage from '@/components/admin/transactions/TransactionListPage.vue'; defineProps<{ role:'admin'; announcement?:string|null; notificationCount?:number }>();</script>
<template><TransactionListPage v-bind="$props" title="Cash Out Transactions" transaction-type="cash_out" /></template>
