---
name: ME Education Admin System
colors:
  surface: '#f9f9ff'
  surface-dim: '#d1daf2'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f1f3ff'
  surface-container: '#e8edff'
  surface-container-high: '#e0e8ff'
  surface-container-highest: '#dae2fa'
  on-surface: '#131c2d'
  on-surface-variant: '#594137'
  inverse-surface: '#283042'
  inverse-on-surface: '#edf0ff'
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
  on-background: '#131c2d'
  surface-variant: '#dae2fa'
typography:
  h2-desktop:
    fontFamily: Be Vietnam Pro
    fontSize: 22px
    fontWeight: '700'
    lineHeight: 32px
  h3-card:
    fontFamily: Be Vietnam Pro
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 26px
  body-main:
    fontFamily: Be Vietnam Pro
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-semibold:
    fontFamily: Be Vietnam Pro
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
  label-caps:
    fontFamily: Be Vietnam Pro
    fontSize: 12px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.05em
  caption-badge:
    fontFamily: Be Vietnam Pro
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 14px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 8px
  sm: 12px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 20px
  sidebar_width: 260px
---

## Brand & Style
The design system for this English language center management CRM is built on the pillars of **operational speed** and **academic reliability**. The brand personality is friendly yet professional, bridging the gap between internal administrative efficiency and student-facing transparency.

The visual style follows a **Modern Corporate** direction, optimized for high-density data management. It utilizes a clear spatial hierarchy where a dark, focused navigation environment (Sidebar) contains a bright, airy, and high-contrast workspace (Content Area). This distinction reduces cognitive load for staff members who spend extended periods within the platform.

Key characteristics include:
- **High Information Density:** Maximizing screen real estate for CRM workflows without sacrificing legibility.
- **Visual Scannability:** Leveraging color-coded stages and SLA badges to allow users to prioritize tasks at a glance.
- **Tactile Clarity:** Using soft shadows and 8px rounded corners to make the professional tool feel approachable and modern.

## Colors
The palette is strategically weighted to balance brand identity with functional utility. 

- **Primary Orange (#F5691A):** Reserved exclusively for high-impact actions and primary Call-to-Actions (CTAs). It signals the most important step in any given workflow.
- **Secondary Blue (#3B5BFB):** Used for navigation cues, secondary buttons, and informational links. It provides a professional anchor to the interface.
- **Sidebar Navy (#111A2B):** A deep, neutral background for the main navigation to minimize distraction from the main content area.
- **Semantic Stage Colors:** A distinct spectrum used specifically for the Kanban board and lead status badges to facilitate instant recognition of a student's journey.
- **SLA Scale:** A traffic-light system (Green/Yellow/Red) is used for countdowns to indicate time-sensitive administrative tasks.

## Typography
**Be Vietnam Pro** is the sole typeface for this design system, chosen for its exceptional support of Vietnamese diacritics and its clean, contemporary geometric grotesque aesthetic.

The hierarchy is optimized for **data density**:
- **Headlines:** Use H2 for page titles and H3 for card headers to establish clear sectioning.
- **Body Text:** The 14px base ensures that even in complex tables, text remains legible and professional.
- **Numerical Data:** For prices, countdowns, and KPI metrics, use the Semibold weight to ensure these figures pop against background elements.
- **Badges:** Caption styles are used for pill-shaped status indicators to maintain a compact footprint within tables and cards.

## Layout & Spacing
The layout follows a **Fluid Grid** model within the content area, designed primarily for 1920x1080 resolution workflows.

- **The Sidebar:** Fixed at 260px width. It uses a dark theme to recede visually, focusing the user's attention on the white workspace.
- **The Workspace:** Uses a 12-column grid with 20px gutters. Padding within cards and tables is kept tight (12px to 16px) to maximize the "above-the-fold" information.
- **Responsive Behavior:** 
    - **Desktop (1440px+):** Full visibility of all CRM columns and Kanban stages.
    - **Tablet (768px - 1439px):** Sidebar collapses into an icon-only rail; tables implement horizontal scrolling for secondary data points.
    - **Mobile:** Not the primary target for the CRM, but uses a single-column reflow for lead cards.

## Elevation & Depth
Depth is used sparingly to maintain the clean, "flat-plus" administrative look.

- **Surface Layers:** The main background uses Gray 50 (#F7F8FA), while interactive cards and data containers use pure White (#FFFFFF) to create a subtle lift.
- **Shadows:** Use a single, soft ambient shadow for cards: `0px 4px 12px rgba(17, 26, 43, 0.05)`. This provides enough definition to separate containers without creating visual clutter.
- **Active States:** Elements being dragged (e.g., in the Kanban board) receive a more pronounced shadow to indicate a higher Z-index.
- **Borders:** Use a 1px solid border (#EDF2F7) for table rows and input fields instead of shadows to keep the interface feeling crisp and structured.

## Shapes
This design system utilizes a **Rounded** shape language to maintain a friendly, approachable atmosphere within a high-utility tool.

- **Base Radius:** 8px (0.5rem) is the standard for cards, buttons, and input fields.
- **Small Radius:** 4px (0.25rem) for checkboxes and small tooltip containers.
- **Pill Shape:** Used exclusively for Status Badges and SLA Countdowns to distinguish them from interactive buttons.
- **Active Indicators:** Vertical 4px bars on the left edge of navigation items or table rows indicate the "active" or "selected" state.

## Components

### Buttons
- **Primary:** Orange (#F5691A) with white text. 8px radius. Height: 40px for main actions, 32px for in-table actions.
- **Secondary:** Blue (#3B5BFB) with white text or Blue outline.
- **Ghost:** Gray text with no background, used for "Cancel" or "Back" actions.

### Data Tables
- **Styling:** Zebra-striping using Gray 50 (#F7F8FA). 
- **Headers:** Navy (#111A2B) text, 12px Bold, uppercase with 1px bottom border.
- **Density:** 12px vertical padding for rows to allow for high row counts.

### Kanban Cards
- **Structure:** 8px rounded corners, white background, soft shadow.
- **Header:** Contains the student name (H3 size) and a Stage Badge.
- **Footer:** SLA Countdown pill (e.g., "2h 15m remaining") aligned to the right.

### Input Fields
- **Default:** 1px border (#E2E8F0), 8px radius, 14px text.
- **Focus:** 2px border using Secondary Blue (#3B5BFB) with a soft blue outer glow.

### Status Badges (Pills)
- Text is always 11px Bold. Backgrounds use a 15% opacity version of the stage color with 100% opacity text for high legibility (e.g., Rose text on light pink background).

### SLA Badges
- **Green:** Tasks within time.
- **Yellow:** Approaching deadline (within 24h).
- **Red:** Overdue. Always includes an icon (clock or alert) for accessibility.