import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api/client.js'

export const useSettingsStore = defineStore('settings', () => {
    const github = ref({ configured: false, pat_preview: null })
    const loading = ref(false)

    async function fetchGithub() {
        const { data } = await api.get('/settings/github')
        github.value = data.data
    }

    async function updateGithubPat(pat) {
        loading.value = true
        try {
            const { data } = await api.put('/settings/github', { pat })
            github.value = data.data
            return data.data
        } finally {
            loading.value = false
        }
    }

    return { github, loading, fetchGithub, updateGithubPat }
})
