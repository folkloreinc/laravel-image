import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default [
    {
        ignores: ['js/lib/**', 'js/es/**', 'node_modules/**', 'vendor/**', 'coverage/**'],
    },
    js.configs.recommended,
    {
        files: ['js/src/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
    },
    {
        files: ['js/src/**/__tests__/**/*.js'],
        languageOptions: {
            globals: globals.jest,
        },
    },
    prettier,
];
