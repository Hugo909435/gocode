import { defineStore } from 'pinia';
import { ref, watch } from 'vue';
import api from '@/api/client.js';

export const useSettingsStore = defineStore('settings', () => {
    const github = ref({ configured: false, pat_preview: null });
    const loading = ref(false);
    const theme = ref(localStorage.getItem('theme') || 'hacker');

    async function fetchGithub() {
        const { data } = await api.get('/settings/github');
        github.value = data.data;
    }

    async function updateGithubPat(pat) {
        loading.value = true;
        try {
            const { data } = await api.put('/settings/github', { pat });
            github.value = data.data;
            return data.data;
        } finally {
            loading.value = false;
        }
    }

    function setTheme(newTheme) {
        theme.value = newTheme;
        localStorage.setItem('theme', newTheme);
        applyTheme(newTheme);
    }

    function applyTheme(targetTheme) {
        const html = document.documentElement;
        html.classList.remove('theme-hacker', 'theme-modern');
        if (targetTheme === 'modern') {
            html.classList.add('theme-modern');
        } else {
            html.classList.add('theme-hacker');
        }
    }

    // Initial apply
    applyTheme(theme.value);

    return { github, loading, theme, fetchGithub, updateGithubPat, setTheme };
});
