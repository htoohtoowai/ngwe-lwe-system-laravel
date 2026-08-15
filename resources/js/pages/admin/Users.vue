<script setup lang="ts">import AdminOperationsPage from '@/components/admin/operations/AdminOperationsPage.vue'; defineProps(['role','mode','resourceId','announcement','notificationCount']);</script>
<template><AdminOperationsPage v-bind="$props" section="users" /></template>
