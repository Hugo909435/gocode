import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth.js';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guest: true },
    },
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'dashboard',
                component: () => import('@/pages/DashboardPage.vue'),
            },
            {
                path: 'projects',
                name: 'projects',
                component: () => import('@/pages/ProjectsPage.vue'),
            },
            {
                path: 'projects/:id/sessions',
                name: 'project-sessions',
                component: () => import('@/pages/SessionsPage.vue'),
            },
            {
                path: 'sessions/:id',
                name: 'session',
                component: () => import('@/pages/SessionPage.vue'),
            },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Garde de navigation : redirige vers /login si non authentifié
router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.checked) {
        await auth.fetchUser();
    }

    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login' };
    }

    if (to.meta.guest && auth.user) {
        return { name: 'dashboard' };
    }
});

export default router;
