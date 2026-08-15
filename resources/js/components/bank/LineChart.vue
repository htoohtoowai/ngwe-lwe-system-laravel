<script setup lang="ts">
import { computed } from 'vue';

/**
 * Dual-line chart, dependency-free SVG — the "Spend vs. Earn" visual.
 * Hollow circle markers, thin lines, gray axis labels, MMK gridlines.
 */
const props = defineProps<{
    labels: string[];
    series: { name: string; color: string; points: number[] }[];
}>();

const W = 720,
    H = 260,
    PAD_L = 64,
    PAD_R = 16,
    PAD_T = 14,
    PAD_B = 34;

const maxVal = computed(() => {
    const all = props.series.flatMap((s) => s.points);
    const m = Math.max(...all, 1);
    // round up to a clean gridline value
    const mag = 10 ** (String(Math.floor(m)).length - 1);

    return Math.ceil(m / mag) * mag;
});

const ticks = computed(() =>
    [0, 0.25, 0.5, 0.75, 1].map((f) => f * maxVal.value),
);

const x = (i: number) =>
    PAD_L +
    (props.labels.length <= 1
        ? 0
        : (i * (W - PAD_L - PAD_R)) / (props.labels.length - 1));
const y = (v: number) => H - PAD_B - (v / maxVal.value) * (H - PAD_T - PAD_B);

const paths = computed(() =>
    props.series.map((s) => ({
        ...s,
        d: s.points
            .map(
                (v, i) =>
                    `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`,
            )
            .join(' '),
        dots: s.points.map((v, i) => ({ cx: x(i), cy: y(v) })),
    })),
);

const fmt = (v: number) =>
    v >= 1_000_000
        ? `${(v / 1_000_000).toLocaleString()}M`
        : v >= 1_000
          ? `${(v / 1_000).toLocaleString()}K`
          : String(v);
</script>

<template>
    <div>
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="w-full"
            role="img"
            aria-label="Line chart"
        >
            <!-- gridlines + y labels -->
            <g v-for="t in ticks" :key="t">
                <line
                    :x1="PAD_L"
                    :x2="W - PAD_R"
                    :y1="y(t)"
                    :y2="y(t)"
                    stroke="var(--color-line)"
                    stroke-width="1"
                />
                <text
                    :x="PAD_L - 8"
                    :y="y(t) + 3.5"
                    text-anchor="end"
                    class="fill-slate"
                    font-size="10"
                    font-family="var(--font-mono)"
                >
                    {{ fmt(t) }}
                </text>
            </g>

            <!-- x labels -->
            <text
                v-for="(l, i) in labels"
                :key="i"
                :x="x(i)"
                :y="H - 12"
                text-anchor="middle"
                class="fill-slate"
                font-size="10"
            >
                {{ l }}
            </text>

            <!-- series -->
            <g v-for="s in paths" :key="s.name">
                <path
                    :d="s.d"
                    fill="none"
                    :stroke="s.color"
                    stroke-width="1.8"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />
                <circle
                    v-for="(d, i) in s.dots"
                    :key="i"
                    :cx="d.cx"
                    :cy="d.cy"
                    r="3.2"
                    fill="var(--color-card)"
                    :stroke="s.color"
                    stroke-width="1.6"
                />
            </g>
        </svg>

        <div class="mt-1 flex justify-end gap-4">
            <span
                v-for="s in series"
                :key="s.name"
                class="flex items-center gap-1.5 text-[11px] font-semibold text-slate"
            >
                <span
                    class="h-0.5 w-4 rounded-full"
                    :style="{ background: s.color }"
                />
                {{ s.name }}
            </span>
        </div>
    </div>
</template>
