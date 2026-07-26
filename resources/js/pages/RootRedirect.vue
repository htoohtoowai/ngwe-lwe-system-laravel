<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { onMounted } from 'vue'
import { readStoredToken } from '@/lib/auth-token'

onMounted(() => {
  const token = readStoredToken()

  if (!token) {
    router.visit('/login')

    return
  }

  router.visit('/dashboard', {
    headers: {
      Authorization: `Bearer ${token}`,
    },
    replace: true,
  })
})
</script>

<template>
  <main class="grid min-h-screen place-items-center bg-canvas font-sans text-ink">
    <section class="rounded-2xl border border-line bg-card px-8 py-7 text-center shadow-sm">
      <span class="mx-auto grid size-11 place-items-center rounded-full bg-brand text-sm font-bold text-white">DP</span>
      <h1 class="mt-4 text-lg font-bold">ဒေါက်တာဖုန်း</h1>
      <p class="mt-1 text-sm font-semibold text-slate">Opening dashboard…</p>
    </section>
  </main>
</template>
