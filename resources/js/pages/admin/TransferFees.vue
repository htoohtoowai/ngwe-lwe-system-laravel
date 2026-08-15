<script setup lang="ts">
import type { FeeManagementProps } from './fee-management';
import FeeManagementPage from './Fees.vue';

const props = defineProps<FeeManagementProps>();
</script>

<template>
    <FeeManagementPage v-bind="props" />
</template>
