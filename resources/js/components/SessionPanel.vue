<template>
  <div 
    class="flex flex-col h-full overflow-hidden bg-black selection:bg-primary selection:text-black"
    :style="themeStyle"
  >
    <!-- ─── Header ─────────────────────────────────────────────── -->
    <header class="shrink-0 bg-black border-b border-primary-dim px-4 py-3">     
      <div class="flex items-center justify-between gap-3">
        <!-- Gauche : breadcrumb + titre -->
        <div class="flex items-center gap-4 min-w-0">
          <RouterLink
            :to="session ? { name: 'project-sessions', params: { id: session.project_id } } : { name: 'projects' }"
            class="text-primary/40 hover:text-primary transition-all shrink-0 hover:glow"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </RouterLink>
          <div class="flex flex-col min-w-0">
            <h1 class="text-sm font-display uppercase tracking-widest text-primary glow truncate leading-none mb-1">
              {{ session?.title || session?.initial_instruction || 'SESSION_STREAM' }}
            </h1>
            <div class="flex items-center gap-2 text-[9px] text-primary/40 uppercase tracking-tighter">
              <span class="truncate">{{ session?.project?.name }}</span>
              <template v-if="repoName">
                <span class="text-primary/20">|</span>
                <div class="flex items-center gap-1 min-w-0">
                  <span class="truncate">UPLINK: {{ repoName }}</span>
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- Droite : badges + actions -->
        <div class="flex items-center gap-3 shrink-0">
          <!-- Cost total -->
          <span v-if="totalCost > 0" class="text-[9px] text-primary/30 font-mono hidden sm:inline uppercase">
            DRAIN: ${{ totalCost.toFixed(4) }}
          </span>
          <!-- Mode -->
          <span
            v-if="session"
            class="text-[9px] px-2 py-0.5 border font-bold uppercase tracking-tighter"
            :class="modeBadgeClass(session.mode)"
          >
            {{ session.mode }}
          </span>
          <!-- Status -->
          <span
            v-if="session"
            class="text-[9px] px-2 py-0.5 border font-bold uppercase tracking-tighter flex items-center gap-1"
            :class="statusBadgeClass(session.status)"
          >
            <span
              v-if="isActive"
              class="w-1 h-1 bg-current shadow-[0_0_5px_currentColor] animate-pulse"
            ></span>
            {{ statusLabel(session.status) }}
          </span>

          <div class="h-4 w-px bg-primary/20 hidden sm:block mx-1"></div>        

          <!-- Actions -->
          <div class="flex items-center gap-2">
            <!-- Split View -->
            <button
              v-if="showSplitAction"
              @click="emit('split')"
              class="flex items-center gap-1.5 px-2 py-1 text-[9px] font-bold text-primary/70 hover:text-black bg-primary/5 hover:bg-primary border border-primary/30 transition-all uppercase tracking-tighter"
            >
              [ SPLIT ]
            </button>

            <!-- Lancer le projet -->
            <button
              v-if="session && !launching"
              @click="doLaunch"
              :disabled="isActive"
              class="flex items-center gap-1.5 px-2 py-1 text-[9px] font-bold text-primary hover:text-black bg-primary/5 hover:bg-primary border border-primary/30 transition-all disabled:opacity-20 uppercase tracking-tighter"
            >
              [ RUN ]
            </button>

            <!-- Push GitHub -->
            <div v-if="session?.project?.git_remote" class="flex items-center gap-1 ml-2">
              <input
                v-model="pushMessage"
                type="text"
                placeholder="COMMIT_MSG..."
                :disabled="isActive || pushing"
                class="bg-black border border-primary/20 px-2 py-1 text-[9px] font-mono text-primary placeholder:text-primary/20 focus:outline-none focus:border-primary/50 transition-colors w-32"
              />
              <button
                @click="doPush"
                :disabled="isActive || pushing"
                class="flex items-center gap-1.5 px-2 py-1 text-[9px] font-bold text-primary/70 hover:text-black bg-primary/5 hover:bg-primary border border-primary/30 transition-all disabled:opacity-20 uppercase tracking-tighter"
              >
                {{ pushing ? 'PUSHING...' : '[ PUSH ]' }}
              </button>
            </div>

            <!-- Poll indicator -->
            <div
              class="w-1.5 h-1.5 ml-1"
              :class="pollConnected ? 'bg-primary shadow-[0_0_5px_var(--color-primary)]' : 'bg-danger shadow-[0_0_5px_var(--color-danger)]'"
              :title="pollConnected ? 'LINK_ESTABLISHED' : 'LINK_BROKEN'"        
            ></div>
          </div>
        </div>
      </div>
    </header>

    <!-- ─── Barre de lancement ─────────────────────────────────── -->
    <Transition name="launch-fade">
      <div v-if="launching && !launchFinished" class="shrink-0 bg-black border-b border-primary-dim">
        <div class="h-0.5 bg-primary/10 overflow-hidden">
          <div
            class="h-full bg-primary shadow-[0_0_5px_var(--color-primary)] transition-all duration-700 ease-out"
            :style="{ width: Math.round(launchProgress) + '%' }"
          ></div>
        </div>
        <div class="px-4 py-1.5 flex items-center justify-between">
          <span class="text-[9px] text-primary/60 font-mono uppercase tracking-[0.2em]">{{ launchStatusText }}</span>
        </div>
      </div>
    </Transition>

    <!-- Lien URL une fois terminé -->
    <Transition name="launch-fade">
      <div v-if="launchFinished && launchUrl" class="shrink-0 px-4 py-2 bg-primary/5 border-b border-primary/20 flex items-center justify-between gap-3">
        <a
          :href="launchUrl"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-1.5 text-[10px] text-primary hover:glow transition-all uppercase tracking-widest font-bold"
        >
          <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
          </svg>
          ACCESS_POINT: {{ launchUrl }}
        </a>
        <button @click="closeLaunchOverlay" class="text-primary/40 hover:text-primary transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
    </Transition>

    <!-- ─── Flux de messages ──────────────────────────────────── -->
    <div
      ref="feedEl"
      class="flex-1 overflow-y-auto px-4 py-6 space-y-3 custom-scrollbar"
      @scroll="onFeedScroll"
    >
      <!-- Message info initial -->
      <div v-if="!loadingSession && messages.length === 0" class="flex flex-col items-center py-20 opacity-20 select-none">
         <span class="font-display text-6xl mb-4">>_</span>
         <p class="text-[10px] uppercase tracking-[0.5em]">Waiting_for_directives</p>
      </div>

      <!-- Messages -->
      <template v-for="msg in messages" :key="msg.id || msg._tempId">
        <!-- Status / Done / Log -->
        <div
          v-if="displayType(msg) === 'status' || displayType(msg) === 'done' || displayType(msg) === 'log' || displayType(msg) === 'cost'"
          class="flex items-center gap-3 py-0.5 opacity-40 hover:opacity-100 transition-opacity"
        >
          <span class="text-[9px] font-mono text-primary/50 shrink-0">[{{ formatDate(msg.created_at || new Date().toISOString()).split(' ')[1] }}]</span>
          <div class="w-1 h-1 bg-primary/60 shrink-0"></div>
          <span class="text-[10px] font-mono uppercase tracking-tighter">        
            <template v-if="displayType(msg) === 'cost'">
               RESOURCE_USAGE: ${{ msg.content?.cost_usd?.toFixed(4) }} (IN:{{ msg.content?.input_tokens }} OUT:{{ msg.content?.output_tokens }})
            </template>
            <template v-else>
               {{ msg.content?.message || msg.content?.status || msg.content || 'SYS_EVENT' }}
            </template>
          </span>
        </div>

        <!-- Message texte de l'agent -->
        <div
          v-else-if="displayType(msg) === 'message'"
          class="flex flex-col gap-1 max-w-[90%]"
        >
          <div class="flex items-center gap-2 text-[9px] font-bold text-primary/40 uppercase tracking-widest ml-1">
             <span class="glow">CORE_PROCESSOR</span>
          </div>
          <div class="bg-primary/5 border-l-2 border-primary/60 px-4 py-3 text-[13px] text-primary/90 whitespace-pre-wrap leading-relaxed font-mono selection:bg-primary selection:text-black">
            {{ msg.content?.text || msg.content }}
          </div>
        </div>

        <!-- Plan -->
        <div
          v-else-if="displayType(msg) === 'plan'"
          class="bg-black border border-primary/40 overflow-hidden my-4"
        >
          <div class="flex items-center justify-between px-4 py-2 border-b border-primary/20 bg-primary/10">
            <span class="text-[10px] font-bold text-primary uppercase tracking-[0.2em]">Strategy_Map</span>
          </div>
          <pre class="px-4 py-4 text-xs text-primary/80 whitespace-pre-wrap font-mono leading-relaxed overflow-x-auto">{{ msg.content?.content || msg.content }}</pre>
        </div>

        <!-- Tool call -->
        <div
          v-else-if="displayType(msg) === 'tool_call'"
          class="flex items-center gap-3 py-1 ml-4"
        >
          <div class="w-2 h-2 border border-primary/60 rotate-45 shrink-0"></div>
          <div class="text-[10px] font-mono text-primary/60 uppercase">
            <span class="text-primary/90">CALL::{{ msg.content?.tool }}</span>   
            <span v-if="msg.content?.file" class="text-primary/40"> -> {{ msg.content.file }}</span>
            <span v-else-if="msg.content?.command" class="text-primary/40"> -> {{ msg.content.command }}</span>
            <span v-else-if="msg.content?.params?.file_path" class="text-primary/40"> -> {{ msg.content.params.file_path }}</span>
          </div>
        </div>

        <!-- Terminal Output -->
        <div
          v-else-if="displayType(msg) === 'terminal'"
          class="bg-black border border-primary/20 overflow-hidden my-2 ml-4"    
        >
          <div class="flex items-center gap-2 px-3 py-1.5 bg-primary/5 border-b border-primary/10">
            <span class="text-[9px] font-mono text-primary/50">OUT::{{ msg.content?.command }}</span>
          </div>
          <pre
            v-if="msg.content?.output"
            class="px-3 py-3 text-[11px] font-mono text-primary/70 whitespace-pre-wrap overflow-x-auto max-h-96 overflow-y-auto leading-tight"
          >{{ msg.content.output }}</pre>
        </div>

        <!-- File change (diff) -->
        <div
          v-else-if="displayType(msg) === 'file_change'"
          class="bg-black border border-primary/20 overflow-hidden my-2 ml-4"    
        >
          <div class="flex items-center justify-between gap-2 px-3 py-1.5 bg-primary/5 border-b border-primary/10">
            <span class="text-[9px] font-mono text-primary/70 uppercase">DIFF::{{ msg.content?.file }}</span>
            <div class="flex gap-2 text-[9px] font-mono">
              <span class="text-primary">+{{ msg.content?.additions }}</span>    
              <span class="text-danger">-{{ msg.content?.deletions }}</span>     
            </div>
          </div>
          <div class="overflow-x-auto max-h-96 overflow-y-auto">
            <table class="w-full text-[11px] font-mono">
              <tbody>
                <tr
                  v-for="(line, i) in parseDiffLines(msg.content?.diff)"
                  :key="i"
                  :class="line.cls"
                >
                  <td class="pl-2 pr-2 py-0 select-none opacity-30 text-right w-6 border-r border-primary/10">{{ line.prefix }}</td>
                  <td class="pl-3 pr-3 py-0 whitespace-pre">{{ line.text }}</td> 
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Confirmation request -->
        <div
          v-else-if="displayType(msg) === 'confirmation_request'"
          class="border-2 border-warning bg-warning/5 my-6 p-6 relative overflow-hidden"
        >
          <!-- Warning background pattern -->
          <div class="absolute inset-0 opacity-5 pointer-events-none" style="background-image: repeating-linear-gradient(45deg, var(--color-warning) 0, var(--color-warning) 10px, transparent 10px, transparent 20px);"></div>

          <div class="relative z-10">
            <h4 class="text-warning font-display text-xl uppercase tracking-[0.2em] mb-2 glow-warning">AUTHORIZATION_REQUIRED</h4>
            <p class="text-sm text-warning/80 mb-6 font-mono tracking-tight">{{ msg.content?.message }}</p>

            <div
              v-if="session?.status === 'awaiting_confirmation' && !confirmedActions.has(msg.content?.action_id)"
              class="flex gap-4"
            >
              <button
                @click="doConfirm(msg.content?.action_id, true)"
                :disabled="confirming"
                class="flex-1 py-3 bg-warning/10 border border-warning text-warning text-xs font-bold uppercase tracking-widest hover:bg-warning hover:text-black transition-all"
              >
                [ GRANT_ACCESS ]
              </button>
              <button
                @click="doConfirm(msg.content?.action_id, false)"
                :disabled="confirming"
                class="flex-1 py-3 bg-black border border-warning/40 text-warning/60 text-xs font-bold uppercase tracking-widest hover:border-warning hover:text-warning transition-all"
              >
                [ DENY ]
              </button>
            </div>
            <div v-else class="text-[10px] font-bold uppercase tracking-widest text-warning/60 border-t border-warning/20 pt-4">
              PROTOCOL_RESOLVED: {{ confirmedActions.get(msg.content?.action_id) ? 'ACCESS_GRANTED' : 'ACCESS_DENIED' }}
            </div>
          </div>
        </div>

        <!-- Error -->
        <div
          v-else-if="displayType(msg) === 'error'"
          class="bg-danger/5 border border-danger/40 p-4 my-2 flex gap-4"        
        >
          <div class="text-danger font-display text-2xl shrink-0 mt-0.5">!</div> 
          <div class="flex-1">
             <h5 class="text-danger text-[10px] font-bold uppercase tracking-widest mb-1">SYSTEM_CRITICAL_FAILURE</h5>
             <p class="text-xs text-danger/80 font-mono whitespace-pre-wrap">{{ msg.content?.message || 'NULL_RESPONSE_EXCEPT' }}</p>
          </div>
        </div>

        <!-- Message utilisateur -->
        <div
          v-else-if="displayType(msg) === 'user'"
          class="flex flex-col items-end gap-1 w-full"
        >
          <div class="flex items-center gap-2 text-[9px] font-bold text-primary/40 uppercase tracking-widest mr-1">
             USER_DIRECTIVE
          </div>
          <div class="max-w-[85%] bg-primary/10 border-r-2 border-primary px-4 py-2 text-[13px] text-primary whitespace-pre-wrap font-mono leading-relaxed">      
            <span class="text-primary/40 mr-2">></span>{{ msg.content?.text || msg.content }}
          </div>
        </div>
      </template>

      <!-- Indicateur d'activité -->
      <div v-if="isActive && !isAwaitingConfirmation" class="flex items-center gap-3 py-2 ml-1">
        <div class="w-1.5 h-1.5 bg-primary animate-ping"></div>
        <span class="text-[10px] text-primary font-mono uppercase tracking-[0.2em]">{{ statusLabel(session?.status) }}...</span>
      </div>

      <!-- Ancre de scroll -->
      <div ref="feedBottomEl"></div>
    </div>

    <!-- ─── Barre d'instruction ────────────────────────────────── -->
    <div class="shrink-0 bg-black border-t border-primary-dim p-6">
      <div class="flex flex-col gap-4 max-w-5xl mx-auto">
        <!-- Input Area -->
        <div class="relative group">
          <div class="absolute -inset-0.5 bg-primary/20 opacity-0 group-focus-within:opacity-100 transition-opacity blur-sm"></div>
          <div class="relative flex gap-4 items-end bg-black border border-primary/30 p-2 group-focus-within:border-primary transition-colors">
            <div class="pl-3 pb-3 shrink-0 text-primary/60 font-display text-xl leading-none select-none">>_</div>

            <textarea
              v-model="instruction"
              ref="inputEl"
              rows="1"
              :disabled="isActive || !session"
              placeholder="ENTER_DIRECTIVE..."
              class="flex-1 bg-transparent text-primary text-[14px] px-0 py-2.5 focus:outline-none resize-none disabled:opacity-20 font-mono placeholder:text-primary/10 h-auto"
              @keydown.ctrl.enter.prevent="submitInstruction"
              @keydown.meta.enter.prevent="submitInstruction"
              v-auto-resize
            ></textarea>

            <div class="flex gap-2 shrink-0 pb-1 pr-1">
              <!-- Stop -->
              <button
                v-if="isActive"
                @click="doStop"
                :disabled="stopping"
                class="w-10 h-10 flex items-center justify-center bg-danger/10 border border-danger/40 text-danger hover:bg-danger hover:text-black transition-all disabled:opacity-20"
                title="ABORT"
              >
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">    
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 00-1 1v4a1 1 0 001 1h4a1 1 0 001-1V8a1 1 0 00-1-1H8z" clip-rule="evenodd" />
                </svg>
              </button>

              <!-- Envoyer -->
              <button
                v-if="!isActive"
                @click="submitInstruction"
                :disabled="!instruction.trim() || sending || !session"
                class="w-10 h-10 flex items-center justify-center bg-primary/10 border border-primary/40 text-primary hover:bg-primary hover:text-black transition-all disabled:opacity-20"
                title="EXECUTE"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <div class="flex justify-between items-center px-1">
           <div class="flex gap-4">
              <button
                v-for="m in modes"
                :key="m.value"
                @click="selectedMode = m.value"
                class="text-[10px] font-bold uppercase tracking-widest transition-all"
                :class="selectedMode === m.value ? 'text-primary glow underline underline-offset-4' : 'text-primary/30 hover:text-primary/60'"
              >
                {{ m.label }}
              </button>
           </div>
           <span class="text-[9px] text-primary/20 uppercase tracking-[0.2em] hidden sm:inline">Ctrl+Enter_to_Execute</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { RouterLink } from 'vue-router'
import { useSessionsStore } from '@/stores/sessions.js'
import api from '@/api/client.js'

// Directive auto-resize pour le textarea
const vAutoResize = {
  updated: (el) => {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
  }
}

const props = defineProps({
  sessionId: { type: String, required: true },
  showSplitAction: { type: Boolean, default: false },
  theme: { type: String, default: 'primary' }, // 'primary', 'blue', 'orange', 'purple'
})

const themeStyle = computed(() => {
  const colors = {
    primary: '#00ff41',
    blue: '#00d2ff',
    orange: '#ff9d00',
    purple: '#d000ff',
  }
  const color = colors[props.theme] || colors.primary
  return {
    '--color-primary': color,
    '--color-primary-dim': `${color}33`, // 20% opacity approx
  }
})

const emit = defineEmits(['split'])

const store = useSessionsStore()

const sessionId = computed(() => props.sessionId)

// State
const session = ref(null)
const messages = ref([])
const loadingSession = ref(true)
const sending = ref(false)
const stopping = ref(false)
const confirming = ref(false)
const pushing = ref(false)
const pushMessage = ref('')
const pushFeedback = ref(null)
const showRemoteForm = ref(false)
const remoteTab = ref('create')
const remoteUrl = ref('')
const newRepoName = ref('')
const newRepoPrivate = ref(true)
const savingRemote = ref(false)
const remoteError = ref('')
const instruction = ref('')
const selectedMode = ref('execute')
const confirmedActions = ref(new Map())
const userScrolled = ref(false)
const pollConnected = ref(false)

// Refs DOM
const feedEl = ref(null)
const feedBottomEl = ref(null)
const inputEl = ref(null)

// Polling
let pollTimer = null
let pollCursor = 0

// Launch
const launching = ref(false)
const launchProgress = ref(0)
const launchStatusText = ref('')
const launchUrl = ref(null)
const launchFinished = ref(false)
let launchProgressTimer = null
let launchPollTimer = null
let launchSessionId = null

const modes = [
  { value: 'read', label: 'READ', desc: 'NO_WRITE' },
  { value: 'plan', label: 'PLAN', desc: 'STRATEGY' },
  { value: 'execute', label: 'EXEC', desc: 'OVERWRITE' },
]

const activeStatuses = new Set(['reading', 'planning', 'building', 'running'])   

const isActive = computed(() =>
  session.value && activeStatuses.has(session.value.status),
)

const isAwaitingConfirmation = computed(() =>
  session.value?.status === 'awaiting_confirmation',
)

const totalCost = computed(() => parseFloat(session.value?.cost_usd ?? 0))       

const repoName = computed(() => {
  const remote = session.value?.project?.git_remote
  if (!remote) return null
  try {
    const url = new URL(remote)
    if (url.hostname === 'github.com') {
      return url.pathname.replace(/^\/|\.git$/g, '')
    }
  } catch (e) {
    // ignore
  }
  return remote
})

// ─── Lifecycle ────────────────────────────────────────────────────

onMounted(async () => {
  await loadSession()
  startPolling(true)
})

onUnmounted(() => {
  stopPolling()
  clearTimeout(launchProgressTimer)
  clearTimeout(launchPollTimer)
})

// ─── Chargement initial ───────────────────────────────────────────

async function loadSession() {
  loadingSession.value = true
  try {
    const data = await store.fetchSession(sessionId.value)
    session.value = data
    selectedMode.value = data.mode || 'execute'
  } finally {
    loadingSession.value = false
  }
}

// ─── Polling ──────────────────────────────────────────────────────

function startPolling(resetCursor = false) {
  stopPolling()
  if (resetCursor) pollCursor = 0
  schedulePoll(100)
}

function stopPolling() {
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
  pollConnected.value = false
}

function schedulePoll(delay = 1000) {
  pollTimer = setTimeout(doPoll, delay)
}

async function doPoll() {
  try {
    const { data } = await import('@/api/client.js').then((m) =>
      m.default.get(`/sessions/${sessionId.value}/poll`, { params: { cursor: pollCursor } }),
    )

    pollConnected.value = true

    if (data.session) {
      session.value = data.session
      store.updateOpenSession(data.session)
    }

    const newMessages = data.messages || []
    for (const msg of newMessages) {
      const id = String(msg.id)
      if (!messages.value.find((m) => String(m.id) === id)) {
        if (msg.role === 'user') {
          const optimisticIdx = messages.value.findIndex((m) => m._optimistic)   
          if (optimisticIdx !== -1) {
            messages.value.splice(optimisticIdx, 1)
          }
        }
        messages.value.push({ ...msg, _fromAPI: true })
        pollCursor = Math.max(pollCursor, msg.id)
        scrollToBottom()
      }
    }

    const terminalDone =
      ['done', 'error', 'idle'].includes(data.session?.status) && newMessages.length === 0

    if (!terminalDone || pollCursor === 0) {
      schedulePoll(terminalDone ? 3000 : 800)
    }
  } catch {
    pollConnected.value = false
    schedulePoll(3000)
  }
}

// ─── Actions ──────────────────────────────────────────────────────

async function submitInstruction() {
  const text = instruction.value.trim()
  if (!text || sending.value || isActive.value) return

  sending.value = true

  messages.value.push({
    id: `user-${Date.now()}`,
    role: 'user',
    type: 'user',
    content: { text },
    created_at: new Date().toISOString(),
    _optimistic: true,
  })

  instruction.value = ''
  scrollToBottom()

  try {
    const updated = await store.sendInstruction(sessionId.value, text, selectedMode.value)
    session.value = updated
    startPolling()
  } catch (e) {
    console.error('Erreur envoi instruction:', e)
  } finally {
    sending.value = false
  }
}

async function doConfirm(actionId, approved) {
  if (!actionId || confirming.value) return
  confirming.value = true
  try {
    const updated = await store.confirmAction(sessionId.value, actionId, approved)
    session.value = updated
    confirmedActions.value = new Map(confirmedActions.value.set(actionId, approved))
  } catch (e) {
    console.error('Erreur confirmation:', e)
  } finally {
    confirming.value = false
  }
}

async function doStop() {
  if (stopping.value) return
  stopping.value = true
  try {
    const updated = await store.stopSession(sessionId.value)
    session.value = updated
  } catch (e) {
    console.error('Erreur stop:', e)
  } finally {
    stopping.value = false
  }
}

// ─── Scroll ───────────────────────────────────────────────────────

function onFeedScroll() {
  if (!feedEl.value) return
  const el = feedEl.value
  const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60
  userScrolled.value = !atBottom
}

function scrollToBottom() {
  if (userScrolled.value) return
  nextTick(() => {
    feedBottomEl.value?.scrollIntoView({ behavior: 'smooth' })
  })
}

// ─── Remote GitHub ────────────────────────────────────────────────

function closeRemoteForm() {
  showRemoteForm.value = false
  remoteUrl.value = ''
  newRepoName.value = ''
  remoteError.value = ''
}

async function saveRemote() {
  if (!remoteUrl.value.trim() || savingRemote.value) return
  savingRemote.value = true
  remoteError.value = ''
  try {
    const { data } = await api.patch(`/projects/${session.value.project_id}`, {  
      git_remote: remoteUrl.value.trim(),
    })
    session.value = { ...session.value, project: { ...session.value.project, git_remote: data.data.git_remote } }
    closeRemoteForm()
  } catch (e) {
    remoteError.value = e.response?.data?.message ?? 'ERR_SAVE_REMOTE'
  } finally {
    savingRemote.value = false
  }
}

async function createGitHubRepo() {
  if (!newRepoName.value.trim() || savingRemote.value) return
  savingRemote.value = true
  remoteError.value = ''
  try {
    const { data } = await api.post(`/projects/${session.value.project_id}/github/create-repo`, {
      name: newRepoName.value.trim(),
      private: newRepoPrivate.value,
    })
    session.value = { ...session.value, project: { ...session.value.project, git_remote: data.data.git_remote } }
    pushFeedback.value = { ok: true, message: `✓ NODE ${data.repo.full_name} CREATED` }
    closeRemoteForm()
  } catch (e) {
    remoteError.value = e.response?.data?.message ?? 'ERR_CREATE_REMOTE'
  } finally {
    savingRemote.value = false
  }
}

// ─── Push GitHub ──────────────────────────────────────────────────

async function doPush() {
  if (pushing.value || !session.value) return
  pushing.value = true
  pushFeedback.value = null
  try {
    const { data } = await api.post(`/projects/${session.value.project_id}/git/push`, {
      message: pushMessage.value
    })
    pushFeedback.value = { ok: true, message: `✓ UPLINK_SYNC: ${data.data.remote} (${data.data.branch})` }
    pushMessage.value = ''
  } catch (e) {
    pushFeedback.value = { ok: false, message: e.response?.data?.message ?? 'ERR_UPLINK_SYNC' }
  } finally {
    pushing.value = false
  }
}

// ─── Lancement projet ─────────────────────────────────────────────

async function doLaunch() {
  if (launching.value || !session.value) return

  launching.value = true
  launchProgress.value = 0
  launchStatusText.value = 'INIT_START_SEQ'
  launchUrl.value = null
  launchFinished.value = false
  launchSessionId = null

  try {
    const { data: sessData } = await api.post(
      `/projects/${session.value.project_id}/sessions`,
      { title: 'START_NODE', mode: 'execute' },
    )
    launchSessionId = sessData.data.id

    await api.post(`/sessions/${launchSessionId}/instruction`, {
      instruction:
        "Lance ce projet en local. Identifie le stack, démarre le serveur de développement et donne-moi l'URL sur laquelle il est accessible.",
      mode: 'execute',
    })

    animateLaunchProgress()
    scheduleLaunchPoll(1500)
  } catch {
    launching.value = false
  }
}

function animateLaunchProgress() {
  const advance = () => {
    if (!launching.value || launchFinished.value) return
    const gap = 85 - launchProgress.value
    if (gap > 0.5) {
      launchProgress.value += Math.max(gap * 0.06, 0.5)
      launchProgress.value = Math.min(launchProgress.value, 85)
      launchProgressTimer = setTimeout(advance, 700 + Math.random() * 600)       
    }
  }
  advance()
}

function scheduleLaunchPoll(delay = 1500) {
  clearTimeout(launchPollTimer)
  launchPollTimer = setTimeout(pollLaunchSession, delay)
}

async function pollLaunchSession() {
  if (!launchSessionId || !launching.value) return

  try {
    const { data } = await api.get(`/sessions/${launchSessionId}`)
    const s = data.data

    launchStatusText.value =
      {
        idle: 'IDLE',
        reading: 'SCANNING',
        planning: 'PLANNING',
        building: 'DEPLOYING',
        running: 'DEPLOYING',
        awaiting_confirmation: 'AWAIT_AUTH',
        done: 'NODE_UP',
        error: 'DEPLOY_ERR',
      }[s.status] ?? 'IN_PROGRESS'

    if (s.status === 'done' || s.status === 'error') {
      clearTimeout(launchProgressTimer)
      launchFinished.value = true
      launchProgress.value = s.status === 'done' ? 100 : launchProgress.value    

      if (s.status === 'done') {
        try {
          const { data: pollData } = await api.get(`/sessions/${launchSessionId}/poll`, {
            params: { cursor: 0 },
          })
          const msgs = (pollData.messages || []).slice().reverse()
          for (const msg of msgs) {
            const text =
              typeof msg.content === 'string' ? msg.content : JSON.stringify(msg.content)
            const match = text.match(/https?:\/\/localhost:\d+/i)
            if (match) {
              launchUrl.value = match[0]
              break
            }
          }
        } catch {}
      }
    } else {
      scheduleLaunchPoll(1500)
    }
  } catch {
    scheduleLaunchPoll(3000)
  }
}

function closeLaunchOverlay() {
  clearTimeout(launchProgressTimer)
  clearTimeout(launchPollTimer)
  launching.value = false
  launchProgress.value = 0
  launchUrl.value = null
  launchFinished.value = false
  launchSessionId = null
}

// ─── Helpers ──────────────────────────────────────────────────────

function displayType(msg) {
  if (msg.role === 'user' || msg.type === 'user') return 'user'
  const metaType = msg.meta?.event_type
  if (metaType) return metaType
  if (msg.type === 'text') return 'message'
  return msg.type
}

function parseDiffLines(diff) {
  if (!diff) return []
  return diff.split('\n').map((line) => {
    if (line.startsWith('+++') || line.startsWith('---')) {
      return { text: line, cls: 'text-primary/30 font-bold', prefix: '' }        
    }
    if (line.startsWith('+')) {
      return { text: line.slice(1), cls: 'text-primary bg-primary/5', prefix: '+' }
    }
    if (line.startsWith('-')) {
      return { text: line.slice(1), cls: 'text-danger bg-danger/5', prefix: '-' }
    }
    if (line.startsWith('@@')) {
      return { text: line, cls: 'text-primary/40 bg-primary/5', prefix: '' }     
    }
    return { text: line, cls: 'text-primary/50', prefix: ' ' }
  })
}

function modeBadgeClass(mode) {
  return {
    read: 'border-primary/40 text-primary/60 bg-primary/5',
    plan: 'border-warning/40 text-warning/60 bg-warning/5',
    execute: 'border-primary text-primary bg-primary/10 glow',
  }[mode] ?? 'border-primary/20 text-primary/20'
}

function statusBadgeClass(status) {
  return {
    idle: 'border-primary/20 text-primary/40',
    reading: 'border-primary text-primary glow bg-primary/5',
    planning: 'border-warning text-warning glow bg-warning/5',
    building: 'border-primary text-primary glow animate-pulse',
    running: 'border-primary text-primary glow animate-pulse',
    awaiting_confirmation: 'border-warning text-warning glow bg-warning/10',     
    done: 'border-primary text-primary glow bg-primary/10',
    error: 'border-danger text-danger glow bg-danger/10',
  }[status] ?? 'border-primary/20 text-primary/20'
}

function statusLabel(status) {
  return {
    idle: 'READY',
    reading: 'SCANNING',
    planning: 'PLANNING',
    building: 'CONSTRUCTING',
    running: 'EXECUTING',
    awaiting_confirmation: 'AWAIT_AUTH',
    done: 'TERMINATED',
    error: 'FAILED',
  }[status] ?? (status ? status.toUpperCase() : 'UNKNOWN')
}

function formatCost(cost) {
  return parseFloat(cost).toFixed(4)
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toISOString().replace('T', ' ').substring(0, 19)
}
</script>

<style>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(0, 255, 65, 0.05);
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(0, 255, 65, 0.2);
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 255, 65, 0.5);
}

.glow-warning {
   text-shadow: 0 0 5px var(--color-warning), 0 0 10px var(--color-warning);     
}

.launch-fade-enter-active,
.launch-fade-leave-active {
  transition: opacity 0.25s ease;
}
.launch-fade-enter-from,
.launch-fade-leave-to {
  opacity: 0;
}
</style>
