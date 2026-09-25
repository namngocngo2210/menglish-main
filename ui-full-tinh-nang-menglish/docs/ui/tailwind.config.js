/**
 * MEnglish ERP — Tailwind CSS 3.4 theme (NGUỒN CHUẨN GIAO DIỆN)
 *
 * Sinh bằng majority-vote từ tailwind.config của 66 mockup (code.html), giữ toàn bộ key:
 *  - Key trùng nhau giữa các màn: lấy giá trị đa số (≈60/66 màn đồng thuận).
 *  - Key chỉ vài màn dùng (men-orange, status-*, navy, brand…): giữ nguyên để màn đó không vỡ.
 *  - Bổ sung: sidebar (#111a2b, lấy từ App Shell), stage-* (màu cột Kanban CRM), boxShadow level-2/3 (DESIGN.md).
 *  - spacing.sidebar-width = 240px theo App Shell đang hiển thị (DESIGN.md ghi 260px nhưng không màn nào dùng).
 * Chi tiết & kết quả đối chiếu pixel: docs/system-design.md mục 15.
 * KHÔNG sửa tay màu/typography. Mọi thay đổi phải qua design review + chạy lại visual test.
 */
/** @type {import("tailwindcss").Config} */
module.exports = {
  content: [
    "./resources/views/**/*.blade.php",
    "./app/Modules/**/Resources/views/**/*.blade.php",
    "./app/Modules/**/Http/Livewire/**/*.php",
    "./resources/js/**/*.js",
  ],
  darkMode: "class", // Phase 1 chỉ light theme. Giữ key để class dark: trong markup mockup không sinh CSS ngoài ý muốn
  theme: {
    extend: {
      "colors": {
        "background": "#f9f9ff",
        "brand": {
          "DEFAULT": "#f5691a",
          "hover": "#dc5208",
          "surface": "#fff7ed",
          "dark": "#111A2B",
          "navy": "#1B2A4A"
        },
        "error": "#ba1a1a",
        "error-container": "#ffdad6",
        "inverse-on-surface": "#ecf0ff",
        "inverse-primary": "#ffb595",
        "inverse-surface": "#2a303d",
        "men-orange": "#f5691a",
        "men-orange-hover": "#e45e15",
        "navy": {
          "50": "#F4F7FB",
          "100": "#E9EEF7",
          "700": "#2A3B5C",
          "800": "#1B273F",
          "900": "#111A2B"
        },
        "on-background": "#161c27",
        "on-error": "#ffffff",
        "on-error-container": "#93000a",
        "on-primary": "#ffffff",
        "on-primary-container": "#521c00",
        "on-primary-fixed": "#360f00",
        "on-primary-fixed-variant": "#7c2e00",
        "on-secondary": "#ffffff",
        "on-secondary-container": "#faf7ff",
        "on-secondary-fixed": "#00105b",
        "on-secondary-fixed-variant": "#002fc9",
        "on-surface": "#161c27",
        "on-surface-variant": "#594137",
        "on-tertiary": "#ffffff",
        "on-tertiary-container": "#0e3500",
        "on-tertiary-fixed": "#062100",
        "on-tertiary-fixed-variant": "#1a5200",
        "outline": "#8d7165",
        "outline-variant": "#e1bfb2",
        "primary": "#a23f00",
        "primary-container": "#f5691a",
        "primary-dark": "#d9530b",
        "primary-fixed": "#ffdbcd",
        "primary-fixed-dim": "#ffb595",
        "primary-light": "#fff3eb",
        "secondary": "#1e43e7",
        "secondary-container": "#4160ff",
        "secondary-fixed": "#dee0ff",
        "secondary-fixed-dim": "#bac3ff",
        "sidebar": "#111a2b",
        "stage": {
          "new": "#1e43e7",
          "consulting": "#256d00",
          "test_scheduled": "#a23f00",
          "tested": "#2563eb",
          "result_sent": "#9333ea",
          "closing": "#d97706",
          "won": "#16a34a",
          "lost": "#ba1a1a"
        },
        "status-blocked": "#ea580c",
        "status-canceled": "#6b7280",
        "status-done": "#22c55e",
        "status-new": "#9ca3af",
        "status-overdue": "#ef4444",
        "status-pending": "#fb923c",
        "status-progress": "#facc15",
        "surface": "#f9f9ff",
        "surface-bright": "#f9f9ff",
        "surface-container": "#e8eeff",
        "surface-container-high": "#e3e8f9",
        "surface-container-highest": "#dde2f3",
        "surface-container-low": "#f1f3ff",
        "surface-container-lowest": "#ffffff",
        "surface-dim": "#d4daea",
        "surface-tint": "#a23f00",
        "surface-variant": "#dde2f3",
        "tertiary": "#256d00",
        "tertiary-container": "#46a917",
        "tertiary-fixed": "#93fb65",
        "tertiary-fixed-dim": "#78de4b"
      },
      "fontFamily": {
        "label-caps": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "h3-card": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "caption-badge": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "body-semibold": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "body-main": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "h2-desktop": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "h1": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "h3": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "h2": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "body-medium": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "body-base": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "label": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "code": [
          "JetBrains Mono",
          "ui-monospace",
          "monospace"
        ],
        "caption": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "body-small": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ],
        "sans": [
          "Be Vietnam Pro",
          "ui-sans-serif",
          "system-ui",
          "sans-serif"
        ]
      },
      "fontSize": {
        "label-caps": [
          "12px",
          {
            "lineHeight": "16px",
            "letterSpacing": "0.05em",
            "fontWeight": "700"
          }
        ],
        "h3-card": [
          "18px",
          {
            "lineHeight": "26px",
            "fontWeight": "600"
          }
        ],
        "caption-badge": [
          "11px",
          {
            "lineHeight": "14px",
            "fontWeight": "600"
          }
        ],
        "body-semibold": [
          "14px",
          {
            "lineHeight": "20px",
            "fontWeight": "600"
          }
        ],
        "body-main": [
          "14px",
          {
            "lineHeight": "20px",
            "fontWeight": "400"
          }
        ],
        "h2-desktop": [
          "22px",
          {
            "lineHeight": "32px",
            "fontWeight": "700"
          }
        ],
        "h1": [
          "28px",
          {
            "lineHeight": "36px",
            "letterSpacing": "-0.02em",
            "fontWeight": "700"
          }
        ],
        "h3": [
          "18px",
          {
            "lineHeight": "26px",
            "fontWeight": "600"
          }
        ],
        "h2": [
          "22px",
          {
            "lineHeight": "30px",
            "letterSpacing": "-0.01em",
            "fontWeight": "600"
          }
        ],
        "body-medium": [
          "14px",
          {
            "lineHeight": "20px",
            "fontWeight": "500"
          }
        ],
        "body-base": [
          "14px",
          {
            "lineHeight": "20px",
            "fontWeight": "400"
          }
        ],
        "label": [
          "11px",
          {
            "lineHeight": "16px",
            "letterSpacing": "0.05em",
            "fontWeight": "600"
          }
        ],
        "code": [
          "13px",
          {
            "lineHeight": "18px",
            "fontWeight": "400"
          }
        ],
        "caption": [
          "11px",
          {
            "lineHeight": "16px",
            "fontWeight": "400"
          }
        ],
        "body-small": [
          "13px",
          {
            "lineHeight": "18px",
            "fontWeight": "400"
          }
        ]
      },
      "borderRadius": {
        "DEFAULT": "0.25rem",
        "lg": "0.5rem",
        "xl": "0.75rem",
        "full": "9999px"
      },
      "spacing": {
        "gutter": "16px",
        "xs": "4px",
        "sm": "8px",
        "lg": "24px",
        "md": "16px",
        "xl": "32px",
        "base": "8px",
        "sidebar-width": "240px",
        "header-height": "64px"
      },
      "boxShadow": {
        "level-2": "0px 4px 12px rgba(0,0,0,0.05)",
        "level-3": "0px 8px 24px rgba(0,0,0,0.12)"
      }
    },
  },
  plugins: [require("@tailwindcss/forms"), require("@tailwindcss/container-queries")],
};
