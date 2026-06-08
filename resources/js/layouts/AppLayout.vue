<template>
  <div class="min-h-screen bg-gray-950 text-gray-100 flex">
    <!-- Sidebar -->
    <aside class="w-64 bg-gray-900 border-r border-gray-800 flex flex-col">
      <div class="p-4 border-b border-gray-800">
        <h1 class="text-xl font-bold text-indigo-400">Cockpit</h1>
        <p class="text-xs text-gray-500 mt-0.5">Agent IA local</p>
      </div>
      <nav class="flex-1 p-3 space-y-1">
        <RouterLink
          :to="{ name: 'dashboard' }"
          class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:bg-gray-800 hover:text-white transition-colors"
          active-class="bg-gray-800 text-white"
        >
          Tableau de bord
        </RouterLink>
        <RouterLink
          :to="{ name: 'projects' }"
          class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:bg-gray-800 hover:text-white transition-colors"
          active-class="bg-gray-800 text-white"
        >
          Projets
        </RouterLink>
      </nav>
      <div class="p-3 border-t border-gray-800">
        <button
          class="w-full px-3 py-2 rounded-lg text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition-colors text-left"
          @click="auth.logout()"
        >
          Déconnexion
        </button>
      </div>
    </aside>

    <!-- Contenu principal -->
    <main class="flex-1 overflow-auto">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { RouterLink, RouterView } from 'vue-router';
import { useAuthStore } from '@/stores/auth.js';

const auth = useAuthStore();
</script>
