<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const modules = [
    { label: 'Users & Roles', detail: 'Owner, cashier, employee access' },
    { label: 'Accounts', detail: 'Service accounts and balances' },
    { label: 'Transactions', detail: 'Cash In, Cash Out, Transfer, Exchange' },
    { label: 'Cash Float', detail: 'Employee mini-vault lifecycle' },
    { label: 'Main Vault', detail: 'MMK denomination inventory' },
    { label: 'Reports', detail: 'Daily summary and reconciliation' },
];

const migrationItems = [
    'Core schema migration created from Python database.sql',
    'Money normalization and MMK fee rounding ported',
    'Models and repositories foundation in progress',
    'Responsive Vue screens will replace PyQt workflows',
];
</script>

<template>
    <Head title="Ngwe Lwe System" />

    <div class="app-shell">
        <header class="masthead">
            <div class="masthead-left">
                <div class="brand-mark" aria-hidden="true">NL</div>
                <div class="brand-text">
                    <span class="brand-name">Ngwe Lwe System</span>
                    <span class="brand-sub">Laravel migration</span>
                </div>
            </div>

            <div class="masthead-right">
                <div class="meta">
                    <span class="meta-key">Source</span>
                    <span class="meta-val">Python</span>
                </div>
                <div class="meta">
                    <span class="meta-key">Target</span>
                    <span class="meta-val live">Laravel</span>
                </div>
            </div>
        </header>

        <main>
            <section class="overview">
                <div class="overview-copy">
                    <p class="eyebrow">Python to PHP Laravel</p>
                    <h1>Money-transfer operations, rebuilt for web.</h1>
                    <p class="lead">
                        This Laravel/Vue target is being migrated from the
                        existing PyQt6 + FastAPI Ngwe Lwe desktop system.
                    </p>
                </div>

                <div class="status-panel">
                    <span class="panel-label">Migration Status</span>
                    <strong>Foundation Started</strong>
                    <p>Schema, money rules, and active app direction are now Ngwe Lwe.</p>
                </div>
            </section>

            <section class="module-grid" aria-label="Ngwe Lwe modules">
                <article
                    v-for="module in modules"
                    :key="module.label"
                    class="module-card"
                >
                    <h2>{{ module.label }}</h2>
                    <p>{{ module.detail }}</p>
                </article>
            </section>

            <section class="migration-list" aria-label="Current migration checklist">
                <h2>Current Slice</h2>
                <ul>
                    <li v-for="item in migrationItems" :key="item">
                        {{ item }}
                    </li>
                </ul>
            </section>
        </main>

        <footer class="ground-line">
            <span>Ngwe Lwe System / owner.cashier.employee / MySQL</span>
        </footer>
    </div>
</template>
