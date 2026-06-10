<template>
    <div class="space-y-4 selection:bg-primary selection:text-black">
        <!-- Barre de recherche -->
        <div class="relative group">
            <span
                class="absolute left-3 top-1/2 -translate-y-1/2 text-primary/40 font-mono text-sm group-focus-within:text-primary transition-colors"
                >></span
            >
            <input
                v-model="search"
                type="text"
                placeholder="SEARCH_REGISTRY..."
                class="w-full bg-primary/5 border border-primary/20 text-primary pl-8 pr-3 py-2.5 text-xs font-mono placeholder:text-primary/10 focus:outline-none focus:border-primary transition-all"
                @input="onSearch"
            />
        </div>

        <!-- État chargement -->
        <div
            v-if="loading"
            class="flex items-center justify-center py-12 text-primary/40 text-[10px] uppercase tracking-[0.3em] animate-pulse"
        >
            Requesting_Data...
        </div>

        <!-- Erreur -->
        <div
            v-else-if="error"
            class="text-[10px] text-danger border border-danger/50 bg-danger/10 px-3 py-2 uppercase font-bold"
        >
            [!] REGISTRY_ERR: {{ error }}
        </div>

        <!-- Liste des repos -->
        <div
            v-else
            class="border border-primary/20 bg-bg-terminal overflow-hidden max-h-72 overflow-y-auto custom-scrollbar"
        >
            <div
                v-if="repos.length === 0"
                class="text-center py-12 text-primary/30 text-[10px] uppercase tracking-widest"
            >
                Registry_Empty. No nodes found.
            </div>
            <button
                v-for="repo in repos"
                :key="repo.id"
                type="button"
                class="w-full flex items-start gap-3 px-4 py-4 text-left hover:bg-primary/5 transition-all border-b border-primary/10 last:border-0 group"
                :class="selected?.id === repo.id ? 'bg-primary/10 border-l-2 border-l-primary' : ''"
                @click="select(repo)"
            >
                <div
                    class="w-2 h-2 border border-primary/40 mt-1 shrink-0 group-hover:border-primary transition-colors"
                ></div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3">
                        <span
                            class="text-xs font-bold text-primary/80 uppercase tracking-widest group-hover:text-primary transition-colors truncate"
                            >{{ repo.full_name }}</span
                        >
                        <span
                            class="text-[8px] px-1.5 py-0.5 border shrink-0 font-bold tracking-tighter"
                            :class="
                                repo.private
                                    ? 'border-warning/30 text-warning/50'
                                    : 'border-primary/20 text-primary/30'
                            "
                        >
                            {{ repo.private ? 'PRIVATE_NODE' : 'PUBLIC_NODE' }}
                        </span>
                    </div>
                    <p
                        v-if="repo.description"
                        class="text-[10px] text-primary/40 truncate mt-1 lowercase font-mono italic"
                    >
                        {{ repo.description }}
                    </p>
                    <div class="flex items-center gap-4 mt-2">
                        <span
                            v-if="repo.language"
                            class="text-[9px] font-mono text-primary/30 uppercase tracking-tighter"
                            >LANG:{{ repo.language }}</span
                        >
                        <span
                            class="text-[9px] font-mono text-primary/20 uppercase tracking-tighter"
                            >UPDATED:{{ formatDate(repo.updated_at) }}</span
                        >
                    </div>
                </div>
                <div
                    v-if="selected?.id === repo.id"
                    class="w-4 h-4 text-primary shrink-0 mt-0.5 glow"
                >
                    <svg fill="currentColor" viewBox="0 0 20 20">
                        <path
                            fill-rule="evenodd"
                            d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </div>
            </button>
        </div>

        <!-- Pagination -->
        <div v-if="repos.length > 0 || page > 1" class="flex items-center justify-between pt-2">
            <button
                v-if="page > 1"
                type="button"
                class="text-[10px] font-bold text-primary/40 hover:text-primary uppercase tracking-widest transition-colors"
                @click="prevPage"
            >
                [ PREV_PAGE ]
            </button>
            <span v-else></span>
            <span class="text-[9px] font-mono text-primary/20 uppercase tracking-[0.2em]"
                >Registry_Page::{{ page }}</span
            >
            <button
                v-if="repos.length === perPage"
                type="button"
                class="text-[10px] font-bold text-primary/40 hover:text-primary uppercase tracking-widest transition-colors"
                @click="nextPage"
            >
                [ NEXT_PAGE ]
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '@/api/client.js';

const emit = defineEmits(['select']);

const repos = ref([]);
const loading = ref(false);
const error = ref('');
const search = ref('');
const page = ref(1);
const perPage = 30;
const selected = ref(null);

let searchTimeout = null;

onMounted(() => fetchRepos());

async function fetchRepos() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/settings/github/repos', {
            params: { page: page.value, per_page: perPage, search: search.value },
        });
        repos.value = data.data;
    } catch (e) {
        error.value = e.response?.data?.message ?? 'FETCH_ERR';
    } finally {
        loading.value = false;
    }
}

function onSearch() {
    clearTimeout(searchTimeout);
    page.value = 1;
    searchTimeout = setTimeout(fetchRepos, 400);
}

function select(repo) {
    selected.value = repo;
    emit('select', repo);
}

function nextPage() {
    page.value++;
    fetchRepos();
}

function prevPage() {
    page.value--;
    fetchRepos();
}

function formatDate(iso) {
    return new Date(iso).toISOString().substring(0, 10);
}
</script>
