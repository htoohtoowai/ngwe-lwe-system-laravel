<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { readStoredToken } from '@/lib/auth-token';

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

onMounted(() => {
    router.visit('/transactions/cash-in', {
        headers: authHeaders(),
        replace: true,
    });
});
</script>

<template>
    <div />
</template>
