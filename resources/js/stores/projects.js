import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/api/client.js';

export const useProjectsStore = defineStore('projects', () => {
    const projects = ref([]);
    const loading = ref(false);

    async function fetchProjects() {
        loading.value = true;
        try {
            const { data } = await api.get('/projects');
            projects.value = data.data;
        } finally {
            loading.value = false;
        }
    }

    async function createProject(payload) {
        const { data } = await api.post('/projects', payload);
        projects.value.unshift(data.data);
        return data.data;
    }

    async function updateProject(id, payload) {
        const { data } = await api.patch(`/projects/${id}`, payload);
        const idx = projects.value.findIndex((p) => p.id === id);
        if (idx !== -1) projects.value[idx] = data.data;
        return data.data;
    }

    async function deleteProject(id) {
        await api.delete(`/projects/${id}`);
        projects.value = projects.value.filter((p) => p.id !== id);
    }

    async function linkGitHub(id, repoUrl) {
        const { data } = await api.post(`/projects/${id}/github/link`, { repo_url: repoUrl });
        const idx = projects.value.findIndex((p) => p.id === id);
        if (idx !== -1) projects.value[idx] = data.data;
        return data.data;
    }

    async function unlinkGitHub(id) {
        const { data } = await api.delete(`/projects/${id}/github/unlink`);
        const idx = projects.value.findIndex((p) => p.id === id);
        if (idx !== -1) projects.value[idx] = data.data;
        return data.data;
    }

    async function refreshProject(id) {
        const { data } = await api.get(`/projects/${id}`);
        const idx = projects.value.findIndex((p) => p.id === id);
        if (idx !== -1) projects.value[idx] = data.data;
        return data.data;
    }

    return {
        projects,
        loading,
        fetchProjects,
        createProject,
        updateProject,
        deleteProject,
        linkGitHub,
        unlinkGitHub,
        refreshProject,
    };
});
