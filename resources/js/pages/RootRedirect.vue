<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { apiRequest } from '@/lib/api';
import { readStoredToken, removeStoredToken } from '@/lib/auth-token';
import type { SessionUser } from '@/types';

onMounted(() => {
    void redirectToConsole();
});

async function redirectToConsole(): Promise<void> {
    const token = readStoredToken();

    if (!token) {
        router.visit('/login');

        return;
    }

    try {
        const response = await apiRequest<{ user: SessionUser }>(
            '/api/auth/me',
            { token },
        );

        router.visit(consoleHref(response.user.role), {
            headers: {
                Authorization: `Bearer ${token}`,
            },
            replace: true,
        });
    } catch {
        removeStoredToken();
        router.visit('/login', { replace: true });
    }
}

function consoleHref(role: SessionUser['role']): string {
    if (role === 'admin') {
        return '/admin';
    }

    if (role === 'cashier') {
        return '/cashier';
    }

    return '/dashboard';
}
</script>

<template>
    <main
        class="grid min-h-screen place-items-center bg-canvas font-sans text-ink"
    >
        <section
            class="rounded-2xl border border-line bg-card px-8 py-7 text-center shadow-sm"
        >
            <span
                class="mx-auto grid size-11 place-items-center rounded-full bg-brand text-sm font-bold text-white"
                >DP</span
            >
            <h1 class="mt-4 text-lg font-bold">ဒေါက်တာဖုန်း</h1>
            <p class="mt-1 text-sm font-semibold text-slate">
                Opening dashboard…
            </p>
        </section>
    </main>
</template>
