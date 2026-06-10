<template>
    <div class="p-4 sm:p-8 selection:bg-primary selection:text-black">
        <div
            class="flex items-center justify-between mb-8 sm:mb-12 border-b border-primary-dim pb-4"
        >
            <div>
                <h2 class="text-3xl font-display theme-text text-primary glow">
                    {{ settings.theme === 'hacker' ? 'Data_Nodes' : 'Projects' }}
                </h2>
                <p class="text-[10px] text-primary/40 theme-text mt-1">
                    {{
                        settings.theme === 'hacker'
                            ? 'Registry: Local_Projects'
                            : 'Manage your development environments'
                    }}
                </p>
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
                {{ settings.theme === 'hacker' ? 'Projet' : 'New Project' }}
            </button>
        </div>

        <!-- Liste des projets -->
        <div v-if="store.loading" class="text-primary/50 text-[10px] theme-text animate-pulse">
            {{ settings.theme === 'hacker' ? 'Scanning_Environment...' : 'Loading projects...' }}
        </div>
        <div
            v-else-if="store.projects.length === 0"
            class="text-primary/50 text-[10px] theme-text border border-dashed border-primary/20 p-8 text-center rounded-[--radius-none]"
        >
            {{
                settings.theme === 'hacker'
                    ? 'Empty_Registry. No project nodes detected.'
                    : 'No projects found. Create one to get started.'
            }}
        </div>
        <div v-else class="grid gap-6 lg:grid-cols-2">
            <div
                v-for="project in store.projects"
                :key="project.id"
                class="bg-bg-terminal border border-primary/20 p-5 relative overflow-hidden group hover:border-primary/60 transition-all rounded-[--radius-none]"
            >
                <!-- Corner detail -->
                <div
                    v-if="settings.theme === 'hacker'"
                    class="absolute top-0 right-0 w-8 h-8 pointer-events-none"
                >
                    <div
                        class="absolute top-0 right-0 border-t-2 border-r-2 border-primary/40 w-2 h-2 group-hover:border-primary transition-colors"
                    ></div>
                </div>

                <div class="flex flex-col gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-2">
                            <h3
                                class="text-lg font-display theme-text text-primary truncate group-hover:glow"
                            >
                                {{ project.name }}
                            </h3>
                            <span
                                v-if="project.stack"
                                class="text-[9px] text-primary bg-primary/10 border border-primary/20 px-1.5 py-0.5 theme-text rounded-[--radius-none]"
                            >
                                {{ project.stack }}
                            </span>
                        </div>
                        <p
                            v-if="project.description"
                            class="text-[11px] text-primary/60 mb-4 line-clamp-2 leading-relaxed"
                        >
                            {{ project.description }}
                        </p>
                        <div class="flex flex-col gap-2">
                            <div
                                class="flex items-center gap-2 text-[9px] font-mono text-primary/30 theme-text truncate"
                            >
                                <span class="text-primary/50">{{
                                    settings.theme === 'hacker' ? '[ PATH ]' : 'Location:'
                                }}</span>
                                {{
                                    project.path ??
                                    (settings.theme === 'hacker' ? 'UNLINKED' : 'Not set')
                                }}
                            </div>
                            <div class="flex items-center gap-3">
                                <CloneStatusBadge
                                    :project="project"
                                    @poll="pollCloneStatus(project)"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-4 border-t border-primary/10">
                        <RouterLink
                            :to="{ name: 'project-sessions', params: { id: project.id } }"
                            class="flex-1 px-3 py-1.5 text-[10px] text-center font-bold text-primary/70 hover:text-black bg-primary/5 hover:bg-primary border border-primary/20 hover:border-primary transition-all theme-text rounded-[--radius-none]"
                        >
                            Sessions
                        </RouterLink>
                        <button
                            class="px-3 py-1.5 text-[10px] font-bold text-primary/70 hover:text-black bg-primary/5 hover:bg-primary border border-primary/20 hover:border-primary transition-all theme-text rounded-[--radius-none]"
                            @click="openGitHub(project)"
                        >
                            GitHub
                        </button>
                        <button
                            class="px-3 py-1.5 text-[10px] font-bold text-danger/70 hover:text-black hover:bg-danger border border-danger/20 hover:border-danger transition-all theme-text rounded-[--radius-none]"
                            @click="confirmDelete(project)"
                        >
                            {{ settings.theme === 'hacker' ? 'Kill' : 'Delete' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal : créer un projet -->
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
                    <div
                        v-if="settings.theme === 'hacker'"
                        class="absolute top-0 left-0 w-full h-1 bg-primary/20"
                    ></div>

                    <h3 class="text-2xl font-display text-primary glow theme-text mb-6">
                        {{
                            settings.theme === 'hacker'
                                ? 'Initialize_New_Node'
                                : 'Create New Project'
                        }}
                    </h3>

                    <form class="space-y-6" @submit.prevent="submitCreate">
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-primary/60 theme-text">{{
                                settings.theme === 'hacker' ? 'Node_Name' : 'Project Name'
                            }}</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-sm focus:outline-none focus:border-primary transition-all placeholder:text-primary/10 rounded-[--radius-none]"
                                :placeholder="
                                    settings.theme === 'hacker'
                                        ? 'PROJECT_ALPHA'
                                        : 'My Awesome Project'
                                "
                            />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-primary/60 theme-text">{{
                                settings.theme === 'hacker'
                                    ? 'Storage_Path'
                                    : 'Local Directory Path'
                            }}</label>
                            <input
                                v-model="form.path"
                                type="text"
                                class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-sm focus:outline-none focus:border-primary font-mono transition-all placeholder:text-primary/10 rounded-[--radius-none]"
                                :placeholder="
                                    settings.theme === 'hacker'
                                        ? '/ROOT/NODES/PROJECT_ALPHA'
                                        : 'C:/Projects/my-app'
                                "
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label
                                    class="block text-[10px] font-bold text-primary/60 theme-text"
                                    >{{
                                        settings.theme === 'hacker'
                                            ? 'Stack_Type'
                                            : 'Technology Stack'
                                    }}</label
                                >
                                <input
                                    v-model="form.stack"
                                    type="text"
                                    class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-sm focus:outline-none focus:border-primary transition-all rounded-[--radius-none]"
                                    :placeholder="
                                        settings.theme === 'hacker'
                                            ? 'LARAVEL_VUE'
                                            : 'React, Tailwind'
                                    "
                                />
                            </div>
                            <div class="flex items-center gap-2 pt-5">
                                <input
                                    id="git_init"
                                    v-model="form.git_init"
                                    type="checkbox"
                                    class="w-4 h-4 accent-primary bg-bg-terminal border-primary border focus:ring-0 rounded-sm"
                                />
                                <label
                                    for="git_init"
                                    class="text-[10px] font-bold text-primary/60 theme-text cursor-pointer"
                                    >Git Init</label
                                >
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-primary/60 theme-text">{{
                                settings.theme === 'hacker' ? 'Node_Description' : 'Description'
                            }}</label>
                            <textarea
                                v-model="form.description"
                                rows="2"
                                class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-sm focus:outline-none focus:border-primary resize-none transition-all rounded-[--radius-none]"
                            ></textarea>
                        </div>

                        <div
                            v-if="createError"
                            class="text-[10px] text-danger border border-danger/50 bg-danger/10 px-3 py-2 theme-text font-bold animate-pulse rounded-[--radius-none]"
                        >
                            {{ settings.theme === 'hacker' ? '[CRITICAL_FAILURE]' : 'Error:' }}
                            {{ createError }}
                        </div>

                        <div class="flex justify-end gap-6 pt-4">
                            <button
                                type="button"
                                class="text-xs text-primary/40 hover:text-primary theme-text"
                                @click="showCreate = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="creating"
                                class="px-6 py-2 bg-primary/10 border border-primary text-primary text-xs font-bold theme-text hover:bg-primary hover:text-black transition-all rounded-[--radius-none]"
                            >
                                <span v-if="creating">{{
                                    settings.theme === 'hacker' ? 'Deploying...' : 'Creating...'
                                }}</span>
                                <span v-else>{{
                                    settings.theme === 'hacker'
                                        ? '[ INITIALIZE_NODE ]'
                                        : 'Create Project'
                                }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Modal : liaison GitHub -->
        <Teleport to="body">
            <div
                v-if="githubModal.project"
                class="fixed inset-0 bg-bg-terminal/80 backdrop-blur-md flex items-center justify-center z-50 p-4"
                @click.self="githubModal.project = null"
            >
                <div
                    class="bg-bg-terminal border border-primary w-full max-w-xl p-8 relative rounded-[--radius-none]"
                >
                    <h3 class="text-2xl font-display text-primary glow theme-text mb-1">
                        {{ settings.theme === 'hacker' ? 'Bridge_Protocol' : 'GitHub Connection' }}
                    </h3>
                    <p class="text-[10px] text-primary/40 theme-text mb-8">
                        {{ settings.theme === 'hacker' ? 'Uplink' : 'Remote repository for' }}:
                        {{ githubModal.project.name }}
                    </p>

                    <!-- Déjà lié -->
                    <div v-if="githubModal.project.git_remote" class="space-y-6">
                        <div
                            class="bg-primary/5 border border-primary/20 px-4 py-3 flex items-center gap-3 rounded-[--radius-none]"
                        >
                            <span
                                v-if="settings.theme === 'hacker'"
                                class="text-primary/40 font-mono text-xs"
                                >></span
                            >
                            <span class="text-xs text-primary font-mono truncate">{{
                                githubModal.project.git_remote
                            }}</span>
                        </div>
                        <div
                            v-if="githubModal.project.clone_error"
                            class="text-[10px] text-danger border border-danger/50 bg-danger/10 px-3 py-2 theme-text font-bold rounded-[--radius-none]"
                        >
                            ERROR: {{ githubModal.project.clone_error }}
                        </div>
                        <div class="flex justify-end gap-6 pt-4">
                            <button
                                class="text-xs text-primary/40 hover:text-primary theme-text"
                                @click="githubModal.project = null"
                            >
                                Close
                            </button>
                            <button
                                :disabled="githubModal.loading"
                                class="px-4 py-2 bg-danger/10 border border-danger text-danger text-xs font-bold theme-text hover:bg-danger hover:text-black transition-all rounded-[--radius-none]"
                                @click="doUnlink"
                            >
                                <span v-if="githubModal.loading">...</span>
                                <span v-else>{{
                                    settings.theme === 'hacker'
                                        ? '[ TERMINATE_UPLINK ]'
                                        : 'Disconnect'
                                }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Pas encore lié — sélecteur de repo -->
                    <div v-else class="space-y-6">
                        <div
                            v-if="githubModal.selectedRepo"
                            class="flex items-center gap-3 bg-primary/10 border border-primary/40 px-4 py-3 rounded-[--radius-none]"
                        >
                            <span class="text-[10px] font-bold text-primary flex-1 theme-text">{{
                                githubModal.selectedRepo.full_name
                            }}</span>
                            <button
                                type="button"
                                class="text-[9px] text-primary/40 hover:text-primary transition-colors theme-text underline"
                                @click="githubModal.selectedRepo = null"
                            >
                                {{ settings.theme === 'hacker' ? 'Change_Target' : 'Change' }}
                            </button>
                        </div>

                        <GitHubRepoPicker
                            v-if="!githubModal.selectedRepo"
                            @select="
                                (repo) => {
                                    githubModal.selectedRepo = repo;
                                }
                            "
                        />

                        <div
                            v-if="githubModal.error"
                            class="text-[10px] text-danger border border-danger/50 bg-danger/10 px-3 py-2 theme-text font-bold rounded-[--radius-none]"
                        >
                            {{ githubModal.error }}
                        </div>
                        <div class="flex justify-end gap-6 pt-4">
                            <button
                                class="text-xs text-primary/40 hover:text-primary theme-text"
                                @click="githubModal.project = null"
                            >
                                Abort
                            </button>
                            <button
                                :disabled="!githubModal.selectedRepo || githubModal.loading"
                                class="px-6 py-2 bg-primary/10 border border-primary text-primary text-xs font-bold theme-text hover:bg-primary hover:text-black transition-all disabled:opacity-20 rounded-[--radius-none]"
                                @click="doLink"
                            >
                                <span v-if="githubModal.loading">{{
                                    settings.theme === 'hacker'
                                        ? 'Establishing_Link...'
                                        : 'Connecting...'
                                }}</span>
                                <span v-else>{{
                                    settings.theme === 'hacker'
                                        ? '[ ESTABLISH_UPLINK ]'
                                        : 'Connect Repository'
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { RouterLink } from 'vue-router';
import { useProjectsStore } from '@/stores/projects.js';
import { useSettingsStore } from '@/stores/settings.js';
import CloneStatusBadge from '@/components/CloneStatusBadge.vue';
import GitHubRepoPicker from '@/components/GitHubRepoPicker.vue';

const store = useProjectsStore();
const settings = useSettingsStore();

const showCreate = ref(false);
const creating = ref(false);
const createError = ref('');
const form = ref({ name: '', path: '', stack: '', description: '', git_init: false });

const githubModal = ref({ project: null, selectedRepo: null, loading: false, error: '' });

// Polling du statut de clone pour les projets en cours
const pollIntervals = {};

onMounted(() => {
    store.fetchProjects().then(() => {
        store.projects.forEach((p) => {
            if (p.clone_status === 'pending' || p.clone_status === 'cloning') {
                startPolling(p);
            }
        });
    });
});

onUnmounted(() => {
    Object.values(pollIntervals).forEach(clearInterval);
});

function startPolling(project) {
    if (pollIntervals[project.id]) return;
    pollIntervals[project.id] = setInterval(async () => {
        const updated = await store.refreshProject(project.id);
        if (updated.clone_status === 'cloned' || updated.clone_status === 'error') {
            clearInterval(pollIntervals[project.id]);
            delete pollIntervals[project.id];
            // Mise à jour du modal si ouvert
            if (githubModal.value.project?.id === updated.id) {
                githubModal.value.project = updated;
            }
        }
    }, 3000);
}

function pollCloneStatus(project) {
    startPolling(project);
}

async function submitCreate() {
    creating.value = true;
    createError.value = '';
    try {
        await store.createProject({
            name: form.value.name,
            path: form.value.path || null,
            git_init: form.value.git_init || false,
            stack: form.value.stack || null,
            description: form.value.description || null,
        });
        showCreate.value = false;
        form.value = { name: '', path: '', stack: '', description: '', git_init: false };
    } catch (e) {
        createError.value = e.response?.data?.message ?? 'Erreur lors de la création.';
    } finally {
        creating.value = false;
    }
}

function openGitHub(project) {
    githubModal.value = { project, selectedRepo: null, loading: false, error: '' };
}

async function doLink() {
    githubModal.value.loading = true;
    githubModal.value.error = '';
    try {
        const repoUrl = githubModal.value.selectedRepo.html_url;
        const updated = await store.linkGitHub(githubModal.value.project.id, repoUrl);
        githubModal.value.project = updated;
        startPolling(updated);
    } catch (e) {
        githubModal.value.error =
            e.response?.data?.errors?.repo_url?.[0] ??
            e.response?.data?.message ??
            'Erreur lors de la liaison.';
    } finally {
        githubModal.value.loading = false;
    }
}

async function doUnlink() {
    githubModal.value.loading = true;
    githubModal.value.error = '';
    try {
        const updated = await store.unlinkGitHub(githubModal.value.project.id);
        githubModal.value.project = null;
    } catch (e) {
        githubModal.value.error = e.response?.data?.message ?? 'Erreur lors de la déliaison.';
    } finally {
        githubModal.value.loading = false;
    }
}

async function confirmDelete(project) {
    if (!confirm(`Supprimer le projet « ${project.name} » ?`)) return;
    await store.deleteProject(project.id);
}
</script>
