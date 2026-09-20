<script setup lang="ts">
import CashierOperationsPage from '@/components/cashier/operations/CashierOperationsPage.vue';
import type { CashierOperationsPageProps } from '@/types/page-props';

defineOptions({ inheritAttrs: false });
defineProps<Omit<CashierOperationsPageProps, 'section'>>();
</script>
<template><CashierOperationsPage v-bind="$props" section="morning-issue" /></template>
