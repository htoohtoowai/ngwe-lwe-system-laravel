<script setup lang="ts">
import AdminOperationsPage from '@/components/admin/operations/AdminOperationsPage.vue';
import type { AdminOperationPageProps } from '@/types/page-props';

defineProps<AdminOperationPageProps>();
</script>
<template><AdminOperationsPage v-bind="$props" section="exchange-rates" /></template>
