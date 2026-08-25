<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { useLocale } from '@/lib/i18n';

type AuditRow = {
    id: number;
    user_id: number | null;
    actor_name: string | null;
    actor_role: string | null;
    action: string;
    category: string | null;
    module: string | null;
    status: string | null;
    entity_type: string | null;
    entity_id: number | null;
    description: string | null;
    details: Record<string, unknown> | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    changed_fields: string[] | null;
    ip_address: string | null;
    user_agent: string | null;
    route: string | null;
    http_method: string | null;
    request_id: string | null;
    failure_reason: string | null;
    created_at: string | null;
};

type FilterState = {
    search: string;
    user_id: string | number | null;
    role: string;
    category: string;
    action: string;
    module: string;
    status: string;
    date_from: string;
    date_to: string;
};

const props = defineProps<{
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    rows: AuditRow[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: FilterState;
    users: Array<{
        id: number;
        full_name: string | null;
        username: string;
        role: string;
    }>;
    filterOptions: {
        categories: string[];
        actions: string[];
        modules: string[];
        roles: string[];
        statuses: string[];
    };
}>();

const { lang } = useLocale();
const isMm = computed(() => lang.value === 'mm');
const text = (en: string, mm: string) => (isMm.value ? mm : en);

const filters = ref<FilterState>({
    search: props.filters?.search ?? '',
    user_id: props.filters?.user_id ?? '',
    role: props.filters?.role ?? '',
    category: props.filters?.category ?? '',
    action: props.filters?.action ?? '',
    module: props.filters?.module ?? '',
    status: props.filters?.status ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
});

const selected = ref<AuditRow | null>(null);

function cleanedFilters(extra: Record<string, unknown> = {}) {
    return Object.fromEntries(
        Object.entries({ ...filters.value, ...extra }).filter(
            ([, value]) => value !== '' && value !== null && value !== undefined,
        ),
    );
}

function applyFilters(): void {
    router.get('/admin/audit-logs', cleanedFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters(): void {
    filters.value = {
        search: '',
        user_id: '',
        role: '',
        category: '',
        action: '',
        module: '',
        status: '',
        date_from: '',
        date_to: '',
    };
    router.get('/admin/audit-logs', {}, { replace: true });
}

function goToPage(page: number): void {
    if (page < 1 || page > props.pagination.last_page) return;
    router.get('/admin/audit-logs', cleanedFilters({ page }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const exportHref = computed(() => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(cleanedFilters())) {
        params.set(key, String(value));
    }
    const suffix = params.toString();
    return `/admin/audit-logs/export${suffix ? `?${suffix}` : ''}`;
});

function dateTime(value: string | null): string {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(isMm.value ? 'my-MM' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function human(value: string | null): string {
    if (!value) return '-';
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status: string | null): string {
    return status === 'failed'
        ? 'border-red-200 bg-red-50 text-red-700'
        : status === 'success'
          ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
          : 'border-amber-200 bg-amber-50 text-amber-700';
}

function actionClass(action: string): string {
    if (action.includes('delete') || action.includes('failed')) {
        return 'border-red-200 bg-red-50 text-red-700';
    }
    if (action.includes('create') || action === 'login') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }
    if (action.includes('update') || action.includes('change')) {
        return 'border-blue-200 bg-blue-50 text-blue-700';
    }
    return 'border-slate-200 bg-slate-50 text-slate-700';
}

function json(value: unknown): string {
    if (value === null || value === undefined) return '-';
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function hasObjectData(value: Record<string, unknown> | null): boolean {
    return !!value && Object.keys(value).length > 0;
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <div class="space-y-5">
            <section class="rounded-xl border border-line bg-card p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-bold tracking-[0.16em] text-slate uppercase">
                            {{ text('Security & compliance', 'လုံခြုံရေးနှင့် စစ်ဆေးမှု') }}
                        </p>
                        <h1 class="mt-1 text-2xl font-black text-ink">
                            {{ text('System Activity Audit', 'စနစ်လုပ်ဆောင်ချက် စစ်ဆေးမှတ်တမ်း') }}
                        </h1>
                        <p class="mt-2 max-w-3xl text-sm font-medium text-slate">
                            {{
                                text(
                                    'Immutable audit trail for authentication, CRUD, permissions, master data and sensitive operational actions.',
                                    'Login/Logout၊ CRUD၊ အခွင့်အရေး၊ Master Data နှင့် အရေးကြီးလုပ်ဆောင်ချက်များကို ပြင်/ဖျက်မရသော audit trail အဖြစ် စစ်ဆေးနိုင်ပါသည်။',
                                )
                            }}
                        </p>
                    </div>
                    <a
                        :href="exportHref"
                        class="bank-button bank-button-secondary shrink-0"
                    >
                        {{ text('Export CSV', 'CSV ထုတ်ယူရန်') }}
                    </a>
                </div>
            </section>

            <section class="rounded-xl border border-line bg-card p-4 shadow-sm sm:p-5">
                <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="applyFilters">
                    <label class="md:col-span-2 xl:col-span-2">
                        <span class="bank-label">{{ text('Search', 'ရှာဖွေရန်') }}</span>
                        <input
                            v-model.trim="filters.search"
                            class="bank-input"
                            :placeholder="text('User, action, module, request ID, IP…', 'User၊ action၊ module၊ request ID၊ IP…')"
                        />
                    </label>

                    <label>
                        <span class="bank-label">{{ text('User', 'အသုံးပြုသူ') }}</span>
                        <select v-model="filters.user_id" class="bank-input">
                            <option value="">{{ text('All users', 'အသုံးပြုသူအားလုံး') }}</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">
                                {{ user.full_name || user.username }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">{{ text('Role', 'Role') }}</span>
                        <select v-model="filters.role" class="bank-input">
                            <option value="">{{ text('All roles', 'Role အားလုံး') }}</option>
                            <option v-for="value in filterOptions.roles" :key="value" :value="value">
                                {{ human(value) }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">{{ text('Result', 'ရလဒ်') }}</span>
                        <select v-model="filters.status" class="bank-input">
                            <option value="">{{ text('All results', 'ရလဒ်အားလုံး') }}</option>
                            <option v-for="value in filterOptions.statuses" :key="value" :value="value">
                                {{ human(value) }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">{{ text('Category', 'အုပ်စု') }}</span>
                        <select v-model="filters.category" class="bank-input">
                            <option value="">{{ text('All categories', 'အုပ်စုအားလုံး') }}</option>
                            <option v-for="value in filterOptions.categories" :key="value" :value="value">
                                {{ human(value) }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">{{ text('Action', 'လုပ်ဆောင်ချက်') }}</span>
                        <select v-model="filters.action" class="bank-input">
                            <option value="">{{ text('All actions', 'လုပ်ဆောင်ချက်အားလုံး') }}</option>
                            <option v-for="value in filterOptions.actions" :key="value" :value="value">
                                {{ human(value) }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">{{ text('Module', 'Module') }}</span>
                        <select v-model="filters.module" class="bank-input">
                            <option value="">{{ text('All modules', 'Module အားလုံး') }}</option>
                            <option v-for="value in filterOptions.modules" :key="value" :value="value">
                                {{ human(value) }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">{{ text('From date', 'စသည့်ရက်') }}</span>
                        <input v-model="filters.date_from" type="date" class="bank-input" />
                    </label>

                    <label>
                        <span class="bank-label">{{ text('To date', 'ဆုံးသည့်ရက်') }}</span>
                        <input v-model="filters.date_to" type="date" class="bank-input" />
                    </label>

                    <div class="flex items-end gap-2 md:col-span-2 xl:col-span-5">
                        <button type="submit" class="bank-button bank-button-primary">
                            {{ text('Apply filters', 'Filter အသုံးပြုရန်') }}
                        </button>
                        <button type="button" class="bank-button bank-button-secondary" @click="clearFilters">
                            {{ text('Clear', 'ရှင်းရန်') }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-line bg-card shadow-sm">
                <div class="flex items-center justify-between border-b border-line px-4 py-3 sm:px-5">
                    <div>
                        <h2 class="font-black text-ink">{{ text('Audit events', 'Audit ဖြစ်စဉ်များ') }}</h2>
                        <p class="text-xs font-semibold text-slate">
                            {{ pagination.total.toLocaleString() }} {{ text('events', 'မှတ်တမ်း') }}
                        </p>
                    </div>
                    <p v-if="pagination.from !== null" class="text-xs font-bold text-slate">
                        {{ pagination.from }}–{{ pagination.to }} / {{ pagination.total }}
                    </p>
                </div>

                <!-- Mobile cards -->
                <div class="divide-y divide-line md:hidden">
                    <button
                        v-for="row in rows"
                        :key="row.id"
                        type="button"
                        class="block w-full p-4 text-left transition hover:bg-mist/60"
                        @click="selected = row"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-black text-ink">
                                    {{ row.actor_name || text('Unknown user', 'အမည်မသိအသုံးပြုသူ') }}
                                </p>
                                <p class="mt-0.5 text-xs font-semibold text-slate">
                                    {{ dateTime(row.created_at) }} · {{ human(row.actor_role) }}
                                </p>
                            </div>
                            <span
                                class="rounded-full border px-2 py-1 text-[10px] font-black uppercase"
                                :class="statusClass(row.status)"
                            >
                                {{ row.status || 'success' }}
                            </span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-full border px-2 py-1 text-[10px] font-black" :class="actionClass(row.action)">
                                {{ human(row.action) }}
                            </span>
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-700">
                                {{ human(row.module) }}
                            </span>
                        </div>
                        <p class="mt-3 line-clamp-2 text-sm font-semibold text-slate">
                            {{ row.description || `${human(row.entity_type)} #${row.entity_id ?? '-'}` }}
                        </p>
                    </button>
                    <div v-if="rows.length === 0" class="p-8 text-center text-sm font-semibold text-slate">
                        {{ text('No audit events match these filters.', 'သတ်မှတ်ထားသော filter နှင့် ကိုက်ညီသည့် audit မှတ်တမ်း မရှိပါ။') }}
                    </div>
                </div>

                <!-- Tablet/Desktop table -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[1120px] text-left text-sm">
                        <thead class="bg-mist text-[11px] font-black tracking-wide text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">{{ text('Time', 'အချိန်') }}</th>
                                <th class="px-4 py-3">{{ text('User / Role', 'အသုံးပြုသူ / Role') }}</th>
                                <th class="px-4 py-3">{{ text('Action', 'လုပ်ဆောင်ချက်') }}</th>
                                <th class="px-4 py-3">{{ text('Module', 'Module') }}</th>
                                <th class="px-4 py-3">{{ text('Record', 'Record') }}</th>
                                <th class="px-4 py-3">{{ text('Result', 'ရလဒ်') }}</th>
                                <th class="px-4 py-3">{{ text('Source', 'Source') }}</th>
                                <th class="px-4 py-3 text-right">{{ text('Details', 'အသေးစိတ်') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in rows" :key="row.id" class="hover:bg-mist/40">
                                <td class="whitespace-nowrap px-4 py-3 text-xs font-semibold text-slate">
                                    {{ dateTime(row.created_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-black text-ink">{{ row.actor_name || '-' }}</p>
                                    <p class="text-xs font-semibold text-slate">{{ human(row.actor_role) }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full border px-2 py-1 text-[10px] font-black" :class="actionClass(row.action)">
                                        {{ human(row.action) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-bold text-ink">{{ human(row.module) }}</p>
                                    <p class="text-xs font-semibold text-slate">{{ human(row.category) }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-bold text-ink">{{ human(row.entity_type) }}</p>
                                    <p class="text-xs font-semibold text-slate">#{{ row.entity_id ?? '-' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full border px-2 py-1 text-[10px] font-black uppercase" :class="statusClass(row.status)">
                                        {{ row.status || 'success' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs font-semibold text-slate">
                                    <p>{{ row.ip_address || '-' }}</p>
                                    <p class="mt-1 max-w-[180px] truncate">{{ row.http_method || '-' }} · {{ row.route || '-' }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="bank-button bank-button-secondary px-3 py-2" @click="selected = row">
                                        {{ text('View', 'ကြည့်ရန်') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="rows.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center font-semibold text-slate">
                                    {{ text('No audit events match these filters.', 'သတ်မှတ်ထားသော filter နှင့် ကိုက်ညီသည့် audit မှတ်တမ်း မရှိပါ။') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-line px-4 py-3 sm:px-5">
                    <button
                        type="button"
                        class="bank-button bank-button-secondary"
                        :disabled="pagination.current_page <= 1"
                        @click="goToPage(pagination.current_page - 1)"
                    >
                        {{ text('Previous', 'ရှေ့') }}
                    </button>
                    <p class="text-xs font-black text-slate">
                        {{ text('Page', 'စာမျက်နှာ') }} {{ pagination.current_page }} / {{ pagination.last_page }}
                    </p>
                    <button
                        type="button"
                        class="bank-button bank-button-secondary"
                        :disabled="pagination.current_page >= pagination.last_page"
                        @click="goToPage(pagination.current_page + 1)"
                    >
                        {{ text('Next', 'နောက်') }}
                    </button>
                </div>
            </section>
        </div>

        <div
            v-if="selected"
            class="fixed inset-0 z-[80] flex items-end justify-center bg-black/45 p-0 sm:items-center sm:p-5"
            @click.self="selected = null"
        >
            <section class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-card shadow-2xl sm:max-w-4xl sm:rounded-2xl">
                <div class="sticky top-0 z-10 flex items-start justify-between border-b border-line bg-card px-5 py-4">
                    <div>
                        <p class="text-xs font-black tracking-wide text-slate uppercase">{{ text('Audit event', 'Audit မှတ်တမ်း') }} #{{ selected.id }}</p>
                        <h2 class="mt-1 text-xl font-black text-ink">{{ selected.description || human(selected.action) }}</h2>
                    </div>
                    <button type="button" class="bank-button bank-button-secondary px-3 py-2" @click="selected = null">
                        {{ text('Close', 'ပိတ်ရန်') }}
                    </button>
                </div>

                <div class="grid gap-4 p-5 lg:grid-cols-2">
                    <div class="rounded-xl border border-line p-4">
                        <h3 class="font-black text-ink">{{ text('Event', 'ဖြစ်စဉ်') }}</h3>
                        <dl class="mt-3 grid grid-cols-[130px_1fr] gap-x-3 gap-y-2 text-sm">
                            <dt class="font-bold text-slate">{{ text('Time', 'အချိန်') }}</dt><dd class="font-semibold text-ink">{{ dateTime(selected.created_at) }}</dd>
                            <dt class="font-bold text-slate">{{ text('User', 'အသုံးပြုသူ') }}</dt><dd class="font-semibold text-ink">{{ selected.actor_name || '-' }}</dd>
                            <dt class="font-bold text-slate">Role</dt><dd class="font-semibold text-ink">{{ human(selected.actor_role) }}</dd>
                            <dt class="font-bold text-slate">{{ text('Action', 'လုပ်ဆောင်ချက်') }}</dt><dd class="font-semibold text-ink">{{ human(selected.action) }}</dd>
                            <dt class="font-bold text-slate">{{ text('Category', 'အုပ်စု') }}</dt><dd class="font-semibold text-ink">{{ human(selected.category) }}</dd>
                            <dt class="font-bold text-slate">Module</dt><dd class="font-semibold text-ink">{{ human(selected.module) }}</dd>
                            <dt class="font-bold text-slate">{{ text('Result', 'ရလဒ်') }}</dt><dd class="font-semibold text-ink">{{ human(selected.status) }}</dd>
                            <dt class="font-bold text-slate">{{ text('Record', 'Record') }}</dt><dd class="font-semibold text-ink">{{ human(selected.entity_type) }} #{{ selected.entity_id ?? '-' }}</dd>
                        </dl>
                    </div>

                    <div class="rounded-xl border border-line p-4">
                        <h3 class="font-black text-ink">{{ text('Request metadata', 'Request အချက်အလက်') }}</h3>
                        <dl class="mt-3 grid grid-cols-[130px_1fr] gap-x-3 gap-y-2 break-all text-sm">
                            <dt class="font-bold text-slate">IP</dt><dd class="font-semibold text-ink">{{ selected.ip_address || '-' }}</dd>
                            <dt class="font-bold text-slate">Route</dt><dd class="font-semibold text-ink">{{ selected.route || '-' }}</dd>
                            <dt class="font-bold text-slate">HTTP</dt><dd class="font-semibold text-ink">{{ selected.http_method || '-' }}</dd>
                            <dt class="font-bold text-slate">Request ID</dt><dd class="font-mono text-xs font-semibold text-ink">{{ selected.request_id || '-' }}</dd>
                            <dt class="font-bold text-slate">User Agent</dt><dd class="text-xs font-semibold text-ink">{{ selected.user_agent || '-' }}</dd>
                        </dl>
                    </div>

                    <div v-if="selected.failure_reason" class="rounded-xl border border-red-200 bg-red-50 p-4 lg:col-span-2">
                        <h3 class="font-black text-red-800">{{ text('Failure reason', 'မအောင်မြင်သည့်အကြောင်း') }}</h3>
                        <p class="mt-2 text-sm font-semibold text-red-700">{{ selected.failure_reason }}</p>
                    </div>

                    <div v-if="selected.changed_fields?.length" class="rounded-xl border border-line p-4 lg:col-span-2">
                        <h3 class="font-black text-ink">{{ text('Changed fields', 'ပြောင်းလဲထားသော fields') }}</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="field in selected.changed_fields" :key="field" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-black text-blue-700">
                                {{ field }}
                            </span>
                        </div>
                    </div>

                    <div v-if="hasObjectData(selected.old_values)" class="rounded-xl border border-red-100 bg-red-50/50 p-4">
                        <h3 class="font-black text-red-800">{{ text('Before', 'မပြောင်းမီ') }}</h3>
                        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap break-words text-xs font-semibold text-slate-800">{{ json(selected.old_values) }}</pre>
                    </div>

                    <div v-if="hasObjectData(selected.new_values)" class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4">
                        <h3 class="font-black text-emerald-800">{{ text('After', 'ပြောင်းပြီး') }}</h3>
                        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap break-words text-xs font-semibold text-slate-800">{{ json(selected.new_values) }}</pre>
                    </div>

                    <div v-if="hasObjectData(selected.details)" class="rounded-xl border border-line p-4 lg:col-span-2">
                        <h3 class="font-black text-ink">{{ text('Additional details', 'ထပ်ဆောင်းအသေးစိတ်') }}</h3>
                        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap break-words text-xs font-semibold text-slate-800">{{ json(selected.details) }}</pre>
                    </div>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
