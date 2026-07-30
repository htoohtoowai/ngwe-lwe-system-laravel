<script setup lang="ts">
defineProps<{
    search: string;
    filter: string;
    page: number;
    pageSize: number;
    total: number;
    pageCount: number;
    filterOptions?: { value: string; label: string }[];
    searchPlaceholder?: string;
    hideToolbar?: boolean;
}>();

const emit = defineEmits<{
    'update:search': [value: string];
    'update:filter': [value: string];
    'update:page': [value: number];
    'update:pageSize': [value: number];
}>();
</script>

<template>
    <div v-if="!hideToolbar" class="mt-4 grid gap-2 md:grid-cols-3">
        <input
            :value="search"
            type="search"
            class="bank-input md:col-span-2"
            :placeholder="searchPlaceholder ?? 'Search'"
            @input="emit('update:search', ($event.target as HTMLInputElement).value)"
        />
        <select
            v-if="filterOptions?.length"
            :value="filter"
            class="bank-input"
            aria-label="Filter"
            @change="emit('update:filter', ($event.target as HTMLSelectElement).value)"
        >
            <option value="">All statuses</option>
            <option v-for="option in filterOptions" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
        </select>
    </div>

    <slot />

    <footer class="mt-4 grid items-center gap-3 text-sm font-semibold text-slate md:grid-cols-3">
        <span>
            Showing {{ total ? (page - 1) * pageSize + 1 : 0 }} to
            {{ Math.min(page * pageSize, total) }} of {{ total }} entries
        </span>
        <label class="flex items-center justify-center gap-2">
            Show
            <select
                :value="pageSize"
                class="bank-input w-20 py-2"
                @change="emit('update:pageSize', Number(($event.target as HTMLSelectElement).value))"
            >
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
            </select>
            entries
        </label>
        <div class="flex justify-end gap-2">
            <button
                type="button"
                class="bank-button bank-button-secondary px-3 py-2"
                :disabled="page <= 1"
                @click="emit('update:page', page - 1)"
            >
                Previous
            </button>
            <span class="self-center">{{ page }} / {{ pageCount }}</span>
            <button
                type="button"
                class="bank-button bank-button-secondary px-3 py-2"
                :disabled="page >= pageCount"
                @click="emit('update:page', page + 1)"
            >
                Next
            </button>
        </div>
    </footer>
</template>
