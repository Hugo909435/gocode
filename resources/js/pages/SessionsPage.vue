<template>
    <div class="p-6 lg:p-8 selection:bg-primary selection:text-black">
        <!-- En-tête -->
        <div class="flex items-center justify-between mb-10 border-b border-primary-dim pb-6">
            <div class="flex items-center gap-4 min-w-0">
                <RouterLink
                    :to="{ name: 'projects' }"
                    class="text-primary/40 hover:text-primary transition-all shrink-0 hover:glow"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 19l-7-7 7-7"
                        />
                    </svg>
                </RouterLink>
                <div class="min-w-0">
                    <h2 class="text-3xl font-display theme-text text-primary glow truncate">
                        {{ settings.theme === 'hacker' ? 'Session_Log' : 'Sessions' }}
                    </h2>
                    <div
                        v-if="projectName"
                        class="flex items-center gap-2 text-[10px] text-primary/40 theme-text mt-1"
                    >
                        <span class="truncate"
                            >{{ settings.theme === 'hacker' ? 'Node' : 'Project' }}:
                            {{ projectName }}</span
                        >
                        <template v-if="repoName">
                            <span class="text-primary/20">|</span>
                            <div class="flex items-center gap-1 min-w-0">
                                <span class="truncate"
                                    >{{ settings.theme === 'hacker' ? 'Uplink' : 'GitHub' }}:
                                    {{ repoName }}</span
                                >
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <button
                class="flex items-center gap-2 px-4 py-2 bg-primary/10 border border-primary text-primary text-xs font-bold theme-text hover:bg-primary hover:text-black transition-all group rounded-[--radius-none]"
                @click="showCreate = true"
            >
                <svg
                    class="w-4 h-4 transition-transform group-hover:rotate-90"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 4v16m8-8H4"
                    />
                </svg>
                {{ settings.theme === 'hacker' ? 'Session' : 'New Session' }}
            </button>
        </div>

        <!-- Liste des sessions -->
        <div v-if="store.loading" class="text-primary/50 text-[10px] theme-text animate-pulse">
            {{ settings.theme === 'hacker' ? 'Accessing_Data_Streams...' : 'Loading sessions...' }}
        </div>
        <div
            v-else-if="store.sessions.length === 0"
            class="text-center py-20 border border-dashed border-primary/20 rounded-[--radius-none]"
        >
            <p class="text-primary/40 text-[10px] theme-text">
                {{
                    settings.theme === 'hacker'
                        ? 'No active session streams detected in this node.'
                        : 'No sessions found for this project.'
                }}
            </p>
        </div>
        <div v-else class="space-y-4">
            <RouterLink
                v-for="session in store.sessions"
                :key="session.id"
                :to="{ name: 'session', params: { id: session.id } }"
                class="block bg-bg-terminal border border-primary/20 p-5 hover:border-primary/60 hover:bg-primary/5 transition-all group relative overflow-hidden rounded-[--radius-none]"
            >
                <!-- Scanner line effect on hover -->
                <div
                    v-if="settings.theme === 'hacker'"
                    class="absolute inset-0 bg-gradient-to-b from-transparent via-primary/5 to-transparent -translate-y-full group-hover:translate-y-full transition-transform duration-1000"
                ></div>

                <div class="flex items-start justify-between gap-4 relative z-10">
                    <div class="flex-1 min-w-0">
                        <div class="mb-3">
                            <span
                                class="text-sm font-bold text-primary group-hover:glow transition-all theme-text"
                            >
                                {{
                                    session.title ||
                                    session.initial_instruction ||
                                    (settings.theme === 'hacker'
                                        ? 'UNTITLED_STREAM'
                                        : 'Untitled Session')
                                }}
                            </span>
                        </div>
                        <div class="flex items-center gap-4 flex-wrap">
                            <!-- Mode badge -->
                            <span
                                class="text-[9px] px-2 py-0.5 border font-bold theme-text rounded-[--radius-none]"
                                :class="modeBadgeClass(session.mode)"
                            >
                                {{ settings.theme === 'hacker' ? 'MODE::' : '' }}{{ session.mode }}
                            </span>
                            <!-- Status badge -->
                            <span
                                class="text-[9px] px-2 py-0.5 border font-bold theme-text rounded-[--radius-none]"
                                :class="statusBadgeClass(session.status)"
                            >
                                {{ settings.theme === 'hacker' ? 'STATUS::' : ''
                                }}{{ statusLabel(session.status) }}
                            </span>
                            <!-- Cost -->
                            <span
                                v-if="session.cost_usd > 0"
                                class="text-[9px] text-primary/40 font-mono theme-text"
                            >
                                {{ settings.theme === 'hacker' ? 'RESOURCE_DRAIN' : 'Cost' }}: ${{
                                    formatCost(session.cost_usd)
                                }}
                            </span>
                        </div>
                    </div>
                    <div class="text-[9px] text-primary/30 font-mono theme-text shrink-0 mt-1">
                        [{{ formatDate(session.created_at) }}]
                    </div>
                </div>
            </RouterLink>
        </div>

        <!-- Modal : nouvelle session -->
        <Teleport to="body">
            <div
                v-if="showCreate"
                class="fixed inset-0 bg-bg-terminal/80 backdrop-blur-md flex items-center justify-center z-50 p-4"
                @click.self="showCreate = false"
            >
                <div
                    class="bg-bg-terminal border border-primary w-full max-w-lg p-8 relative rounded-[--radius-none]"
                    :class="settings.theme === 'hacker' ? 'border-double' : ''"
                >
                    <h3 class="text-2xl font-display text-primary glow theme-text mb-6">
                        {{ settings.theme === 'hacker' ? 'Initialize_Stream' : 'New Session' }}
                    </h3>
                    <form class="space-y-6" @submit.prevent="submitCreate">
                        <!-- Config projet manquante -->
                        <div
                            v-if="!project?.path"
                            class="border border-warning/50 bg-warning/5 p-4 space-y-4 rounded-[--radius-none]"
                        >
                            <p class="text-[10px] text-warning font-bold theme-text">
                                {{
                                    settings.theme === 'hacker'
                                        ? '[!] PATH_NOT_DEFINED'
                                        : 'Project path not set'
                                }}
                            </p>
                            <div class="space-y-1">
                                <label
                                    class="block text-[9px] text-primary/60 theme-text font-bold"
                                    >{{
                                        settings.theme === 'hacker'
                                            ? 'Base_Directory'
                                            : 'Base Directory'
                                    }}</label
                                >
                                <input
                                    v-model="setup.baseDir"
                                    type="text"
                                    class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-xs font-mono focus:outline-none focus:border-primary rounded-[--radius-none]"
                                    :placeholder="
                                        settings.theme === 'hacker'
                                            ? '/ROOT/PROJECTS'
                                            : 'C:/Projects'
                                    "
                                />
                            </div>
                            <div class="space-y-1">
                                <label
                                    class="block text-[9px] text-primary/60 theme-text font-bold"
                                    >{{
                                        settings.theme === 'hacker'
                                            ? 'Registry_Name'
                                            : 'Folder Name'
                                    }}</label
                                >
                                <input
                                    v-model="setup.repoName"
                                    type="text"
                                    class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-xs font-mono focus:outline-none focus:border-primary rounded-[--radius-none]"
                                    :placeholder="
                                        settings.theme === 'hacker' ? 'MY_NODE' : 'my-project'
                                    "
                                />
                            </div>
                            <p v-if="setupFullPath" class="text-[9px] text-primary/30 font-mono">
                                TARGET: {{ setupFullPath }}
                            </p>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    v-model="setup.git_init"
                                    type="checkbox"
                                    class="w-4 h-4 bg-bg-terminal border-primary text-primary focus:ring-0 rounded-sm"
                                />
                                <span class="text-[10px] text-primary/60 theme-text font-bold"
                                    >Git Init</span
                                >
                            </label>
                        </div>

                        <!-- Titre optionnel -->
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-primary/60 theme-text">{{
                                settings.theme === 'hacker' ? 'Name' : 'Session Title'
                            }}</label>
                            <input
                                v-model="form.title"
                                type="text"
                                class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-sm focus:outline-none focus:border-primary transition-all placeholder:text-primary/10 rounded-[--radius-none]"
                                :placeholder="
                                    settings.theme === 'hacker' ? 'FIX_AUTH_CORE' : 'Bug fixes'
                                "
                            />
                        </div>

                        <!-- Mode -->
                        <div class="space-y-2">
                            <label class="block text-[10px] font-bold text-primary/60 theme-text">{{
                                settings.theme === 'hacker'
                                    ? 'Execution_Protocol'
                                    : 'Operation Mode'
                            }}</label>
                            <div class="grid grid-cols-3 gap-3">
                                <button
                                    v-for="m in modes"
                                    :key="m.value"
                                    type="button"
                                    class="flex flex-col items-center gap-1 px-2 py-3 border text-[10px] theme-text font-bold transition-all rounded-[--radius-none]"
                                    :class="
                                        form.mode === m.value
                                            ? 'border-primary bg-primary/20 text-primary glow'
                                            : 'border-primary/20 text-primary/40 hover:border-primary/40 hover:text-primary/60'
                                    "
                                    @click="form.mode = m.value"
                                >
                                    <span>{{ m.label }}</span>
                                    <span class="text-[8px] opacity-50 theme-text">{{
                                        m.desc
                                    }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Instruction initiale -->
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-primary/60 theme-text">{{
                                settings.theme === 'hacker'
                                    ? 'Primary_Directive'
                                    : 'Initial Instruction'
                            }}</label>
                            <textarea
                                v-model="form.initial_instruction"
                                rows="3"
                                class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-sm focus:outline-none focus:border-primary resize-none transition-all rounded-[--radius-none]"
                                :placeholder="
                                    settings.theme === 'hacker'
                                        ? 'DEFINE_TASK_PARAMETERS...'
                                        : 'What needs to be done?'
                                "
                            ></textarea>
                        </div>

                        <div
                            v-if="createError"
                            class="text-[10px] text-danger border border-danger/50 bg-danger/10 px-3 py-2 theme-text font-bold animate-pulse rounded-[--radius-none]"
                        >
                            {{ settings.theme === 'hacker' ? '[!] BUFFER_OVERFLOW:' : 'Error:' }}
                            {{ createError }}
                        </div>
                        <div class="flex justify-end gap-6 pt-2">
                            <button
                                type="button"
                                class="text-xs text-primary/40 hover:text-primary theme-text"
                                @click="showCreate = false"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="creating"
                                class="px-6 py-2 bg-primary/10 border border-primary text-primary text-xs font-bold theme-text hover:bg-primary hover:text-black transition-all rounded-[--radius-none]"
                            >
                                <span v-if="creating">{{
                                    settings.theme === 'hacker' ? 'Spawning...' : 'Creating...'
                                }}</span>
                                <span v-else>{{
                                    settings.theme === 'hacker'
                                        ? '[ EXECUTE_SPAWN ]'
                                        : 'Start Session'
                                }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter, RouterLink } from 'vue-router';
import { useSessionsStore } from '@/stores/sessions.js';
import { useProjectsStore } from '@/stores/projects.js';
import { useSettingsStore } from '@/stores/settings.js';

const route = useRoute();
const router = useRouter();
const store = useSessionsStore();
const projectsStore = useProjectsStore();
const settings = useSettingsStore();

const projectId = computed(() => route.params.id);
const project = computed(
    () => projectsStore.projects.find((p) => String(p.id) === String(projectId.value)) ?? null,
);
const projectName = computed(() => project.value?.name ?? null);

const repoName = computed(() => {
    const remote = project.value?.git_remote;
    if (!remote) return null;
    try {
        const url = new URL(remote);
        if (url.hostname === 'github.com') {
            return url.pathname.replace(/^\/|\.git$/g, '');
        }
    } catch (e) {}
    return remote;
});

const showCreate = ref(false);
const creating = ref(false);
const createError = ref('');
const form = ref({ title: '', mode: 'execute', initial_instruction: '' });
const setup = ref({ baseDir: '', repoName: '', git_init: false });
const setupFullPath = computed(() => {
    const base = setup.value.baseDir.replace(/\/+$/, '');
    const name = setup.value.repoName.trim();
    if (!base || !name) return null;
    return `${base}/${name}`;
});

const modes = [
    { value: 'read', label: 'READ', desc: 'NO_WRITE' },
    { value: 'plan', label: 'PLAN', desc: 'STRATEGY' },
    { value: 'execute', label: 'EXEC', desc: 'OVERWRITE' },
];

onMounted(async () => {
    if (!projectsStore.projects.length) {
        await projectsStore.fetchProjects();
    }
    await store.fetchSessions(projectId.value);
});

async function submitCreate() {
    creating.value = true;
    createError.value = '';
    try {
        if (!project.value?.path && setupFullPath.value) {
            await projectsStore.updateProject(projectId.value, {
                path: setupFullPath.value,
                git_init: setup.value.git_init,
            });
        }

        const session = await store.createSession(projectId.value, {
            title: form.value.title || null,
            mode: form.value.mode,
            initial_instruction: form.value.initial_instruction || null,
        });
        showCreate.value = false;
        form.value = { title: '', mode: 'execute', initial_instruction: '' };
        setup.value = { baseDir: '', repoName: '', git_init: false };
        router.push({ name: 'session', params: { id: session.id } });
    } catch (e) {
        createError.value = e.response?.data?.message ?? 'SYSTEM_ERR';
    } finally {
        creating.value = false;
    }
}

function modeBadgeClass(mode) {
    return (
        {
            read: 'border-primary/40 text-primary/60 bg-primary/5',
            plan: 'border-warning/40 text-warning/60 bg-warning/5',
            execute: 'border-primary text-primary bg-primary/10 glow',
        }[mode] ?? 'border-primary/20 text-primary/20'
    );
}

function statusBadgeClass(status) {
    return (
        {
            idle: 'border-primary/20 text-primary/40',
            reading: 'border-primary text-primary glow bg-primary/5',
            planning: 'border-warning text-warning glow bg-warning/5',
            building: 'border-primary text-primary glow animate-pulse',
            running: 'border-primary text-primary glow animate-pulse',
            awaiting_confirmation: 'border-warning text-warning glow bg-warning/10',
            done: 'border-primary text-primary glow bg-primary/10',
            error: 'border-danger text-danger glow bg-danger/10',
        }[status] ?? 'border-primary/20 text-primary/20'
    );
}

function statusLabel(status) {
    return (
        {
            idle: 'READY',
            reading: 'SCANNING',
            planning: 'PLANNING',
            building: 'CONSTRUCTING',
            running: 'EXECUTING',
            awaiting_confirmation: 'AWAIT_AUTH',
            done: 'TERMINATED',
            error: 'FAILED',
        }[status] ?? (status ? status.toUpperCase() : 'UNKNOWN')
    );
}

function formatCost(cost) {
    return parseFloat(cost).toFixed(4);
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toISOString().replace('T', ' ').substring(0, 19);
}
</script>
