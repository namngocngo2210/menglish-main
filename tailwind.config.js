import forms from '@tailwindcss/forms';
import containerQueries from '@tailwindcss/container-queries';

/**
 * MEnglish ERP — Tailwind CSS 3.4 theme.
 *
 * Nguồn chuẩn: ui-full-tinh-nang-menglish/docs/ui/tailwind.config.js (majority-vote từ 66 mockup).
 * KHÔNG sửa tay giá trị token màu/typography — mọi thay đổi phải qua design review.
 *
 * Quy ước màu thương hiệu (khác bản cũ):
 *  - `primary-container` (#f5691a) = màu cam CTA (nền nút chính, item active, focus ring).
 *  - `primary` (#a23f00)           = màu nhấn tối dùng cho chữ/viền (link, tab active, icon).
 * Các alias tương thích ngược (primary-hover/border/tint/light, navy.*, surface.subtle,
 * secondary-hover…) được giữ để view cũ không mất style.
 */

const sans = ['Be Vietnam Pro', 'ui-sans-serif', 'system-ui', 'sans-serif'];
const mono = ['JetBrains Mono', 'ui-monospace', 'monospace'];

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        // Model/Service trả về class badge (vd. badge_color) cần được quét.
        './app/**/*.php',
    ],

    // Phase 1 chỉ light theme: class dark: chỉ bật khi có .dark trên <html>.
    darkMode: 'class',

    theme: {
        extend: {
            // Breakpoint của App Shell (DESIGN.md): ≥1200px sidebar đầy đủ, 768–1199px thu gọn icon.
            screens: {
                desktop: '1200px',
            },

            colors: {
                background: '#f9f9ff',
                sidebar: '#111a2b',

                primary: {
                    DEFAULT: '#a23f00',
                    container: '#f5691a',
                    dark: '#d9530b',
                    fixed: '#ffdbcd',
                    'fixed-dim': '#ffb595',
                    light: '#fff3eb',
                    // Alias tương thích ngược
                    hover: '#dc5208',
                    border: '#ffdbcd',
                    tint: '#d9530b',
                },
                'on-primary': '#ffffff',
                'on-primary-container': '#521c00',
                'on-primary-fixed': '#360f00',
                'on-primary-fixed-variant': '#7c2e00',
                'inverse-primary': '#ffb595',

                secondary: {
                    DEFAULT: '#1e43e7',
                    container: '#4160ff',
                    fixed: '#dee0ff',
                    'fixed-dim': '#bac3ff',
                    // Alias tương thích ngược
                    hover: '#1635b8',
                    'container-hover': '#324fd9',
                },
                'on-secondary': '#ffffff',
                'on-secondary-container': '#faf7ff',
                'on-secondary-fixed': '#00105b',
                'on-secondary-fixed-variant': '#002fc9',

                tertiary: {
                    DEFAULT: '#256d00',
                    container: '#46a917',
                    fixed: '#93fb65',
                    'fixed-dim': '#78de4b',
                },
                'on-tertiary': '#ffffff',
                'on-tertiary-container': '#0e3500',
                'on-tertiary-fixed': '#062100',
                'on-tertiary-fixed-variant': '#1a5200',

                error: {
                    DEFAULT: '#ba1a1a',
                    container: '#ffdad6',
                },
                'on-error': '#ffffff',
                'on-error-container': '#93000a',

                surface: {
                    DEFAULT: '#f9f9ff',
                    bright: '#f9f9ff',
                    dim: '#d4daea',
                    tint: '#a23f00',
                    variant: '#dde2f3',
                    container: '#e8eeff',
                    'container-lowest': '#ffffff',
                    'container-low': '#f1f3ff',
                    'container-high': '#e3e8f9',
                    'container-highest': '#dde2f3',
                    // Alias tương thích ngược
                    subtle: '#f8fafc',
                },
                'on-surface': '#161c27',
                'on-surface-variant': '#594137',
                'on-background': '#161c27',
                'inverse-surface': '#2a303d',
                'inverse-on-surface': '#ecf0ff',

                outline: {
                    DEFAULT: '#8d7165',
                    variant: '#e1bfb2',
                },

                brand: {
                    DEFAULT: '#f5691a',
                    hover: '#dc5208',
                    surface: '#fff7ed',
                    dark: '#111A2B',
                    navy: '#1B2A4A',
                },
                'men-orange': '#f5691a',
                'men-orange-hover': '#e45e15',

                navy: {
                    50: '#F4F7FB',
                    100: '#E9EEF7',
                    700: '#2A3B5C',
                    800: '#1B273F',
                    900: '#111A2B',
                    // Alias tương thích ngược (sidebar cũ)
                    DEFAULT: '#111A2B',
                    light: '#1C2940',
                    lighter: '#263452',
                    dark: '#0D1320',
                },

                // Màu cột Kanban CRM theo giai đoạn
                stage: {
                    new: '#1e43e7',
                    consulting: '#256d00',
                    test_scheduled: '#a23f00',
                    tested: '#2563eb',
                    result_sent: '#9333ea',
                    closing: '#d97706',
                    won: '#16a34a',
                    lost: '#ba1a1a',
                },

                // Trạng thái công việc
                'status-blocked': '#ea580c',
                'status-canceled': '#6b7280',
                'status-done': '#22c55e',
                'status-new': '#9ca3af',
                'status-overdue': '#ef4444',
                'status-pending': '#fb923c',
                'status-progress': '#facc15',
            },

            fontFamily: {
                sans,
                mono,
                h1: sans,
                h2: sans,
                'h2-desktop': sans,
                h3: sans,
                'h3-card': sans,
                'body-base': sans,
                'body-main': sans,
                'body-medium': sans,
                'body-semibold': sans,
                'body-small': sans,
                label: sans,
                'label-caps': sans,
                caption: sans,
                'caption-badge': sans,
                code: mono,
            },

            fontSize: {
                h1: ['28px', { lineHeight: '36px', letterSpacing: '-0.02em', fontWeight: '700' }],
                h2: ['22px', { lineHeight: '30px', letterSpacing: '-0.01em', fontWeight: '600' }],
                'h2-desktop': ['22px', { lineHeight: '32px', fontWeight: '700' }],
                h3: ['18px', { lineHeight: '26px', fontWeight: '600' }],
                'h3-card': ['18px', { lineHeight: '26px', fontWeight: '600' }],
                'body-base': ['14px', { lineHeight: '20px', fontWeight: '400' }],
                'body-main': ['14px', { lineHeight: '20px', fontWeight: '400' }],
                'body-medium': ['14px', { lineHeight: '20px', fontWeight: '500' }],
                'body-semibold': ['14px', { lineHeight: '20px', fontWeight: '600' }],
                'body-small': ['13px', { lineHeight: '18px', fontWeight: '400' }],
                label: ['11px', { lineHeight: '16px', letterSpacing: '0.05em', fontWeight: '600' }],
                'label-caps': ['12px', { lineHeight: '16px', letterSpacing: '0.05em', fontWeight: '700' }],
                caption: ['11px', { lineHeight: '16px', fontWeight: '400' }],
                'caption-badge': ['11px', { lineHeight: '14px', fontWeight: '600' }],
                code: ['13px', { lineHeight: '18px', fontWeight: '400' }],
            },

            borderRadius: {
                DEFAULT: '0.25rem',
                lg: '0.5rem',
                xl: '0.75rem',
                full: '9999px',
            },

            spacing: {
                '0.2': '0.05rem',
                base: '8px',
                xs: '4px',
                sm: '8px',
                md: '16px',
                lg: '24px',
                xl: '32px',
                gutter: '16px',
                'sidebar-width': '240px',
                'sidebar-collapsed': '72px',
                'header-height': '64px',
            },

            boxShadow: {
                '2xs': '0 1px 2px 0 rgba(0, 0, 0, 0.03)',
                xs: '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
                'level-2': '0px 4px 12px rgba(0,0,0,0.05)',
                'level-3': '0px 8px 24px rgba(0,0,0,0.12)',
            },

            backdropBlur: {
                xs: '2px',
            },
        },
    },

    plugins: [forms, containerQueries],
};
