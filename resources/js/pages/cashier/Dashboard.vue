<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import CashierOperationsPage from '@/components/cashier/operations/CashierOperationsPage.vue';
import type { CashierOperationsPageProps } from '@/types/page-props';

defineOptions({ inheritAttrs: false });
defineProps<Omit<CashierOperationsPageProps, 'section'>>();
</script>

<template>
    <CashierOperationsPage v-bind="$props" section="dashboard" />

    <Link
        href="/cashier/admin-requests"
        class="fixed right-4 bottom-[calc(1rem+env(safe-area-inset-bottom))] z-40 rounded-full bg-brand px-4 py-3 text-sm font-black text-white shadow-lg"
    >
        Admin Requests
    </Link>
</template>
