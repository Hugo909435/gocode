import globals from 'globals';
import pluginVue from 'eslint-plugin-vue';
import { defineConfig } from 'eslint/config';
import configPrettier from '@vue/eslint-config-prettier';

export default defineConfig([
    {
        name: 'app/files-to-lint',
        files: ['resources/js/**/*.{js,vue}'],
    },
    {
        name: 'app/files-to-ignore',
        ignores: ['public/**', 'vendor/**', 'node_modules/**'],
    },
    {
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
    },
    ...pluginVue.configs['flat/recommended'],
    configPrettier,
]);
