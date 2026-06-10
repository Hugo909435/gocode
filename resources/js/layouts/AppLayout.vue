<template>
    <div
        class="bg-bg-terminal text-primary flex h-screen overflow-hidden selection:bg-primary selection:text-black"
    >
        <!-- Sidebar (desktop uniquement) -->
        <aside
            class="hidden lg:flex w-64 bg-bg-terminal border-r border-primary-dim flex-col shrink-0"
        >
            <div class="p-4 border-b border-primary-dim flex items-center gap-3">
                <div
                    v-if="settings.theme === 'hacker'"
                    class="w-8 h-8 flex items-center justify-center border border-primary glow-box"
                >
                    <span class="font-display text-xl leading-none">>_</span>
                </div>
                <div>
                    <h1 class="text-xl font-display theme-text glow">
                        {{ settings.theme === 'hacker' ? 'gocode' : 'GoCode' }}
                    </h1>
                    <p class="text-[10px] text-primary/60 theme-text">
                        {{ settings.theme === 'hacker' ? 'System: Online' : 'Cloud Assistant' }}
                    </p>
                </div>
            </div>

            <nav class="p-3 space-y-1 shrink-0">
                <RouterLink
                    :to="{ name: 'projects' }"
                    class="flex items-center gap-2 px-3 py-2 text-sm text-primary/70 hover:text-primary transition-all duration-300 relative group rounded-[--radius-none]"
                    active-class="text-primary glow !text-primary bg-primary/5"
                >
                    <span
                        v-if="settings.theme === 'hacker'"
                        class="opacity-0 group-[.router-link-active]:opacity-100 transition-opacity"
                        >>></span
                    >
                    <span class="theme-text">{{
                        settings.theme === 'hacker' ? 'Projets' : 'Projects'
                    }}</span>
                </RouterLink>
                <RouterLink
                    :to="{ name: 'settings' }"
                    class="flex items-center gap-2 px-3 py-2 text-sm text-primary/70 hover:text-primary transition-all duration-300 relative group rounded-[--radius-none]"
                    active-class="text-primary glow !text-primary bg-primary/5"
                >
                    <span
                        v-if="settings.theme === 'hacker'"
                        class="opacity-0 group-[.router-link-active]:opacity-100 transition-opacity"
                        >>></span
                    >
                    <span class="theme-text">{{
                        settings.theme === 'hacker' ? 'Paramètres' : 'Settings'
                    }}</span>
                </RouterLink>
            </nav>

            <!-- Sessions récemment ouvertes -->
            <div
                v-if="sessionsStore.openSessions.length > 0"
                class="flex-1 overflow-y-auto px-3 py-2 border-t border-primary-dim"
            >
                <p class="text-[10px] font-bold text-primary/40 theme-text px-2 mb-2">
                    {{ settings.theme === 'hacker' ? 'Active_Nodes' : 'Recent Sessions' }}
                </p>
                <div class="space-y-0.5">
                    <RouterLink
                        v-for="s in sessionsStore.openSessions"
                        :key="s.id"
                        :to="{ name: 'session', params: { id: s.id } }"
                        class="flex items-center gap-2 px-2 py-2 border border-transparent hover:border-primary-dim hover:bg-primary/5 transition-all group rounded-[--radius-none]"
                        active-class="border-primary-dim bg-primary/10 !text-primary"
                    >
                        <!-- Indicateur de statut -->
                        <div
                            class="w-1.5 h-1.5 shrink-0 rounded-full"
                            :class="[
                                statusDotClass(s.status),
                                isActiveStatus(s.status) ? 'animate-pulse' : '',
                            ]"
                        ></div>
                        <div class="flex-1 min-w-0">
                            <p
                                class="text-[11px] truncate leading-tight opacity-80 group-hover:opacity-100 transition-opacity"
                            >
                                {{ s.title || s.initial_instruction || 'Session' }}
                            </p>
                            <p class="text-[9px] text-primary/40 truncate theme-text">
                                {{ s.project?.name ?? 'Unknown' }}
                            </p>
                        </div>
                    </RouterLink>
                </div>
            </div>
            <div v-else class="flex-1 border-t border-primary-dim"></div>

            <div class="p-3 border-t border-primary-dim shrink-0">
                <button
                    class="w-full px-3 py-2 text-xs text-primary/50 hover:text-danger hover:bg-danger/5 transition-all text-left theme-text rounded-[--radius-none]"
                    @click="auth.logout()"
                >
                    {{ settings.theme === 'hacker' ? '[ TERMINATE_SESSION ]' : 'Logout' }}
                </button>
            </div>
        </aside>

        <!-- Colonne contenu -->
        <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
            <!-- Contenu principal -->
            <main class="flex-1 overflow-y-auto bg-bg-terminal">
                <RouterView />
            </main>

            <!-- Navigation bas (mobile uniquement) -->
            <nav
                class="lg:hidden shrink-0 flex items-stretch bg-bg-terminal border-t border-primary-dim h-14"
            >
                <RouterLink
                    :to="{ name: 'projects' }"
                    class="flex-1 flex flex-col items-center justify-center gap-0.5 text-primary/50 transition-colors"
                    active-class="text-primary glow"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"
                        />
                    </svg>
                    <span class="text-[9px] leading-none theme-text">{{
                        settings.theme === 'hacker' ? 'Nodes' : 'Projects'
                    }}</span>
                </RouterLink>

                <RouterLink
                    :to="{ name: 'settings' }"
                    class="flex-1 flex flex-col items-center justify-center gap-0.5 text-primary/50 transition-colors"
                    active-class="text-primary glow"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                        />
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                        />
                    </svg>
                    <span class="text-[9px] leading-none theme-text">{{
                        settings.theme === 'hacker' ? 'Config' : 'Settings'
                    }}</span>
                </RouterLink>

                <button
                    class="flex-1 flex flex-col items-center justify-center gap-0.5 text-primary/50 transition-colors"
                    @click="auth.logout()"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                        />
                    </svg>
                    <span class="text-[9px] leading-none theme-text">{{
                        settings.theme === 'hacker' ? 'Exit' : 'Logout'
                    }}</span>
                </button>
            </nav>
        </div>
    </div>
</template>

<script setup>
import { RouterLink, RouterView } from 'vue-router';
import { useAuthStore } from '@/stores/auth.js';
import { useSessionsStore } from '@/stores/sessions.js';
import { useSettingsStore } from '@/stores/settings.js';

const auth = useAuthStore();
const sessionsStore = useSessionsStore();
const settings = useSettingsStore();

const activeStatuses = new Set([
    'reading',
    'planning',
    'building',
    'running',
    'awaiting_confirmation',
]);

function isActiveStatus(status) {
    return activeStatuses.has(status);
}

function statusDotClass(status) {
    return (
        {
            idle: 'bg-primary/20 border border-primary/40',
            reading: 'bg-primary shadow-[0_0_8px_rgba(0,255,65,0.6)]',
            planning: 'bg-warning shadow-[0_0_8px_rgba(243,255,0,0.6)]',
            building: 'bg-primary shadow-[0_0_8px_rgba(0,255,65,0.6)]',
            running: 'bg-primary shadow-[0_0_8px_rgba(0,255,65,0.6)]',
            awaiting_confirmation: 'bg-warning shadow-[0_0_8px_rgba(243,255,0,0.6)]',
            done: 'bg-primary shadow-[0_0_8px_rgba(0,255,65,0.6)]',
            error: 'bg-danger shadow-[0_0_8px_rgba(255,0,60,0.6)]',
        }[status] ?? 'bg-primary/20'
    );
}
</script>
