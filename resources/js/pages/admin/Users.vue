<script setup lang="ts">
import BankLayout from '@/layouts/BankLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Role = 'admin' | 'cashier' | 'teller';

type BranchRow = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type UserRow = {
    id: number;
    username: string;
    email: string | null;
    full_name: string;
    role: Role;
    branch_id: number | null;
    branch?: BranchRow | null;
    is_active: boolean;
    has_pin: boolean;
};

const props = defineProps<{
    role: 'admin';
    mode?: 'list' | 'detail' | 'create' | 'edit';
    resourceId?: number | null;
    announcement?: string | null;
    notificationCount?: number;
    adminData?: {
        users?: UserRow[];
        branches?: BranchRow[];
    };
}>();

const mode = computed(() => props.mode ?? 'list');
const users = computed(() => props.adminData?.users ?? []);
const branches = computed(() => props.adminData?.branches ?? []);
const activeBranches = computed(() => branches.value.filter((branch) => branch.is_active));
const currentUser = computed(
    () => users.value.find((user) => user.id === props.resourceId) ?? null,
);
const adminUser = computed(() => users.value.find((user) => user.role === 'admin') ?? null);
const search = ref('');

const filteredUsers = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) {
        return users.value;
    }

    return users.value.filter((user) =>
        [
            user.full_name,
            user.username,
            user.email ?? '',
            user.role,
            user.branch?.name ?? '',
            user.branch?.code ?? '',
        ].some((value) => value.toLowerCase().includes(needle)),
    );
});

const form = useForm({
    username: '',
    email: '',
    full_name: '',
    role: '' as Role | '',
    branch_id: null as number | null,
    password: '',
    pin: '',
    is_active: true,
});

const passwordForm = useForm({
    new_password: '',
});

const pinForm = useForm({
    pin: '',
});

function fillForm(): void {
    const user = currentUser.value;

    if (mode.value === 'edit' && user) {
        form.username = user.username;
        form.email = user.email ?? '';
        form.full_name = user.full_name;
        form.role = user.role;
        form.branch_id = user.branch_id;
        form.password = '';
        form.pin = '';
        form.is_active = user.is_active;

        return;
    }

    if (mode.value === 'create') {
        form.reset();
        form.password = 'password123';
        form.is_active = true;
    }
}

watch(
    () => [mode.value, props.resourceId, users.value.length],
    fillForm,
    { immediate: true },
);

watch(
    () => form.role,
    (role) => {
        if (role === 'admin') {
            form.branch_id = null;

            return;
        }

        if (role && form.branch_id === null && activeBranches.value.length > 0) {
            form.branch_id = activeBranches.value[0].id;
        }
    },
);

const selectedBranchCashier = computed(() => {
    if (form.branch_id === null) {
        return null;
    }

    return users.value.find(
        (user) =>
            user.role === 'cashier' &&
            user.branch_id === form.branch_id &&
            user.id !== currentUser.value?.id,
    ) ?? null;
});

const canChooseAdmin = computed(
    () => adminUser.value === null || adminUser.value.id === currentUser.value?.id,
);

function saveUser(): void {
    const isEdit = mode.value === 'edit' && currentUser.value !== null;
    const target = isEdit
        ? `/admin/branch-management/users/${currentUser.value?.id}`
        : '/admin/branch-management/users';

    const payload: Record<string, unknown> = {
        username: form.username,
        email: form.email || null,
        full_name: form.full_name,
        role: form.role,
        branch_id: form.role === 'admin' ? null : form.branch_id,
        is_active: form.is_active,
    };

    if (!isEdit || form.password) {
        payload.password = form.password;
    }

    if (form.pin) {
        payload.pin = form.pin;
    }

    form.transform(() => payload);

    if (isEdit) {
        form.patch(target, { preserveScroll: true });
    } else {
        form.post(target, { preserveScroll: true });
    }
}

function toggleUser(user: UserRow): void {
    router.patch(
        `/admin/branch-management/users/${user.id}/status`,
        { is_active: !user.is_active },
        { preserveScroll: true },
    );
}

function resetPassword(): void {
    if (!currentUser.value) return;

    passwordForm.post(
        `/admin/branch-management/users/${currentUser.value.id}/reset-password`,
        {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        },
    );
}

function setPin(): void {
    if (!currentUser.value) return;

    pinForm.post(
        `/admin/branch-management/users/${currentUser.value.id}/pin`,
        {
            preserveScroll: true,
            onSuccess: () => pinForm.reset(),
        },
    );
}

function branchLabel(user: UserRow): string {
    return user.role === 'admin'
        ? 'All Branches'
        : (user.branch?.name ?? 'Unassigned');
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
                        Staff & Access
                    </p>
                    <h1 class="text-2xl font-black text-ink">
                        Branch Staff
                    </h1>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        One Cashier per branch. Each branch can have multiple Tellers.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/admin/branches"
                        class="bank-button bank-button-secondary"
                    >
                        Manage Branches
                    </Link>
                    <Link
                        v-if="mode === 'list'"
                        href="/admin/users/create"
                        class="bank-button bank-button-primary"
                    >
                        Create Staff
                    </Link>
                    <Link
                        v-else
                        href="/admin/users"
                        class="bank-button bank-button-secondary"
                    >
                        Staff List
                    </Link>
                </div>
            </header>

            <section
                v-if="mode === 'create' || mode === 'edit'"
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <h2 class="text-lg font-black text-ink">
                    {{ mode === 'edit' ? 'Update Staff' : 'Create Staff' }}
                </h2>

                <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="saveUser">
                    <label>
                        <span class="bank-label">Username</span>
                        <input v-model.trim="form.username" class="bank-input" required />
                        <span v-if="form.errors.username" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.username }}
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">Full Name</span>
                        <input v-model.trim="form.full_name" class="bank-input" required />
                        <span v-if="form.errors.full_name" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.full_name }}
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">Email</span>
                        <input v-model.trim="form.email" type="email" class="bank-input" />
                        <span v-if="form.errors.email" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.email }}
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">Role</span>
                        <select
                            v-model="form.role"
                            class="bank-input"
                            :disabled="currentUser?.role === 'admin'"
                            required
                        >
                            <option value="" disabled>Select role</option>
                            <option value="teller">Teller</option>
                            <option value="cashier">Cashier</option>
                            <option value="admin" :disabled="!canChooseAdmin">Admin</option>
                        </select>
                        <span v-if="form.errors.role" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.role }}
                        </span>
                    </label>

                    <label v-if="form.role !== 'admin'">
                        <span class="bank-label">Branch</span>
                        <select v-model.number="form.branch_id" class="bank-input" required>
                            <option :value="null" disabled>Select branch</option>
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                                :disabled="
                                    !branch.is_active &&
                                    branch.id !== currentUser?.branch_id
                                "
                            >
                                {{ branch.name }} ({{ branch.code }})
                                {{ branch.is_active ? '' : ' - Inactive' }}
                            </option>
                        </select>
                        <span v-if="form.errors.branch_id" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.branch_id }}
                        </span>
                        <span
                            v-else-if="form.role === 'cashier' && selectedBranchCashier"
                            class="mt-1 block text-xs font-bold text-amber-700"
                        >
                            This branch already has Cashier {{ selectedBranchCashier.full_name }}.
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">Password</span>
                        <input
                            v-model="form.password"
                            type="password"
                            minlength="8"
                            class="bank-input"
                            :required="mode === 'create'"
                            :placeholder="mode === 'edit' ? 'Leave blank to keep current password' : ''"
                        />
                        <span v-if="form.errors.password" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.password }}
                        </span>
                    </label>

                    <label>
                        <span class="bank-label">PIN</span>
                        <input
                            v-model.trim="form.pin"
                            inputmode="numeric"
                            class="bank-input"
                            placeholder="4-8 digits"
                        />
                        <span v-if="form.errors.pin" class="mt-1 block text-xs font-bold text-red-600">
                            {{ form.errors.pin }}
                        </span>
                    </label>

                    <label class="flex items-center gap-2 text-sm font-bold text-ink">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 accent-brand"
                            :disabled="currentUser?.role === 'admin'"
                        />
                        Active
                    </label>

                    <div class="md:col-span-2">
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="
                                form.processing ||
                                !form.role ||
                                (form.role !== 'admin' && form.branch_id === null) ||
                                (form.role === 'cashier' && selectedBranchCashier !== null)
                            "
                        >
                            {{ form.processing ? 'Saving…' : mode === 'edit' ? 'Update Staff' : 'Save Staff' }}
                        </button>
                    </div>
                </form>
            </section>

            <section
                v-else-if="mode === 'detail'"
                class="grid gap-5 lg:grid-cols-[1fr_0.8fr]"
            >
                <div class="rounded-xl border border-line bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">Staff Detail</h2>
                        <Link
                            v-if="currentUser"
                            :href="`/admin/users/${currentUser.id}/edit`"
                            class="bank-button bank-button-primary py-2"
                        >
                            Edit
                        </Link>
                    </div>

                    <dl v-if="currentUser" class="mt-4 grid gap-4 rounded-lg bg-mist p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-bold text-slate">Name</dt>
                            <dd class="font-black text-ink">{{ currentUser.full_name }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Username</dt>
                            <dd class="font-black text-ink">{{ currentUser.username }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Role</dt>
                            <dd class="font-black text-ink capitalize">{{ currentUser.role }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Branch</dt>
                            <dd class="font-black text-ink">{{ branchLabel(currentUser) }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Email</dt>
                            <dd class="font-black text-ink">{{ currentUser.email ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Status</dt>
                            <dd class="font-black text-ink">{{ currentUser.is_active ? 'Active' : 'Inactive' }}</dd>
                        </div>
                    </dl>
                </div>

                <div v-if="currentUser" class="rounded-xl border border-line bg-card p-5 shadow-sm">
                    <h2 class="text-lg font-black text-ink">Credentials</h2>

                    <form class="mt-4 grid gap-3" @submit.prevent="resetPassword">
                        <label>
                            <span class="bank-label">New Password</span>
                            <input
                                v-model="passwordForm.new_password"
                                type="password"
                                minlength="8"
                                class="bank-input"
                                required
                            />
                        </label>
                        <button
                            type="submit"
                            class="bank-button bank-button-danger"
                            :disabled="passwordForm.processing || passwordForm.new_password.length < 8"
                        >
                            Reset Password
                        </button>
                    </form>

                    <form class="mt-5 grid gap-3 border-t border-line pt-5" @submit.prevent="setPin">
                        <label>
                            <span class="bank-label">New PIN</span>
                            <input
                                v-model.trim="pinForm.pin"
                                inputmode="numeric"
                                class="bank-input"
                                required
                            />
                        </label>
                        <button
                            type="submit"
                            class="bank-button bank-button-secondary"
                            :disabled="pinForm.processing || pinForm.pin.length < 4"
                        >
                            Set PIN
                        </button>
                    </form>
                </div>
            </section>

            <section v-else class="rounded-xl border border-line bg-card p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <input
                        v-model.trim="search"
                        type="search"
                        class="bank-input max-w-md"
                        placeholder="Search staff, branch, username or role"
                    />
                    <p class="text-sm font-bold text-slate">
                        {{ filteredUsers.length }} staff
                    </p>
                </div>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Username</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3">PIN</th>
                                <th class="px-4 py-3 text-right">Action</th>
                                <th class="px-4 py-3 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="user in filteredUsers" :key="user.id">
                                <td class="px-4 py-3">
                                    <p class="font-bold text-ink">{{ user.full_name }}</p>
                                    <p class="text-xs font-semibold text-slate">{{ user.email ?? '-' }}</p>
                                </td>
                                <td class="px-4 py-3 font-semibold text-slate">{{ user.username }}</td>
                                <td class="px-4 py-3 font-bold text-ink capitalize">{{ user.role }}</td>
                                <td class="px-4 py-3 font-semibold text-slate">{{ branchLabel(user) }}</td>
                                <td class="px-4 py-3 text-slate">{{ user.has_pin ? 'Set' : 'Missing' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Link
                                            :href="`/admin/users/${user.id}`"
                                            class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                        >
                                            View
                                        </Link>
                                        <Link
                                            :href="`/admin/users/${user.id}/edit`"
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
                                        :class="user.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-mist text-slate'"
                                        :disabled="user.role === 'admin'"
                                        @click="toggleUser(user)"
                                    >
                                        {{ user.is_active ? 'Active' : 'Inactive' }}
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
