import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/api/client.js';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const checked = ref(false);

    async function fetchUser() {
        try {
            const { data } = await api.get('/user');
            user.value = data;
        } catch {
            user.value = null;
        } finally {
            checked.value = true;
        }
    }

    async function login(email, password) {
        await api.get('/sanctum/csrf-cookie');
        await api.post('/login', { email, password });
        await fetchUser();
    }

    async function logout() {
        await api.post('/logout');
        user.value = null;
    }

    return { user, checked, fetchUser, login, logout };
});
