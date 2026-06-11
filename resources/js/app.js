import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router/index.js';
import App from './App.vue';
import '../css/app.css';

const app = createApp(App);

app.use(createPinia());
app.use(router);

app.mount('#app');

// Service worker PWA (notifications push + installation écran d'accueil).
// Le SW vit dans public/ (hors de Vite) pour être servi à la racine du scope.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((e) => {
            console.warn('Service worker non enregistré :', e);
        });
    });
}
