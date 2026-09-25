import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
            boxShadow: {
                '2xs': '0 1px 2px 0 rgba(0, 0, 0, 0.03)',
                'xs': '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
            },
            backdropBlur: {
                'xs': '2px',
            },
            spacing: {
                '0.2': '0.05rem',
            },
            colors: {
                primary: {
                    DEFAULT: '#F5691A',
                    hover: '#E05A10',
                    light: '#fff5ef',
                    border: '#fed7aa',
                    tint: '#ea580c',
                    container: '#f5691a',
                    fixed: '#ffdbcd',
                    'fixed-dim': '#ffb595',
                },
                secondary: {
                    DEFAULT: '#1e43e7',
                    hover: '#1635b8',
                    container: '#4160ff',
                    'container-hover': '#324fd9',
                    fixed: '#dee0ff',
                    'fixed-dim': '#bac3ff',
                },
                tertiary: {
                    DEFAULT: '#256d00',
                    container: '#46a917',
                    fixed: '#93fb65',
                    'fixed-dim': '#78de4b',
                },
                navy: {
                    DEFAULT: '#111A2B',
                    900: '#111A2B',
                    800: '#1a273e',
                    700: '#263852',
                    light: '#1C2940',
                    lighter: '#263452',
                    dark: '#0D1320',
                },
                surface: {
                    DEFAULT: '#f9f9ff',
                    subtle: '#f8fafc',
                    dim: '#d4daea',
                    bright: '#f9f9ff',
                    container: '#e8eeff',
                    'container-lowest': '#ffffff',
                    'container-low': '#f1f3ff',
                    'container-high': '#e3e8f9',
                    'container-highest': '#dde2f3',
                    variant: '#dde2f3',
                },
                'on-surface': '#161c27',
                'on-surface-variant': '#594137',
            },
        },
    },

    plugins: [forms],
};

