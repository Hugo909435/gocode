import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/api/client.js';

export const useSessionsStore = defineStore('sessions', () => {
    const sessions = ref([]);
    const current = ref(null);
    const loading = ref(false);

    // Sessions récemment ouvertes — alimentées par fetchSession + updateOpenSession
    const openSessions = ref([]);

    async function fetchSessions(projectId) {
        loading.value = true;
        try {
            const { data } = await api.get(`/projects/${projectId}/sessions`);
            sessions.value = data.data;
        } finally {
            loading.value = false;
        }
    }

    async function createSession(projectId, payload) {
        const { data } = await api.post(`/projects/${projectId}/sessions`, payload);
        sessions.value.unshift(data.data);
        return data.data;
    }

    async function fetchSession(id) {
        const { data } = await api.get(`/sessions/${id}`);
        current.value = data.data;
        _trackOpenSession(data.data);
        return data.data;
    }

    async function sendInstruction(id, instruction, mode, skills = []) {
        const { data } = await api.post(`/sessions/${id}/instruction`, {
            instruction,
            mode,
            skills,
        });
        current.value = data.data;
        return data.data;
    }

    async function confirmAction(id, actionId, approved) {
        const { data } = await api.post(`/sessions/${id}/confirm`, {
            action_id: actionId,
            approved,
        });
        current.value = data.data;
        return data.data;
    }

    async function stopSession(id) {
        const { data } = await api.post(`/sessions/${id}/stop`);
        current.value = data.data;
        return data.data;
    }

    async function updateSession(id, payload) {
        const { data } = await api.patch(`/sessions/${id}`, payload);
        current.value = data.data;
        return data.data;
    }

    async function clearMessages(id) {
        const { data } = await api.delete(`/sessions/${id}/messages`);
        current.value = data.data;
        return data.data;
    }

    // Appelé depuis le polling pour maintenir le statut à jour dans openSessions
    function updateOpenSession(sessionData) {
        const idx = openSessions.value.findIndex((s) => s.id === sessionData.id);
        if (idx !== -1) {
            openSessions.value[idx] = sessionData;
        }
    }

    function _trackOpenSession(sessionData) {
        const idx = openSessions.value.findIndex((s) => s.id === sessionData.id);
        if (idx !== -1) {
            openSessions.value[idx] = sessionData;
        } else {
            openSessions.value.unshift(sessionData);
            if (openSessions.value.length > 8) openSessions.value.pop();
        }
    }

    return {
        sessions,
        current,
        loading,
        openSessions,
        fetchSessions,
        createSession,
        fetchSession,
        sendInstruction,
        confirmAction,
        stopSession,
        updateSession,
        clearMessages,
        updateOpenSession,
    };
});
