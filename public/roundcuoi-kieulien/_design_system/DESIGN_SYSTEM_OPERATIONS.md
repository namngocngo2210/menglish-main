---
name: MENGLISH Operations Core
colors:
  surface: '#f9f9ff'
  surface-dim: '#d4daea'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f1f3ff'
  surface-container: '#e8eeff'
  surface-container-high: '#e3e8f9'
  surface-container-highest: '#dde2f3'
  on-surface: '#161c27'
  on-surface-variant: '#594137'
  inverse-surface: '#2a303d'
  inverse-on-surface: '#ecf0ff'
  outline: '#8d7165'
  outline-variant: '#e1bfb2'
  surface-tint: '#a23f00'
  primary: '#a23f00'
  on-primary: '#ffffff'
  primary-container: '#f5691a'
  on-primary-container: '#521c00'
  inverse-primary: '#ffb595'
  secondary: '#1e43e7'
  on-secondary: '#ffffff'
  secondary-container: '#4160ff'
  on-secondary-container: '#faf7ff'
  tertiary: '#256d00'
  on-tertiary: '#ffffff'
  tertiary-container: '#46a917'
  on-tertiary-container: '#0e3500'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffdbcd'
  primary-fixed-dim: '#ffb595'
  on-primary-fixed: '#360f00'
  on-primary-fixed-variant: '#7c2e00'
  secondary-fixed: '#dee0ff'
  secondary-fixed-dim: '#bac3ff'
  on-secondary-fixed: '#00105b'
  on-secondary-fixed-variant: '#002fc9'
  tertiary-fixed: '#93fb65'
  tertiary-fixed-dim: '#78de4b'
  on-tertiary-fixed: '#062100'
  on-tertiary-fixed-variant: '#1a5200'
  background: '#f9f9ff'
  on-background: '#161c27'
  surface-variant: '#dde2f3'
typography:
  display:
    fontFamily: Be Vietnam Pro
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Be Vietnam Pro
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.015em
  headline-md:
    fontFamily: Be Vietnam Pro
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Be Vietnam Pro
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
    letterSpacing: 0em
  body-lg:
    fontFamily: Be Vietnam Pro
    fontSize: 15px
    fontWeight: '400'
    lineHeight: 22px
    letterSpacing: 0em
  body-md:
    fontFamily: Be Vietnam Pro
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
    letterSpacing: 0em
  body-sm:
    fontFamily: Be Vietnam Pro
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
    letterSpacing: 0em
  label-md:
    fontFamily: Be Vietnam Pro
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 18px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Be Vietnam Pro
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  code-md:
    fontFamily: JetBrains Mono
    fontSize: 13px
    fontWeight: '500'
    lineHeight: 18px
    letterSpacing: 0em
  code-sm:
    fontFamily: JetBrains Mono
    fontSize: 11px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  2xl: 48px
  3xl: 64px
---

## Brand & Style

This design system drives the internal operations, academic tracking, and student acquisition CRM for MENGLISH (ME Education). Striking a deliberate equilibrium between enterprise-grade rigor and educational warmth, the visual language communicates reliability, momentum, and operational precision.

### Target Audience & Mindset
- **Primary Users:** Academic advisors, course consultants (tele-sales), operational coordinators, and academic heads.
- **Mental State:** Fast-paced, handling hundreds of leads, scheduling assessment tests, managing class rosters, and resolving SLA bottlenecks under pressure.
- **Evoked Emotions:** Clear control, effortless focus, approachable efficiency, and visual trust without operational fatigue.

### Design Direction
- **Corporate Functional Meets Modern Kinetic:** Combines crisp structural alignment, density-tuned data layouts, and high-legibility typographic scale with energetic accents.
- **Clarity Principle:** Strict visual hierarchy separates high-frequency operational controls from structural containers. The deep navy sidebar frames the workspace securely, while crisp neutral surfaces keep active work areas lightweight and distraction-free.

## Colors

The color palette is built for rapid categorization, immediate visual prioritization, and zero cognitive ambiguity across heavy tabular data and multi-column Kanban workflows.

### Cardinal Rule: Reserved Primary
- **Primary Orange (`#F5691A`):** Strictly reserved for singular, high-intent primary actions (e.g., "Tạo học viên mới", "Xác nhận ghi danh", "Lưu thay đổi"). It must **never** be used for status pills, badge backgrounds, table tag elements, or incidental icons.

### Architectural Tiers
- **Sidebar & Persistent Shell (`#111A2B`):** Establishes an anchor frame on the left, grounding the administrative context. Active navigation states use white text on subtle translucent white surfaces or primary indicator bars.
- **Surface Canvas (`#F7F8FA`):** Low-strain background color ensuring sustained comfort during 8-hour shift work.
- **Card & Table Elevated Backgrounds (`#FFFFFF`):** High-contrast, clean work surfaces framed by `#D6DCE5` micro-borders.

### Kanban & Lifecycle Pipeline
- `leadMoi` (`#F43F7E`): High-energy magenta to call attention to uncontacted leads.
- `chamSoc` (`#F5A623`): Warm amber denoting in-progress nurture calls.
- `henTest` (`#7C5CFC`): Royal violet indicating scheduled placement testing.
- `daTest` (`#3B5BFB`): Solid cobalt denoting completed test evaluations waiting for assessment.
- `guiKQ` (`#17B6C4`): Crisp cyan signaling final result dispatch and enrollment readiness.

## Typography

Typography prioritizes localized Vietnamese diacritics rendering with optimal glyph balance using **Be Vietnam Pro**. Administrative identifiers, student ID codes, test scores, timestamps, and currency values utilize **JetBrains Mono** to prevent misreading numerical data.

### Formatting Guidelines
- **Tabular Figures:** Always apply `font-feature-settings: "tnum" 1` across all numerical displays in tables, KPI metrics, and time counters.
- **IDs & Technical Tokens:** Student codes (e.g., `ME-2024-8842`), phone numbers, and transaction IDs must use `code-md` or `code-sm` to maintain strict column alignment.
- **Truncation:** Any user names or course titles overflowing their designated bounds must truncate with `text-overflow: ellipsis` alongside native browser tooltips (`title`) or custom hover tooltips.

## Layout & Spacing

The layout is engineered around an unyielding 8px mathematical baseline grid. Administrative workflows require high information throughput; padding and margins contract or expand systematically to preserve vertical rhythm.

### Grid Architecture
- **Sidebar Width:** Fixed at 260px (expanded) and collapses cleanly to 68px (icon-only mode).
- **Application Shell:** Fluid workspace (`calc(100vw - 260px)`) spanning standard display resolutions up to 1920px, with horizontal scroll protection for dense tables and Kanban boards.
- **Filter Bars & Viewports:** Fixed sticky top bar with height 64px, containing search inputs, batch filters, and primary CTAs.
- **Responsive Breakpoints:**
  - `Desktop Wide (>= 1440px)`: Full 5-column Kanban view visible without horizontal scrolling; dual-panel drawer views enabled.
  - `Desktop Standard (1200px - 1439px)`: 5-column Kanban adopts minimal 280px column constraints with controlled horizontal scroll; standard table layouts expand fully.
  - `Tablet / Laptop Small (< 1200px)`: Sidebar collapses automatically to 68px; data tables engage horizontal overflow panning with sticky primary ID columns.

## Elevation & Depth

Visual hierarchy uses clean surface tiering paired with low-contrast micro-outlines and ambient shadows. This prevents UI noise while maintaining clear depth separation between structural panels and interactive floating elements.

### Elevation Levels
- **Level 0 (Base Surface):** `#F7F8FA` — Canvas background. No shadow.
- **Level 1 (Card & Content Blocks):** `#FFFFFF` — Default for tables, cards, and KPI panels. Border: `1px solid #D6DCE5`. Shadow: `0 1px 3px rgba(26, 32, 44, 0.04), 0 1px 2px rgba(26, 32, 44, 0.02)`.
- **Level 2 (Hovered Cards & Dropdown Menus):** Active Kanban card drags, hover elevation, and custom popovers. Border: `1px solid #D6DCE5`. Shadow: `0 4px 12px rgba(26, 32, 44, 0.08), 0 2px 4px rgba(26, 32, 44, 0.04)`.
- **Level 3 (Modals, Overlays & Drawers):** SLA notification popups, bulk import modal, and lead audit slide-out panels. Border: `1px solid rgba(214, 220, 229, 0.6)`. Shadow: `0 12px 32px rgba(17, 26, 43, 0.16), 0 4px 8px rgba(17, 26, 43, 0.06)`.
- **Modal Backdrop:** `rgba(17, 26, 43, 0.6)` paired with `backdrop-filter: blur(4px)`.

## Shapes

The design system standardizes on clean, approachable corners that communicate modern software while maintaining data compactness.

### Corner Radii Scale
- **Small (`sm: 4px`):** Used for micro-badges, SLA duration tags, inner status indicators, table check inputs, and code inline blocks.
- **Medium (`md: 8px`):** Default for standard form inputs, action buttons, table cell tooltips, and tab selectors.
- **Large (`lg: 12px`):** Default for Kanban cards, KPI cards, table container wrappers, and dropdown surface sheets.
- **Extra Large (`xl: 16px`):** Reserved for dialog modals, drawer sheets, and large alert callouts.
- **Pill (`9999px`):** Strictly used for round user avatars and circular quick-action floating badges.

## Components

### 1. Primary Orange Button
- **Role:** Sole primary action per screen context.
- **Style:** Background `#F5691A`, text `#FFFFFF`, border-radius `8px`, height `40px` (md) or `32px` (sm), padding `0 16px`. Font: `label-md`.
- **States:** Hover `#C94F0F`, Active `#C94F0F` with scale `0.98`, Disabled `#D6DCE5` text `#8A93A3` with `cursor: not-allowed`.
- **Prohibition:** Do not use orange for secondary buttons, outline buttons, or background fills of badges.

### 2. Secondary & Ghost Buttons
- **Secondary:** Background `#EEF1F5`, text `#1A202C`, hover background `#D6DCE5`.
- **Primary Ghost / Subtle:** Background transparent, text `#3B5BFB`, hover background `#EEF1F5`.

### 3. Sidebar Navigation (Navy Shell)
- **Container:** Background `#111A2B`, text `#8A93A3`, width `260px`.
- **Brand Header:** ME Education logo in white paired with an orange visual dot accent.
- **Nav Item:** Height `40px`, border-radius `8px`, margin `4px 12px`. Hover: background `rgba(255, 255, 255, 0.06)`, text `#FFFFFF`.
- **Active State:** Background `rgba(59, 91, 251, 0.15)`, text `#FFFFFF`, left accent bar `3px solid #3B5BFB` or active icon fill in `#3B5BFB`.

### 4. Top Filter Bar
- **Container:** Sticky top `0`, height `64px`, background `#FFFFFF`, border-bottom `1px solid #D6DCE5`, padding `0 24px`.
- **Elements:** Quick keyword search (JetBrains Mono for phone/code search), filter pills dropdown (Chi nhánh, Nguồn lead, Trạng thái), date range picker, and the single primary action button anchored on the far right.

### 5. Kanban Cards & Column Headers
- **Column Header:** Height `48px`, background transparent, border-top `3px solid [Stage Color]`, title in `label-md` with lead count counter pill (background `#EEF1F5`, text `#4A5568`).
- **Cards:** Background `#FFFFFF`, border `1px solid #D6DCE5`, radius `12px`, padding `16px`. Hover lifts with Level 2 elevation.
- **Card Content Hierarchy:**
  1. Top: Student Code (`code-sm`, `#4A5568`) + Time in Stage SLA badge.
  2. Middle: Student Full Name (`headline-sm`, `#1A202C`), Target Course.
  3. Bottom: Assigned Counselor avatar, Test score tag (if available), quick call/schedule actions.

### 6. Status Badges & SLA Badges
- **Status Badges:** Text `11px`, weight `600`, radius `4px`, padding `2px 8px`. Uses 10% opacity tint of semantic/stage color for background and 100% token for text.
  - Success / Da Chot: BG `#E9F6EE`, Text `#22A559`.
  - Warning / Can Xu Ly: BG `#FEF6E9`, Text `#F5A623`.
  - Error / Qua Han SLA: BG `#FCEDED`, Text `#E23D3D`.
  - Info / Hen Test: BG `#EBF0FF`, Text `#3B5BFB`.
- **SLA Countdown Badges:** Includes a clock icon. Turns `#E23D3D` with soft pulse when SLA breach is within < 15 minutes.

### 7. Data Tables
- **Header:** Height `44px`, background `#F7F8FA`, border-bottom `1px solid #D6DCE5`, text `#4A5568` in `label-sm`, uppercase.
- **Row:** Height `52px`, background `#FFFFFF`, hover `#F7F8FA`, border-bottom `1px solid #EEF1F5`. Checkbox left-aligned, sticky column support for student name.
- **Metrics/Codes:** Rendered in `JetBrains Mono`.

### 8. KPI Progress Cards
- **Structure:** Radius `12px`, border `1px solid #D6DCE5`, background `#FFFFFF`, padding `20px`.
- **Metrics:** Stat number (`display`, `32px`, bold), delta badge (green `#4CAF1E` for positive growth, red `#E23D3D` for drop), progress bar track `#EEF1F5` with progress fill `#3B5BFB` or `#4CAF1E`.

### 9. Audit Timeline Panel (Drawer)
- **Container:** Slide-out right panel, width `420px`, background `#FFFFFF`, shadow Level 3.
- **Nodes:** Vertical rule `#D6DCE5`, event node dot `8px` colored by action type (call, note, status shift, payment), timestamp in `code-sm`, author name and action note in `body-sm`.

### 10. Bulk Import Modal
- **Container:** Centered modal, width `640px`, radius `16px`, background `#FFFFFF`.
- **Upload Zone:** Dashed border `2px dashed #D6DCE5`, radius `12px`, background `#F7F8FA`, hover border `#3B5BFB`. Includes template download link (`#3B5BFB`) and CSV/XLSX error preview table showing column mismatches in real time.