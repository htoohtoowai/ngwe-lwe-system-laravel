<script setup lang="ts">
import BankLayout from '@/layouts/BankLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type CashierSummary = {
    id: number;
    username: string;
    full_name: string;
    is_active: boolean;
};

type BranchRow = {
    id: number;
    code: string;
    name: string;
    address: string | null;
    phone: string | null;
    is_active: boolean;
    cashier?: CashierSummary | null;
    staff_count: number;
    active_staff_count: number;
    teller_count: number;
};

const props = defineProps<{
    role: 'admin';
    mode?: 'list' | 'detail' | 'create' | 'edit';
    resourceId?: number | null;
    announcement?: string | null;
    notificationCount?: number;
    adminData?: {
        branches?: BranchRow[];
    };
}>();

const mode = computed(() => props.mode ?? 'list');
const branches = computed(() => props.adminData?.branches ?? []);
const currentBranch = computed(
    () => branches.value.find((branch) => branch.id === props.resourceId) ?? null,
);
const search = ref('');

const filteredBranches = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) {
        return branches.value;
    }

    return branches.value.filter((branch) =>
        [
            branch.code,
            branch.name,
            branch.address ?? '',
            branch.phone ?? '',
            branch.cashier?.full_name ?? '',
        ].some((value) => value.toLowerCase().includes(needle)),
    );
});

const form = useForm({
    code: '',
    name: '',
    address: '',
    phone: '',
    is_active: true,
});

function fillForm(): void {
    const branch = currentBranch.value;

    if (mode.value === 'edit' && branch) {
        form.code = branch.code;
        form.name = branch.name;
        form.address = branch.address ?? '';
        form.phone = branch.phone ?? '';
        form.is_active = branch.is_active;

        return;
    }

    if (mode.value === 'create') {
        form.reset();
        form.is_active = true;
    }
}

watch(
    () => [mode.value, props.resourceId, branches.value.length],
    fillForm,
    { immediate: true },
);

function saveBranch(): void {
    const isEdit = mode.value === 'edit' && currentBranch.value !== null;
    const target = isEdit
        ? `/admin/branch-management/branches/${currentBranch.value?.id}`
        : '/admin/branch-management/branches';

    if (isEdit) {
        form.patch(target, { preserveScroll: true });
    } else {
        form.post(target, { preserveScroll: true });
    }
}

function toggleBranch(branch: BranchRow): void {
    router.patch(
        `/admin/branch-management/branches/${branch.id}/status`,
        { is_active: !branch.is_active },
        { preserveScroll: true },
    );
}
</script>

<template>
    <BankLayout
        role="admin"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <div class="mx-auto grid w-full max-w-7xl gap-5 p-4 sm:p-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-black tracking-[0.18em] text-slate uppercase">
                        Master Data
                    </p>
                    <h1 class="text-2xl font-black text-ink">Branches</h1>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        Each active branch can have one Cashier and multiple Tellers.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/users" class="bank-button bank-button-secondary">
                        Staff & Access
                    </Link>
                    <Link
                        v-if="mode === 'list'"
                        href="/admin/branches/create"
                        class="bank-button bank-button-primary"
                    >
                        Create Branch
                    </Link>
                    <Link
                        v-else
                        href="/admin/branches"
                        class="bank-button bank-button-secondary"
                    >
                        Branch List
                    </Link>
                </div>
            </header>

            <section
                v-if="mode === 'create' || mode === 'edit'"
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <h2 class="text-lg font-black text-ink">
                    {{ mode === 'edit' ? 'Update Branch' : 'Create Branch' }}
                </h2>

                <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="saveBranch">
                    <label>
                        <span class="bank-label">Branch Code</span>
                        <input
                            v-model.trim="form.code"
                            class="bank-input uppercase"
                            maxlength="32"
                            :readonly="currentBranch?.code === 'MAIN'"
                            required
                        />
                        <span v-if="form.errors.code" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.code }}
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">Branch Name</span>
                        <input v-model.trim="form.name" class="bank-input" required />
                        <span v-if="form.errors.name" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.name }}
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">Phone</span>
                        <input v-model.trim="form.phone" class="bank-input" />
                    </label>

                    <label class="md:col-span-2">
                        <span class="bank-label">Address</span>
                        <textarea v-model.trim="form.address" rows="3" class="bank-input resize-none" />
                    </label>

                    <label class="flex items-center gap-2 text-sm font-bold text-ink">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 accent-brand"
                            :disabled="currentBranch?.code === 'MAIN'"
                        />
                        Active
                    </label>

                    <div class="md:col-span-2">
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : mode === 'edit' ? 'Update Branch' : 'Save Branch' }}
                        </button>
                    </div>
                </form>
            </section>

            <section
                v-else-if="mode === 'detail'"
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-ink">Branch Detail</h2>
                    <Link
                        v-if="currentBranch"
                        :href="`/admin/branches/${currentBranch.id}/edit`"
                        class="bank-button bank-button-primary py-2"
                    >
                        Edit
                    </Link>
                </div>

                <dl v-if="currentBranch" class="mt-4 grid gap-4 rounded-lg bg-mist p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="font-bold text-slate">Code</dt>
                        <dd class="font-black text-ink">{{ currentBranch.code }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate">Name</dt>
                        <dd class="font-black text-ink">{{ currentBranch.name }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate">Status</dt>
                        <dd class="font-black text-ink">{{ currentBranch.is_active ? 'Active' : 'Inactive' }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate">Cashier</dt>
                        <dd class="font-black text-ink">{{ currentBranch.cashier?.full_name ?? 'Not assigned' }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate">Tellers</dt>
                        <dd class="font-black text-ink">{{ currentBranch.teller_count }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate">Active Staff</dt>
                        <dd class="font-black text-ink">{{ currentBranch.active_staff_count }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate">Phone</dt>
                        <dd class="font-black text-ink">{{ currentBranch.phone ?? '-' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-bold text-slate">Address</dt>
                        <dd class="font-black text-ink">{{ currentBranch.address ?? '-' }}</dd>
                    </div>
                </dl>
            </section>

            <section v-else class="rounded-xl border border-line bg-card p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <input
                        v-model.trim="search"
                        type="search"
                        class="bank-input max-w-md"
                        placeholder="Search branch, code, cashier or address"
                    />
                    <p class="text-sm font-bold text-slate">
                        {{ filteredBranches.length }} branches
                    </p>
                </div>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[920px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3">Cashier</th>
                                <th class="px-4 py-3 text-right">Tellers</th>
                                <th class="px-4 py-3 text-right">Active Staff</th>
                                <th class="px-4 py-3">Phone</th>
                                <th class="px-4 py-3 text-right">Action</th>
                                <th class="px-4 py-3 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="branch in filteredBranches" :key="branch.id">
                                <td class="px-4 py-3">
                                    <p class="font-black text-ink">{{ branch.name }}</p>
                                    <p class="text-xs font-bold text-slate">{{ branch.code }}</p>
                                </td>
                                <td class="px-4 py-3 font-semibold text-slate">
                                    {{ branch.cashier?.full_name ?? 'Not assigned' }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-ink">
                                    {{ branch.teller_count }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-ink">
                                    {{ branch.active_staff_count }}
                                </td>
                                <td class="px-4 py-3 text-slate">{{ branch.phone ?? '-' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Link
                                            :href="`/admin/branches/${branch.id}`"
                                            class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                        >
                                            View
                                        </Link>
                                        <Link
                                            :href="`/admin/branches/${branch.id}/edit`"
                                            class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                        >
                                            Edit
                                        </Link>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="rounded-pill px-3 py-1 text-xs font-black"
                                        :class="branch.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-mist text-slate'"
                                        :disabled="branch.code === 'MAIN'"
                                        @click="toggleBranch(branch)"
                                    >
                                        {{ branch.is_active ? 'Active' : 'Inactive' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
