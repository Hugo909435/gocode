<template>
    <div v-if="settings.theme === 'hacker'" class="crt-overlay"></div>
    <RouterView />
</template>

<script setup>
import { RouterView } from 'vue-router';
import { useSettingsStore } from '@/stores/settings.js';

const settings = useSettingsStore();
</script>
