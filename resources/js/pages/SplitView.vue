<template>
  <div ref="containerEl" class="flex flex-col h-full overflow-hidden bg-black">
    <!-- Header Multi-Stream -->
    <header class="shrink-0 bg-black border-b border-primary/20 px-4 py-2 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <RouterLink :to="{ name: 'projects' }" class="text-primary/40 hover:text-primary transition-all">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </RouterLink>
        <h2 class="text-xs font-display text-primary glow uppercase tracking-[0.3em]">Multi_Stream_Matrix</h2>
        <span class="text-[9px] text-primary/30 font-mono hidden sm:inline">Active_Nodes: {{ sessionIds.length }}</span>
      </div>
      
      <div class="flex items-center gap-4">
        <!-- Desktop Grid Toggle -->
        <div class="hidden md:flex items-center gap-2 border border-primary/20 p-1">
          <button 
            @click="layout = 'grid'"
            class="p-1 transition-all"
            :class="layout === 'grid' ? 'text-primary bg-primary/10' : 'text-primary/30 hover:text-primary/60'"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
            </svg>
          </button>
          <button 
            @click="layout = 'cols'"
            class="p-1 transition-all"
            :class="layout === 'cols' ? 'text-primary bg-primary/10' : 'text-primary/30 hover:text-primary/60'"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
          </button>
        </div>

        <button 
          @click="showAddPicker = true"
          class="flex items-center gap-1.5 px-3 py-1 bg-primary/10 border border-primary/40 text-primary text-[10px] font-bold uppercase tracking-widest hover:bg-primary hover:text-black transition-all"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add_Node
        </button>
      </div>
    </header>

    <!-- Mobile Tabs Navigation -->
    <div class="md:hidden flex border-b border-primary/20 shrink-0 overflow-x-auto custom-scrollbar">
      <button
        v-for="(id, index) in sessionIds"
        :key="id"
        @click="activeMobilePanel = index"
        class="flex-1 min-w-[120px] py-3 text-[10px] font-bold uppercase tracking-[0.2em] transition-all whitespace-nowrap px-4"
        :class="activeMobilePanel === index ? 'text-primary bg-primary/10 border-b-2 border-primary glow' : 'text-primary/40'"
      >
        Stream_{{ String(index + 1).padStart(2, '0') }}
      </button>
    </div>

    <!-- Container des Streams -->
    <div 
      class="flex-1 overflow-hidden"
      :class="[
        !isDesktop ? 'flex flex-col' : (layout === 'grid' ? 'grid' : 'flex'),
        isDesktop && layout === 'grid' ? gridClass : ''
      ]"
    >
      <div
        v-for="(id, index) in sessionIds"
        :key="id"
        v-show="isDesktop || activeMobilePanel === index"
        class="flex flex-col overflow-hidden relative border-primary/10"
        :class="[
          isDesktop ? (layout === 'cols' ? 'flex-1 border-r' : 'border') : 'flex-1'
        ]"
      >
        <!-- Stream Toolbar -->
        <div class="bg-primary/5 px-4 py-1 flex items-center justify-between border-b border-primary/5">
          <span class="text-[8px] text-primary/30 uppercase font-mono tracking-widest">
            Node_Ref::{{ String(index + 1).padStart(2, '0') }}
          </span>
          <button 
            v-if="sessionIds.length > 1"
            @click="removeSession(id)"
            class="text-primary/20 hover:text-danger transition-colors"
            title="DISCONNECT_NODE"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <SessionPanel
          :session-id="id"
          :theme="['primary', 'blue', 'orange', 'purple'][index % 4]"
          class="flex-1"
        />
      </div>
    </div>

    <!-- Modal : Ajouter une session (réutilisé ou similaire à SessionPage) -->
    <Teleport to="body">
      <div v-if="showAddPicker" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center z-50 p-4" @click.self="showAddPicker = false">
        <div class="bg-black border border-primary border-double w-full max-w-lg flex flex-col max-h-[80vh] relative">
           <div class="flex items-center justify-between px-6 py-5 border-b border-primary/20 shrink-0">
            <div>
              <h3 class="text-xl font-display text-primary glow uppercase tracking-widest">Inject_Node</h3>
              <p class="text-[10px] text-primary/40 uppercase tracking-widest mt-1">Select node for concurrent stream</p>
            </div>
            <button @click="showAddPicker = false" class="text-primary/40 hover:text-primary transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="px-6 py-4 border-b border-primary/10 shrink-0">
            <label class="block text-[9px] font-bold text-primary/60 uppercase tracking-widest mb-2">Target_Project</label>
            <select
              v-model="pickerProjectId"
              class="w-full bg-primary/5 border border-primary/30 text-primary px-3 py-2 text-xs font-mono focus:outline-none focus:border-primary"
              @change="loadPickerSessions"
            >
              <option v-for="p in projects" :key="p.id" :value="p.id" class="bg-black">{{ p.name }}</option>
            </select>
          </div>

          <div class="flex-1 overflow-y-auto py-2 custom-scrollbar">
            <div v-if="pickerLoading" class="px-6 py-8 text-[10px] text-primary/40 uppercase animate-pulse">Accessing_Data...</div>
            <button
              v-for="s in pickerSessions"
              :key="s.id"
              :disabled="sessionIds.includes(s.id)"
              @click="addSession(s.id)"
              class="w-full text-left px-6 py-4 hover:bg-primary/5 border-l-2 border-transparent hover:border-primary disabled:opacity-20 transition-all flex items-start justify-between gap-4 group"
            >
              <div class="min-w-0">
                <p class="text-xs font-bold text-primary/80 group-hover:text-primary uppercase truncate">{{ s.title || s.initial_instruction || 'UNTITLED_STREAM' }}</p>
                <p class="text-[9px] text-primary/30 uppercase mt-1">{{ s.mode }} | {{ s.status }}</p>
              </div>
              <span class="text-[9px] font-mono text-primary/20">[{{ s.id.substring(0,8) }}]</span>
            </button>
          </div>

          <div class="px-6 py-5 border-t border-primary/20 shrink-0">
            <button
              @click="createNewAndAdd"
              :disabled="creatingSession"
              class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-primary/10 border border-primary text-primary text-xs font-bold uppercase tracking-widest hover:bg-primary hover:text-black transition-all"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              <span>Initialize_New_Session</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SessionPanel from '@/components/SessionPanel.vue'
import { useSessionsStore } from '@/stores/sessions.js'
import { useProjectsStore } from '@/stores/projects.js'
import api from '@/api/client.js'

const route = useRoute()
const router = useRouter()
const sessionsStore = useSessionsStore()
const projectsStore = useProjectsStore()

const sessionIds = computed(() => route.params.ids.split(',').filter(Boolean))

const layout = ref('grid') // 'grid' ou 'cols'
const activeMobilePanel = ref(0)
const windowWidth = ref(window.innerWidth)
const isDesktop = computed(() => windowWidth.value >= 768)

// Picker state
const showAddPicker = ref(false)
const pickerProjectId = ref(null)
const pickerSessions = ref([])
const pickerLoading = ref(false)
const creatingSession = ref(false)
const projects = computed(() => projectsStore.projects)

const gridClass = computed(() => {
  const count = sessionIds.value.length
  if (count <= 1) return 'grid-cols-1'
  if (count === 2) return 'grid-cols-2'
  if (count <= 4) return 'grid-cols-2 grid-rows-2'
  return 'grid-cols-3'
})

function handleResize() {
  windowWidth.value = window.innerWidth
}

onMounted(async () => {
  window.addEventListener('resize', handleResize)
  if (!projectsStore.projects.length) {
    await projectsStore.fetchProjects()
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
})

watch(showAddPicker, (val) => {
  if (val && !pickerProjectId.value && projects.value.length > 0) {
    pickerProjectId.value = projects.value[0].id
    loadPickerSessions()
  }
})

async function loadPickerSessions() {
  if (!pickerProjectId.value) return
  pickerLoading.value = true
  try {
    const { data } = await api.get(`/projects/${pickerProjectId.value}/sessions`)
    pickerSessions.value = data.data
  } finally {
    pickerLoading.value = false
  }
}

function addSession(id) {
  if (sessionIds.value.includes(id)) return
  const newIds = [...sessionIds.value, id].join(',')
  router.push({ name: 'multi', params: { ids: newIds } })
  showAddPicker.value = false
}

function removeSession(id) {
  const newIds = sessionIds.value.filter(sid => sid !== id).join(',')
  if (!newIds) {
    router.push({ name: 'projects' })
  } else {
    router.push({ name: 'multi', params: { ids: newIds } })
    if (activeMobilePanel.value >= sessionIds.value.length - 1) {
      activeMobilePanel.value = Math.max(0, sessionIds.value.length - 2)
    }
  }
}

async function createNewAndAdd() {
  if (!pickerProjectId.value || creatingSession.value) return
  creatingSession.value = true
  try {
    const session = await sessionsStore.createSession(pickerProjectId.value, {
      mode: 'execute',
    })
    addSession(session.id)
  } catch (e) {
    console.error('Erreur création session:', e)
  } finally {
    creatingSession.value = false
  }
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    height: 4px;
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(0, 255, 65, 0.05);
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(0, 255, 65, 0.2);
}
</style>


