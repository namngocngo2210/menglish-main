---
name: MENGLISH Admin
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
  h1:
    fontFamily: Be Vietnam Pro
    fontSize: 28px
    fontWeight: '700'
    lineHeight: 36px
    letterSpacing: -0.02em
  h2:
    fontFamily: Be Vietnam Pro
    fontSize: 22px
    fontWeight: '600'
    lineHeight: 30px
    letterSpacing: -0.01em
  h3:
    fontFamily: Be Vietnam Pro
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 26px
  body-base:
    fontFamily: Be Vietnam Pro
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-medium:
    fontFamily: Be Vietnam Pro
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
  body-small:
    fontFamily: Be Vietnam Pro
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label:
    fontFamily: Be Vietnam Pro
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  caption:
    fontFamily: Be Vietnam Pro
    fontSize: 11px
    fontWeight: '400'
    lineHeight: 16px
  code:
    fontFamily: JetBrains Mono
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 8px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  sidebar-width: 260px
  header-height: 64px
  gutter: 16px
---

## Brand & Style

The design system is engineered for efficiency, clarity, and operational speed. It serves an internal demographic of admins and accountants who manage high volumes of data. The personality is **friendly yet authoritative**, blending the warmth of an educational brand with the precision of a financial tool.

The visual style follows a **Corporate / Modern** aesthetic with high-density layouts. It prioritizes information hierarchy through a clear "white-label" workspace feel, where the brand’s vibrant orange is used surgically for high-intent actions, ensuring the user's focus is never diluted. 

Key principles:
- **Operational Clarity:** High contrast between backgrounds and data containers.
- **Academic Reliability:** Trustworthy typography and structured data tables.
- **Dynamic Response:** Interactive states that feel snappy and immediate.

## Colors

The color palette is strictly functional. 

- **Primary Orange:** Reserved exclusively for global CTAs (e.g., "+ TẠO MỚI", "XÁC NHẬN PHIẾU THU"). Do not use this for status indicators or decorative elements.
- **Sidebar Navy:** Provides a structural anchor for the application, creating a clear mental model of "Navigation" vs. "Workspace."
- **Kanban Stages:** These colors are used for column headers and card indicators to provide instant visual categorization of the sales funnel.
- **Semantic Colors:** Used for feedback and status badges (e.g., "Đã thanh toán" uses Success Green).

## Typography

This design system uses **Be Vietnam Pro** for all interface elements to ensure excellent legibility for the Vietnamese language, specifically focusing on diacritic clarity.

- **Scale:** The 14px body size is the workhorse of the system, optimized for dense data tables and forms.
- **Labels:** Use the 11px uppercase label style for section headers in the sidebar or table headers.
- **Numbers:** For financial reporting and IDs, use the **JetBrains Mono** font to ensure tabular alignment and prevent digit confusion.
- **Language:** All system labels must be in Vietnamese (e.g., "Tìm kiếm khách hàng", "Tổng doanh thu").

## Layout & Spacing

The layout utilizes a **Fixed Sidebar / Fluid Content** model.

- **Grid:** A standard 12-column grid is used for dashboard layouts, but data-heavy views (like the Lead List) should use a fluid container with 24px margins to maximize screen real estate.
- **Rhythm:** An 8px base unit (8, 16, 24, 32) governs all padding and margins.
- **Table Density:** Use "Compact" (8px vertical padding) for large datasets and "Comfortable" (16px vertical padding) for simple summary lists.
- **Breakpoints:**
  - Desktop: 1200px+ (Default admin view)
  - Tablet: 768px - 1199px (Sidebar collapses to icons)
  - Mobile: <768px (Sidebar hidden, drawer-based navigation)

## Elevation & Depth

To maintain academic reliability and speed, the design system avoids heavy shadows and decorative depth.

- **Tonal Layers:** The background uses `Gray 50`. Primary workspace containers (Cards, Tables) use `White` with a 1px border of `Gray 100`.
- **Elevation levels:**
  - **Level 0 (Flat):** Background and inactive areas.
  - **Level 1 (Raised):** Cards and main data containers. No shadow, 1px Gray 100 border.
  - **Level 2 (Hover):** Cards on hover. Subtle shadow: `0px 4px 12px rgba(0, 0, 0, 0.05)`.
  - **Level 3 (Overlay):** Modals and Dropdowns. Sharp shadow: `0px 8px 24px rgba(0, 0, 0, 0.12)`.

## Shapes

The shape language is modern and approachable.

- **SM (4px):** Checkboxes, radio buttons, and small tags.
- **MD (8px):** Standard buttons, input fields, and dropdown menus.
- **LG (12px):** Secondary cards, modal windows, and Kanban cards.
- **XL (16px):** Large dashboard widgets and profile containers.

Interactive elements should maintain a consistent corner radius to feel part of a cohesive ecosystem.

## Components

### Buttons
- **Primary:** Background `#F5691A`, Text `White`. Only for the main action of the page.
- **Secondary:** Background `White`, Border `Gray 300`, Text `Gray-900`. For neutral actions like "Hủy" or "Quay lại".
- **Ghost:** No background or border. Used for table row actions (e.g., "Chi tiết").

### Status Badges
- Used for Kanban stages and status indicators. 
- Format: Soft background (10-15% opacity of the color) with bold text of the color.
- *Example:* **Lead Mới** tag has background `#F43F7E` at 10% opacity, text `#F43F7E`.

### Data Tables
- Header: `Gray 50` background, `Gray-500` uppercase text.
- Row: `White` background, 1px bottom border `Gray-100`.
- Hover: Row background changes to `Gray-50`.

### Input Fields
- Default: `Gray-100` border, `White` background.
- Focus: `Secondary-Default (#3B5BFB)` 1px border with a 2px soft glow.
- Labels: Placed above the field in `Gray-700`, 13px size.

### Kanban Cards
- Margin: 12px between cards.
- Content: Lead name (H3), Phone number (Mono Body), and a "Last Contact" timestamp in Caption style.
- Indicator: 4px vertical color bar on the left edge indicating the current stage.