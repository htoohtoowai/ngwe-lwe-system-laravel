<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

/**
 * Segmented control — the reference's "Own Account / Other / Pay / Other Bank"
 * bar: a light-gray track with a black selected pill. Here it switches between
 * the four transaction types.
 */
defineProps<{
    tabs: { label: string; href: string }[];
}>();

const page = usePage();
const isActive = (href: string) => page.url.startsWith(href);
function authHeaders(): Record<string, string> {
    return {};
}
</script>

<template>
    <div class="flex w-full max-w-2xl items-center rounded-pill bg-mist p-1">
        <Link
            v-for="t in tabs"
            :key="t.href"
            :href="t.href"
            :headers="authHeaders()"
            class="flex-1 rounded-pill px-4 py-2 text-center text-[13px] font-bold whitespace-nowrap transition"
            :class="
                isActive(t.href)
                    ? 'bg-ink text-white shadow-sm'
                    : 'text-slate hover:text-ink'
            "
        >
            {{ t.label }}
        </Link>
    </div>
</template>
