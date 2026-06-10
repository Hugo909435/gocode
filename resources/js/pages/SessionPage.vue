<template>
    <div class="flex flex-col h-full overflow-hidden">
        <SessionPanel
            :session-id="sessionId"
            :show-split-action="true"
            @split="showSplitPicker = true"
        />

        <!-- ─── Modal : sélecteur de session parallèle ──────────────── -->
        <Teleport to="body">
            <div
                v-if="showSplitPicker"
                class="fixed inset-0 bg-bg-terminal/80 backdrop-blur-md flex items-center justify-center z-50 p-4"
                @click.self="closePicker"
            >
                <div
                    class="bg-bg-terminal border border-primary border-double w-full max-w-lg flex flex-col max-h-[80vh] relative"
                >
                    <!-- En-tête -->
                    <div
                        class="flex items-center justify-between px-6 py-5 border-b border-primary/20 shrink-0"
                    >
                        <div>
                            <h3
                                class="text-xl font-display text-primary glow uppercase tracking-widest"
                            >
                                Parallel_Link
                            </h3>
                            <p class="text-[10px] text-primary/40 uppercase tracking-widest mt-1">
                                Select node for concurrent stream
                            </p>
                        </div>
                        <button
                            class="text-primary/40 hover:text-primary transition-colors"
                            @click="closePicker"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <!-- Sélecteur de projet -->
                    <div class="px-6 py-4 border-b border-primary/10 shrink-0">
                        <label
                            class="block text-[9px] font-bold text-primary/60 uppercase tracking-widest mb-2"
                            >Target_Node</label
                        >
                        <select
                            v-model="pickerProjectId"
                            class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-xs font-mono focus:outline-none focus:border-primary transition-all"
                            @change="loadPickerSessions"
                        >
                            <option
                                v-for="p in projects"
                                :key="p.id"
                                :value="p.id"
                                class="bg-bg-terminal"
                            >
                                {{ p.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Liste des sessions -->
                    <div class="flex-1 overflow-y-auto py-2 custom-scrollbar">
                        <div
                            v-if="pickerLoading"
                            class="px-6 py-8 text-[10px] text-primary/40 uppercase animate-pulse"
                        >
                            Accessing_Data...
                        </div>
                        <div
                            v-else-if="pickerSessions.length === 0"
                            class="px-6 py-8 text-[10px] text-primary/40 uppercase border border-dashed border-primary/10 mx-6 my-4 text-center"
                        >
                            Empty_Registry. No sessions detected.
                        </div>
                        <button
                            v-for="s in pickerSessions"
                            v-else
                            :key="s.id"
                            :disabled="s.id === sessionId"
                            class="w-full text-left px-6 py-4 hover:bg-primary/5 border-l-2 border-transparent hover:border-primary disabled:opacity-20 disabled:cursor-not-allowed transition-all flex items-start justify-between gap-4 group"
                            @click="openSplit(s.id)"
                        >
                            <div class="min-w-0">
                                <p
                                    class="text-xs font-bold text-primary/80 group-hover:text-primary group-hover:glow uppercase tracking-wider truncate"
                                >
                                    {{ s.title || s.initial_instruction || 'UNTITLED_STREAM' }}
                                </p>
                                <div class="flex items-center gap-3 mt-2">
                                    <span
                                        class="text-[9px] px-1.5 py-0.5 border font-bold uppercase tracking-tighter"
                                        :class="modeBadgeClass(s.mode)"
                                        >{{ s.mode }}</span
                                    >
                                    <span
                                        class="text-[9px] px-1.5 py-0.5 border font-bold uppercase tracking-tighter flex items-center gap-1"
                                        :class="statusBadgeClass(s.status)"
                                    >
                                        <span
                                            v-if="isActiveStatus(s.status)"
                                            class="w-1 h-1 bg-current animate-pulse"
                                        ></span>
                                        {{ statusLabel(s.status) }}
                                    </span>
                                </div>
                            </div>
                            <span
                                class="text-[9px] font-mono text-primary/30 shrink-0 mt-1 uppercase"
                            >
                                [{{ formatDate(s.created_at) }}]
                            </span>
                        </button>
                    </div>

                    <!-- Créer une nouvelle session -->
                    <div class="px-6 py-5 border-t border-primary/20 shrink-0">
                        <button
                            :disabled="creatingSession"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-primary/10 border border-primary text-primary text-xs font-bold uppercase tracking-widest hover:bg-primary hover:text-black transition-all disabled:opacity-50 group"
                            @click="createAndSplit"
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
                            <span v-if="creatingSession">Initializing...</span>
                            <span v-else>Session</span>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SessionPanel from '@/components/SessionPanel.vue';
import { useSessionsStore } from '@/stores/sessions.js';
import { useProjectsStore } from '@/stores/projects.js';
import api from '@/api/client.js';

const route = useRoute();
const router = useRouter();
const sessionsStore = useSessionsStore();
const projectsStore = useProjectsStore();

const sessionId = computed(() => route.params.id);

const showSplitPicker = ref(false);
const pickerProjectId = ref(null);
const pickerSessions = ref([]);
const pickerLoading = ref(false);
const creatingSession = ref(false);

const projects = computed(() => projectsStore.projects);

// Project ID de la session courante, mis à jour dès qu'elle apparaît dans openSessions
const currentProjectId = computed(() => {
    const s = sessionsStore.openSessions.find((s) => s.id === sessionId.value);
    return s?.project_id ?? projectsStore.projects[0]?.id ?? null;
});

onMounted(async () => {
    if (!projectsStore.projects.length) {
        await projectsStore.fetchProjects();
    }
});

watch(showSplitPicker, (val) => {
    if (val) {
        pickerProjectId.value = currentProjectId.value;
        pickerSessions.value = [];
        if (pickerProjectId.value) loadPickerSessions();
    }
});

async function loadPickerSessions() {
    if (!pickerProjectId.value) return;
    pickerLoading.value = true;
    try {
        const { data } = await api.get(`/projects/${pickerProjectId.value}/sessions`);
        pickerSessions.value = data.data;
    } finally {
        pickerLoading.value = false;
    }
}

async function openPickerWithProject(projectId) {
    pickerProjectId.value = projectId;
    await loadPickerSessions();
    showSplitPicker.value = true;
}

function closePicker() {
    showSplitPicker.value = false;
}

function openSplit(otherId) {
    closePicker();
    const ids = [sessionId.value, otherId].join(',');
    router.push({ name: 'multi', params: { ids } });
}

async function createAndSplit() {
    if (!pickerProjectId.value || creatingSession.value) return;
    creatingSession.value = true;
    try {
        const session = await sessionsStore.createSession(pickerProjectId.value, {
            mode: 'execute',
        });
        openSplit(session.id);
    } catch (e) {
        console.error('Erreur création session:', e);
    } finally {
        creatingSession.value = false;
    }
}

// ─── Helpers ──────────────────────────────────────────────────────

const activeStatuses = new Set(['reading', 'planning', 'building', 'running']);

function isActiveStatus(status) {
    return activeStatuses.has(status);
}

function modeBadgeClass(mode) {
    return (
        {
            read: 'bg-blue-500/10 text-blue-400',
            plan: 'bg-yellow-500/10 text-yellow-400',
            execute: 'bg-indigo-500/10 text-indigo-400',
        }[mode] ?? 'bg-gray-500/10 text-gray-400'
    );
}

function statusBadgeClass(status) {
    return (
        {
            idle: 'bg-gray-500/10 text-gray-400',
            reading: 'bg-blue-500/10 text-blue-400',
            planning: 'bg-yellow-500/10 text-yellow-400',
            building: 'bg-orange-500/10 text-orange-400',
            running: 'bg-violet-500/10 text-violet-400',
            awaiting_confirmation: 'bg-amber-500/10 text-amber-400',
            done: 'bg-green-500/10 text-green-400',
            error: 'bg-red-500/10 text-red-400',
        }[status] ?? 'bg-gray-500/10 text-gray-400'
    );
}

function statusLabel(status) {
    return (
        {
            idle: 'Prête',
            reading: 'Lecture…',
            planning: 'Planification…',
            building: 'Construction…',
            running: 'Exécution…',
            awaiting_confirmation: 'En attente',
            done: 'Terminée',
            error: 'Erreur',
        }[status] ?? status
    );
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<style scoped>
/* Ouvre la liste de sessions dès que le picker monte */
</style>
