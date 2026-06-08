import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/api/client.js';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const checked = ref(false);

    async function fetchUser() {
        try {
            const { data } = await api.get('/me');
            user.value = data.data;
        } catch {
            user.value = null;
        } finally {
            checked.value = true;
        }
    }

    async function login(email, password) {
        // Utilise axios brut (sans baseURL /api) — Sanctum n'est pas sous /api
        await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
        await api.post('/login', { email, password });
        await fetchUser();
    }

    async function logout() {
        await api.post('/logout');
        user.value = null;
    }

    return { user, checked, fetchUser, login, logout };
});
