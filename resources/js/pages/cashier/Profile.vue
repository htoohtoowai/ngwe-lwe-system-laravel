<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { apiRequest } from '@/lib/api';
import { readStoredToken, removeStoredToken } from '@/lib/auth-token';

defineProps<{
    role: 'cashier';
    announcement?: string | null;
    notificationCount?: number;
    user: {
        id: number;
        username: string;
        full_name: string;
        role: string;
        has_pin: boolean;
    };
}>();

const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const pin = ref('');
const confirmPin = ref('');
const busy = ref<'password' | 'pin' | null>(null);
const error = ref('');
const notice = ref('');

function firstError(value: unknown): string {
    const data = value as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    const validation = data.errors ? Object.values(data.errors)[0]?.[0] : null;

    return validation ?? data.message ?? 'Request failed.';
}

async function savePassword() {
    if (newPassword.value !== confirmPassword.value) {
        error.value = 'New passwords do not match.';

        return;
    }

    busy.value = 'password';
    error.value = '';
    notice.value = '';

    try {
        await apiRequest('/api/auth/password', {
            method: 'POST',
            token: readStoredToken(),
            body: {
                current_password: currentPassword.value,
                password: newPassword.value,
                password_confirmation: confirmPassword.value,
            },
        });
        removeStoredToken();
        router.visit('/login');
    } catch (exception) {
        error.value = firstError(exception);
    } finally {
        busy.value = null;
    }
}

async function savePin() {
    if (pin.value !== confirmPin.value) {
        error.value = 'PIN values do not match.';

        return;
    }

    busy.value = 'pin';
    error.value = '';
    notice.value = '';

    try {
        await apiRequest('/api/auth/pin', {
            method: 'POST',
            token: readStoredToken(),
            body: { pin: pin.value },
        });
        pin.value = '';
        confirmPin.value = '';
        notice.value = 'Cashier PIN updated successfully.';
    } catch (exception) {
        error.value = firstError(exception);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <BankLayout :role="role" :announcement="announcement" :notification-count="notificationCount">
        <header class="mb-6">
            <p
                class="text-xs font-black tracking-[0.18em] text-brand uppercase"
            >
                Cashier profile
            </p>
            <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                Account security
            </h1>
            <p class="mt-1 text-sm text-slate">
                Manage your Cashier identity, password, and approval PIN.
            </p>
        </header>

        <div
            v-if="error"
            class="mb-4 rounded-xl border border-brand/20 bg-brand-soft px-4 py-3 text-sm font-semibold text-brand"
            role="alert"
        >
            {{ error }}
        </div>
        <div
            v-if="notice"
            class="mb-4 rounded-xl border border-balance/25 bg-balance/5 px-4 py-3 text-sm font-semibold text-balance"
            role="status"
        >
            {{ notice }}
        </div>

        <section
            class="rounded-2xl border border-line bg-card p-5 shadow-sm sm:p-6"
        >
            <div class="flex flex-wrap items-center gap-4">
                <span
                    class="grid size-14 place-items-center rounded-2xl bg-brand text-xl font-black text-white"
                    >{{ user.full_name.slice(0, 1).toUpperCase() }}</span
                >
                <div>
                    <h2 class="text-xl font-black">{{ user.full_name }}</h2>
                    <p class="mt-1 text-sm text-slate">
                        @{{ user.username }} ·
                        <span class="font-bold text-brand uppercase">{{
                            user.role
                        }}</span>
                    </p>
                </div>
            </div>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <form
                class="rounded-2xl border border-line bg-card p-5 shadow-sm sm:p-6"
                @submit.prevent="savePassword"
            >
                <h2 class="text-lg font-black">Change password</h2>
                <p class="mt-1 text-xs text-slate">
                    You will be signed out after a successful password change.
                </p>
                <label
                    class="mt-5 block text-xs font-black tracking-wide text-slate uppercase"
                    for="current-password"
                    >Current password</label
                >
                <input
                    id="current-password"
                    v-model="currentPassword"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="mt-1.5 h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
                <label
                    class="mt-4 block text-xs font-black tracking-wide text-slate uppercase"
                    for="new-password"
                    >New password</label
                >
                <input
                    id="new-password"
                    v-model="newPassword"
                    type="password"
                    autocomplete="new-password"
                    minlength="8"
                    required
                    class="mt-1.5 h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
                <label
                    class="mt-4 block text-xs font-black tracking-wide text-slate uppercase"
                    for="confirm-password"
                    >Confirm password</label
                >
                <input
                    id="confirm-password"
                    v-model="confirmPassword"
                    type="password"
                    autocomplete="new-password"
                    minlength="8"
                    required
                    class="mt-1.5 h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
                <button
                    type="submit"
                    :disabled="busy !== null"
                    class="mt-5 w-full rounded-xl bg-ink px-4 py-3 text-sm font-black text-white hover:bg-brand disabled:opacity-40"
                >
                    {{ busy === 'password' ? 'Updating…' : 'Update password' }}
                </button>
            </form>

            <form
                class="rounded-2xl border border-line bg-card p-5 shadow-sm sm:p-6"
                @submit.prevent="savePin"
            >
                <h2 class="text-lg font-black">Cashier approval PIN</h2>
                <p class="mt-1 text-xs text-slate">
                    {{
                        user.has_pin
                            ? 'Your PIN is required for Cash In approval and Teller return confirmation.'
                            : 'Set a PIN before approving Cash In or confirming Teller returns.'
                    }}
                </p>
                <label
                    class="mt-5 block text-xs font-black tracking-wide text-slate uppercase"
                    for="cashier-pin"
                    >New PIN</label
                >
                <input
                    id="cashier-pin"
                    v-model="pin"
                    type="password"
                    inputmode="numeric"
                    autocomplete="new-password"
                    minlength="4"
                    maxlength="8"
                    pattern="[0-9]{4,8}"
                    required
                    class="money mt-1.5 h-12 w-full rounded-xl border border-line bg-mist px-3 text-center text-xl tracking-[0.45em] outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
                <label
                    class="mt-4 block text-xs font-black tracking-wide text-slate uppercase"
                    for="confirm-pin"
                    >Confirm PIN</label
                >
                <input
                    id="confirm-pin"
                    v-model="confirmPin"
                    type="password"
                    inputmode="numeric"
                    autocomplete="new-password"
                    minlength="4"
                    maxlength="8"
                    pattern="[0-9]{4,8}"
                    required
                    class="money mt-1.5 h-12 w-full rounded-xl border border-line bg-mist px-3 text-center text-xl tracking-[0.45em] outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                />
                <button
                    type="submit"
                    :disabled="busy !== null"
                    class="mt-5 w-full rounded-xl bg-brand px-4 py-3 text-sm font-black text-white hover:bg-ink disabled:opacity-40"
                >
                    {{ busy === 'pin' ? 'Updating…' : 'Update Cashier PIN' }}
                </button>
            </form>
        </div>
    </BankLayout>
</template>
