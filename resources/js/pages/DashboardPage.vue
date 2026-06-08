<template>
  <div class="p-8">
    <h2 class="text-2xl font-bold text-white mb-2">Tableau de bord</h2>
    <p class="text-gray-400">Bienvenue, {{ auth.user?.name }}.</p>
    <div class="mt-8 text-gray-500 text-sm">— Aucune session active —</div>
  </div>
</template>

<script setup>
import { useAuthStore } from '@/stores/auth.js';

const auth = useAuthStore();
</script>
