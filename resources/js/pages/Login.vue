<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

import { ApiRequestError, apiRequest } from '../lib/api';
import {
    readStoredToken,
    removeStoredToken,
    storeToken,
} from '../lib/auth-token';
import type { LoginResponse, SessionUser } from '../types';

type DemoUser = {
    role: string;
    username: string;
    password: string;
    pin: string;
};

type NoticeTone = 'ok' | 'warn' | 'error';

type Notice = {
    tone: NoticeTone;
    message: string;
};

const props = defineProps<{
    demoUsers?: DemoUser[] | null;
}>();

const brandName = 'ဒေါက်တာဖုန်း';
const username = ref('');
const password = ref('');
const showPassword = ref(false);
const submitting = ref(false);
const notice = ref<Notice | null>(null);
const selectedDemoUsername = ref('');

const canSubmit = computed(
    () => username.value.trim().length > 0 && password.value.length > 0,
);

const roleTone: Record<string, string> = {
    owner: 'bg-[#cd1f2c]/10 text-[#cd1f2c] border-[#cd1f2c]/25',
    cashier: 'bg-[#111827] text-white border-[#111827]',
    employee: 'bg-[#f3f4f6] text-[#111827] border-[#d1d5db]',
};

const accessRows = [
    { label: 'Door credential', value: 'Username + password' },
    { label: 'Money authority', value: 'PIN inside counter' },
    { label: 'Local demos', value: 'Hidden in production' },
];

const accountCards = [
    { title: 'Cash Counter', type: 'ACTIVE', amount: '10,000,000' },
    { title: 'Float Desk', type: 'FLOAT', amount: '4,135,000' },
    { title: 'Pending Cash In', type: 'QUEUE', amount: '820,000' },
];

onMounted(() => {
    const token = readStoredToken();

    if (token) {
        void restoreExistingSession(token);
    }
});

function fill(user: DemoUser): void {
    username.value = user.username;
    password.value = user.password;
    selectedDemoUsername.value = user.username;
    notice.value = null;
}

async function restoreExistingSession(token: string): Promise<void> {
    submitting.value = true;

    try {
        await apiRequest<{ user: SessionUser }>('/api/auth/me', { token });
        goToConsole();
    } catch {
        removeStoredToken();
    } finally {
        submitting.value = false;
    }
}

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    submitting.value = true;
    notice.value = null;

    try {
        const response = await apiRequest<LoginResponse>('/api/auth/login', {
            method: 'POST',
            body: {
                username: username.value.trim(),
                password: password.value,
            },
        });

        storeToken(response.token);
        password.value = '';
        goToConsole();
    } catch (error) {
        notice.value = {
            tone: 'error',
            message: messageFromError(error),
        };
    } finally {
        submitting.value = false;
    }
}

function messageFromError(error: unknown): string {
    if (error instanceof ApiRequestError || error instanceof Error) {
        return error.message;
    }

    return 'Login failed.';
}

function goToConsole(): void {
    if (typeof window !== 'undefined') {
        window.location.assign('/');
    }
}
</script>

<template>
    <Head :title="`${brandName} Sign in`" />

    <main class="min-h-screen overflow-hidden bg-black font-sans text-[#111827]">
        <section
            class="relative grid min-h-screen items-center gap-8 px-5 py-8 lg:grid-cols-[minmax(0,1fr)_30rem] lg:px-10 xl:px-14"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_22%_20%,rgba(205,31,44,0.32),transparent_30%),radial-gradient(circle_at_76%_58%,rgba(255,255,255,0.10),transparent_28%)]"
                aria-hidden="true"
            />

            <section class="relative hidden min-h-[42rem] lg:block">
                <div
                    class="absolute left-0 top-12 w-[58rem] rounded-[2.2rem] border-[18px] border-[#1b1b1b] bg-[#f4f5f7] p-8 shadow-[0_40px_90px_rgba(0,0,0,0.55)]"
                    aria-hidden="true"
                >
                    <div
                        class="absolute left-1/2 top-[-0.85rem] size-2 -translate-x-1/2 rounded-full bg-[#343434]"
                    />
                    <div class="overflow-hidden bg-white">
                        <header
                            class="flex items-center justify-between border-b border-[#eeeeee] px-7 py-4"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="grid size-9 place-items-center rounded-full bg-[#cd1f2c] text-sm font-bold text-white"
                                >
                                    ဒ
                                </div>
                                <span
                                    class="font-display text-lg font-bold text-[#cd1f2c]"
                                >
                                    {{ brandName }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span
                                    class="rounded-full bg-[#f3f4f6] px-3 py-1 text-xs font-medium"
                                >
                                    English
                                </span>
                                <span
                                    class="grid size-8 place-items-center rounded-full bg-[#cd1f2c] text-xs font-semibold text-white"
                                >
                                    J
                                </span>
                            </div>
                        </header>

                        <div class="px-8 py-6">
                            <div class="flex items-center gap-3">
                                <span
                                    class="rounded-full bg-[#cd1f2c] px-3 py-1 text-[11px] font-semibold text-white"
                                >
                                    Announcement
                                </span>
                                <p class="truncate text-sm text-[#4b5563]">
                                    Counter operations are ready for today's cash
                                    service.
                                </p>
                            </div>

                            <div class="mt-8 flex items-start justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-[#6b7280]">
                                        Overview
                                    </p>
                                    <h1
                                        class="mt-7 font-display text-4xl font-semibold tracking-normal"
                                    >
                                        Spend vs. Earn
                                    </h1>
                                </div>
                                <button
                                    class="rounded-full bg-[#f3f4f6] px-4 py-2 text-xs font-semibold text-[#6b7280]"
                                    type="button"
                                >
                                    1 Year
                                </button>
                            </div>

                            <div
                                class="mt-8 rounded-[0.35rem] border border-[#eeeeee] bg-white p-5"
                            >
                                <svg
                                    class="h-48 w-full"
                                    viewBox="0 0 620 190"
                                    role="img"
                                    aria-label="Preview chart"
                                >
                                    <g stroke="#e5e7eb" stroke-width="1">
                                        <line x1="30" y1="30" x2="600" y2="30" />
                                        <line x1="30" y1="75" x2="600" y2="75" />
                                        <line x1="30" y1="120" x2="600" y2="120" />
                                        <line x1="30" y1="165" x2="600" y2="165" />
                                    </g>
                                    <polyline
                                        fill="none"
                                        points="30,80 90,120 150,112 210,76 270,132 330,68 390,98 450,65 510,135 570,75"
                                        stroke="#7bc67e"
                                        stroke-linecap="round"
                                        stroke-width="3"
                                    />
                                    <polyline
                                        fill="none"
                                        points="30,132 90,92 150,82 210,98 270,150 330,125 390,124 450,52 510,50 570,95"
                                        stroke="#e7b39b"
                                        stroke-linecap="round"
                                        stroke-width="3"
                                    />
                                </svg>
                            </div>

                            <div class="mt-8">
                                <div class="flex items-center justify-between">
                                    <h2 class="font-display text-lg font-semibold">
                                        My Accounts
                                    </h2>
                                    <a class="text-xs font-semibold text-[#6b7280]">
                                        Go to Accounts
                                    </a>
                                </div>
                                <div class="mt-4 grid grid-cols-3 gap-4">
                                    <article
                                        v-for="card in accountCards"
                                        :key="card.title"
                                        class="rounded-[0.4rem] border border-[#eeeeee] bg-white p-4 shadow-sm"
                                    >
                                        <div
                                            class="mb-4 flex items-center justify-between"
                                        >
                                            <p class="text-xs font-semibold">
                                                {{ card.title }}
                                            </p>
                                            <span
                                                class="rounded-[0.2rem] bg-[#cd1f2c] px-2 py-1 text-[10px] font-bold text-white"
                                            >
                                                {{ card.type }}
                                            </span>
                                        </div>
                                        <strong
                                            class="font-mono text-lg text-[#111827]"
                                        >
                                            {{ card.amount }}
                                        </strong>
                                        <span class="ml-1 text-[10px] text-[#9ca3af]">
                                            MMK
                                        </span>
                                    </article>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="absolute right-4 top-28 w-[31rem] rounded-[1.8rem] border-[16px] border-[#181818] bg-[#f4f5f7] p-5 shadow-[0_40px_80px_rgba(0,0,0,0.58)]"
                    aria-hidden="true"
                >
                    <div class="absolute left-1/2 top-[-0.9rem] size-2 -translate-x-1/2 rounded-full bg-[#303030]" />
                    <div class="bg-white p-5">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-xl">☰</span>
                                <span
                                    class="grid size-7 place-items-center rounded-full bg-[#cd1f2c] text-xs font-bold text-white"
                                >
                                    ဒ
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="size-2 rounded-full bg-[#cd1f2c]" />
                                <span
                                    class="grid size-7 place-items-center rounded-full bg-[#cd1f2c] text-xs font-semibold text-white"
                                >
                                    J
                                </span>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center gap-2">
                            <span
                                class="rounded-full bg-[#cd1f2c] px-3 py-1 text-[10px] font-semibold text-white"
                            >
                                Announcement
                            </span>
                            <p class="truncate text-xs text-[#6b7280]">
                                Cash counter status is ready.
                            </p>
                        </div>

                        <h2 class="mt-7 font-display text-2xl font-semibold">
                            Spend vs. Earn
                        </h2>
                        <div class="mt-4 border border-[#eeeeee] p-3">
                            <svg class="h-32 w-full" viewBox="0 0 320 130">
                                <g stroke="#eeeeee" stroke-width="1">
                                    <line x1="15" y1="25" x2="310" y2="25" />
                                    <line x1="15" y1="65" x2="310" y2="65" />
                                    <line x1="15" y1="105" x2="310" y2="105" />
                                </g>
                                <polyline
                                    fill="none"
                                    points="15,42 55,75 95,63 135,88 175,45 215,70 255,50 300,88"
                                    stroke="#7bc67e"
                                    stroke-width="2"
                                />
                                <polyline
                                    fill="none"
                                    points="15,90 55,56 95,48 135,82 175,76 215,38 255,36 300,60"
                                    stroke="#e7b39b"
                                    stroke-width="2"
                                />
                            </svg>
                        </div>

                        <h3 class="mt-6 text-sm font-semibold">My Cards</h3>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="rounded-[0.4rem] bg-[#181818] p-4 text-white">
                                <p class="text-[10px] text-white/60">Mastercard</p>
                                <p class="mt-5 font-mono text-xs">0282 **** 8793</p>
                            </div>
                            <div class="rounded-[0.4rem] bg-[#181818] p-4 text-white">
                                <p class="text-[10px] text-white/60">Counter Card</p>
                                <p class="mt-5 font-mono text-xs">0418 **** 2210</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="relative mx-auto w-full max-w-md">
                <div
                    class="mb-5 flex items-center justify-between rounded-[1.25rem] border border-white/10 bg-white/10 px-4 py-3 text-white backdrop-blur lg:hidden"
                >
                    <div>
                        <p class="font-display text-xl font-bold text-white">
                            {{ brandName }}
                        </p>
                        <p class="text-xs text-white/60">Counter Console</p>
                    </div>
                    <span
                        class="rounded-full bg-[#cd1f2c] px-3 py-1 text-xs font-semibold"
                    >
                        Ready
                    </span>
                </div>

                <div
                    class="overflow-hidden rounded-[1.5rem] border border-white/15 bg-white shadow-[0_32px_90px_rgba(0,0,0,0.45)]"
                >
                    <div class="border-b border-[#eeeeee] bg-[#fbfbfc] px-6 py-5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="font-mono text-[11px] text-[#6b7280]">
                                    ACCESS SLIP / DRPHONE-LOCAL
                                </p>
                                <h1
                                    class="mt-2 font-display text-3xl font-semibold tracking-normal"
                                >
                                    {{ brandName }}
                                </h1>
                            </div>
                            <div
                                class="grid size-11 place-items-center rounded-full bg-[#cd1f2c] text-base font-bold text-white"
                                aria-hidden="true"
                            >
                                ဒ
                            </div>
                        </div>
                        <div class="mt-5 flex items-center gap-2">
                            <span
                                class="rounded-full bg-[#cd1f2c] px-3 py-1 text-[11px] font-semibold text-white"
                            >
                                Announcement
                            </span>
                            <p class="truncate text-xs text-[#6b7280]">
                                Sign in to open the counter session.
                            </p>
                        </div>
                    </div>

                    <div class="px-6 py-5">
                        <div class="mb-5 grid gap-2">
                            <div
                                v-for="row in accessRows"
                                :key="row.label"
                                class="flex items-center justify-between border-b border-[#eeeeee] pb-2 text-xs last:border-b-0 last:pb-0"
                            >
                                <span class="text-[#6b7280]">
                                    {{ row.label }}
                                </span>
                                <span class="font-medium text-[#111827]">
                                    {{ row.value }}
                                </span>
                            </div>
                        </div>

                        <form class="space-y-4" @submit.prevent="submit">
                            <div>
                                <label class="field-label" for="username">
                                    Username
                                </label>
                                <input
                                    id="username"
                                    v-model="username"
                                    type="text"
                                    autocomplete="username"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    autofocus
                                    class="field-input mt-1.5 h-12 rounded-[0.7rem]"
                                    placeholder="employee"
                                />
                            </div>

                            <div>
                                <label class="field-label" for="password">
                                    Password
                                </label>
                                <div class="relative mt-1.5">
                                    <input
                                        id="password"
                                        v-model="password"
                                        :type="showPassword ? 'text' : 'password'"
                                        autocomplete="current-password"
                                        class="field-input h-12 rounded-[0.7rem] pr-16"
                                        placeholder="********"
                                        @keydown.enter="submit"
                                    />
                                    <button
                                        type="button"
                                        class="absolute inset-y-1 right-1 rounded-[0.6rem] px-3 text-xs font-semibold text-[#6b7280] transition hover:bg-[#f3f4f6] hover:text-[#111827]"
                                        @click="showPassword = !showPassword"
                                    >
                                        {{ showPassword ? 'Hide' : 'Show' }}
                                    </button>
                                </div>
                            </div>

                            <p
                                v-if="notice"
                                class="rounded-[0.7rem] border px-3.5 py-2.5 text-sm"
                                :class="
                                    notice.tone === 'error'
                                        ? 'border-debit/30 bg-debit/5 text-debit'
                                        : 'border-held/30 bg-held/5 text-held'
                                "
                            >
                                {{ notice.message }}
                            </p>

                            <button
                                type="submit"
                                :disabled="!canSubmit || submitting"
                                class="flex h-12 w-full items-center justify-center rounded-[0.7rem] bg-[#cd1f2c] px-4 font-display text-sm font-semibold text-white transition hover:bg-[#a71925] disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                {{
                                    submitting
                                        ? 'Opening secure session...'
                                        : 'Open counter session'
                                }}
                            </button>
                        </form>

                        <div
                            class="mt-5 flex items-center justify-between border-t border-[#eeeeee] pt-4 text-xs text-[#6b7280]"
                        >
                            <span>Session token</span>
                            <span class="font-mono">{{ brandName }}</span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="props.demoUsers?.length"
                    class="mt-5 rounded-[1.25rem] border border-white/10 bg-white/95 p-4 shadow-[0_22px_70px_rgba(0,0,0,0.35)]"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-[11px] font-semibold text-[#6b7280]">
                            DEMO USERS
                        </p>
                        <p class="text-[11px] text-[#9ca3af]">
                            Tap a role to fill
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <button
                            v-for="user in props.demoUsers"
                            :key="user.username"
                            type="button"
                            class="group grid grid-cols-[1fr_auto] items-center gap-4 rounded-[0.8rem] border px-4 py-3 text-left transition"
                            :class="
                                selectedDemoUsername === user.username
                                    ? 'border-[#cd1f2c] bg-[#fff3f4] shadow-sm'
                                    : 'border-[#eeeeee] bg-white hover:border-[#cd1f2c]/60 hover:shadow-sm'
                            "
                            @click="fill(user)"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <span
                                    class="rounded-full border px-2.5 py-1 text-[10px] font-bold"
                                    :class="
                                        roleTone[user.role.toLowerCase()] ??
                                        roleTone.employee
                                    "
                                >
                                    {{ user.role }}
                                </span>
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-semibold text-[#111827]"
                                    >
                                        {{ user.username }}
                                    </span>
                                    <span class="block text-xs text-[#6b7280]">
                                        PIN requested at approval
                                    </span>
                                </span>
                            </span>
                            <span
                                class="rounded-full px-2.5 py-1 text-xs transition"
                                :class="
                                    selectedDemoUsername === user.username
                                        ? 'bg-[#cd1f2c] text-white'
                                        : 'bg-[#feecef] text-[#cd1f2c] group-hover:bg-[#cd1f2c] group-hover:text-white'
                                "
                            >
                                Fill
                            </span>
                        </button>
                    </div>
                </div>
            </section>
        </section>
    </main>
</template>
