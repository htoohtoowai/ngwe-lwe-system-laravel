<script setup lang="ts">
import CashierOperationsPage from '@/components/cashier/operations/CashierOperationsPage.vue';

defineOptions({ inheritAttrs: false });
</script>

<template>
    <CashierOperationsPage v-bind="$attrs" section="dashboard" />
</template>
