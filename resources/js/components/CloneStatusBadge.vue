<template>
    <span v-if="!project.git_remote" class="text-[10px] text-primary/30 uppercase tracking-tighter"
        >No_Remote</span
    >

    <span
        v-else-if="project.clone_status === 'cloned'"
        class="inline-flex items-center gap-1.5 text-[10px] font-bold text-primary border border-primary/30 bg-primary/5 px-2 py-0.5 uppercase tracking-tighter"
    >
        <div class="w-1.5 h-1.5 bg-primary shadow-[0_0_5px_var(--color-primary)]"></div>
        Cloned
    </span>

    <button
        v-else-if="project.clone_status === 'pending' || project.clone_status === 'cloning'"
        class="inline-flex items-center gap-1.5 text-[10px] font-bold text-warning border border-warning/30 bg-warning/5 px-2 py-0.5 cursor-default uppercase tracking-tighter"
        @click.stop="$emit('poll')"
    >
        <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
            />
        </svg>
        {{ project.clone_status === 'pending' ? 'Pending' : 'Cloning' }}
    </button>

    <span
        v-else-if="project.clone_status === 'error'"
        class="inline-flex items-center gap-1.5 text-[10px] font-bold text-danger border border-danger/30 bg-danger/5 px-2 py-0.5 uppercase tracking-tighter"
        :title="project.clone_error"
    >
        <div class="w-1.5 h-1.5 bg-danger shadow-[0_0_5px_var(--color-danger)]"></div>
        Clone_Err
    </span>

    <span
        v-if="status"
        class="inline-flex items-center gap-1.5 text-[10px] text-primary/50 border border-primary/20 bg-bg-terminal px-2 py-0.5 uppercase tracking-tighter"
    >
        Linked
    </span>
</template>

<script setup>
defineProps({ project: Object });
defineEmits(['poll']);
</script>
