<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

import { ApiRequestError, apiRequest } from '../lib/api';
import {
    readStoredToken,
    removeStoredToken,
    storeToken,
} from '../lib/auth-token';
import type { DeviceContext, LoginResponse, SessionUser } from '../types';

type NoticeTone = 'ok' | 'warn' | 'error';

type Notice = {
    tone: NoticeTone;
    message: string;
};

const loading = ref(false);
const notice = ref<Notice | null>(null);
const page = usePage<{ device?: DeviceContext }>();
const loginForm = ref({
    username: '',
    password: '',
});
const device = computed<DeviceContext>(
    () =>
        page.props.device ?? {
            type: 'desktop',
            view: 'web',
            is_mobile: false,
            is_tablet: false,
            is_desktop: true,
        },
);
const loginShellClass = computed(() => [
    'login-shell min-h-screen px-4 py-6 sm:px-6 md:px-8',
    `device-${device.value.type}`,
    {
        'is-mobile': device.value.is_mobile,
        'is-tablet': device.value.is_tablet,
        'is-desktop': device.value.is_desktop,
    },
]);

onMounted(() => {
    const token = readStoredToken();

    if (token) {
        void restoreExistingSession(token);
    }
});

async function restoreExistingSession(token: string): Promise<void> {
    loading.value = true;

    try {
        await apiRequest<{ user: SessionUser }>('/api/auth/me', { token });
        goToConsole();
    } catch {
        removeStoredToken();
    } finally {
        loading.value = false;
    }
}

async function login(): Promise<void> {
    loading.value = true;
    notice.value = null;

    try {
        const response = await apiRequest<LoginResponse>('/api/auth/login', {
            method: 'POST',
            body: {
                username: loginForm.value.username,
                password: loginForm.value.password,
            },
        });
        storeToken(response.token);
        loginForm.value.password = '';
        goToConsole();
    } catch (error) {
        notice.value = {
            tone: 'error',
            message: messageFromError(error),
        };
    } finally {
        loading.value = false;
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
    <Head title="Login" />

    <main :class="loginShellClass" :data-device="device.type">
        <section
            class="login-card auth-layout rounded-2xl p-5 shadow-2xl sm:p-6"
            aria-label="Ngwe Lwe login"
        >
            <div class="auth-panel">
                <div class="login-brand">
                    <div class="brand-mark" aria-hidden="true">NL</div>
                    <div>
                        <h1>Ngwe Lwe System</h1>
                        <p>Operations Console</p>
                    </div>
                </div>

                <div class="auth-copy">
                    <h2>Welcome back</h2>
                    <p>
                        Sign in with one of the demo credentials below and start
                        using the operations console.
                    </p>
                </div>

                <div class="auth-credentials" aria-label="Demo accounts">
                    <div class="credential-card">
                        <span class="credential-role">Owner</span>
                        <strong>owner</strong>
                        <span>password123</span>
                        <span>PIN 1111</span>
                    </div>
                    <div class="credential-card">
                        <span class="credential-role">Cashier</span>
                        <strong>cashier</strong>
                        <span>password123</span>
                        <span>PIN 2222</span>
                    </div>
                    <div class="credential-card">
                        <span class="credential-role">Employee</span>
                        <strong>employee</strong>
                        <span>password123</span>
                        <span>PIN 3333</span>
                    </div>
                </div>
            </div>

            <div class="login-panel">
                <div v-if="notice" class="notice" :data-tone="notice.tone">
                    {{ notice.message }}
                </div>

                <form class="stack-form" @submit.prevent="login">
                    <label>
                        Username
                        <input
                            v-model="loginForm.username"
                            autocomplete="username"
                            autofocus
                        />
                    </label>
                    <label>
                        Password
                        <input
                            v-model="loginForm.password"
                            autocomplete="current-password"
                            type="password"
                        />
                    </label>
                    <button
                        type="submit"
                        class="primary-button"
                        :disabled="loading"
                    >
                        Login
                    </button>
                </form>
            </div>
        </section>
    </main>
</template>
