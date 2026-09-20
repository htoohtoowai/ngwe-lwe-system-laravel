<script setup lang="ts">
import { computed } from 'vue';
import { menuIcons } from '@/lib/menu-icons';
import type { MenuIconName } from '@/lib/menu-icons';

const props = withDefaults(
    defineProps<{
        name: MenuIconName;
        size?: 'menu' | 'launcher';
    }>(),
    {
        size: 'menu',
    },
);

const icon = computed(() => menuIcons[props.name]);
const tileClass = computed(() =>
    props.size === 'launcher'
        ? 'size-[88px] rounded-[1.45rem] sm:size-[96px]'
        : 'size-9 rounded-xl',
);
const svgClass = computed(() =>
    props.size === 'launcher' ? 'size-12' : 'size-[18px]',
);
</script>

<template>
    <span
        class="relative grid shrink-0 place-items-center overflow-hidden border border-white/20 bg-linear-to-br text-white shadow-[0_12px_28px_-16px_rgba(15,23,42,0.85)]"
        :class="[tileClass, icon.gradient]"
        aria-hidden="true"
    >
        <span
            class="pointer-events-none absolute -top-1/3 -right-1/3 size-3/4 rounded-full bg-white/25"
        />
        <span
            class="pointer-events-none absolute -bottom-1/3 -left-1/3 size-3/4 rounded-full bg-black/10"
        />
        <svg
            class="relative drop-shadow-sm"
            :class="svgClass"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.9"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path v-for="path in icon.paths" :key="path" :d="path" />
        </svg>
    </span>
</template>
