# MEnglish — UX audit (Laravel/Inertia app) & redesign shortlist

> 31/10/2026 · dev agent · skill `ui-ux-pro-max` · **Step 1 of the owner request: audit + design images only. No application code changed.**

## Method
- Local build of `origin/main` (8c0fa0a7c) in Docker with the demo seeders (phases 1–3; the DemoPhase4 seeder aborts on a validation error — pre-existing, not touched). Throw-away SQLite DB, throw-away passwords; staging/production were NOT used and no data was written there.
- 39 screens × 2 viewports (1440, 390) rendered with Chromium as 5 roles (Admin, Giáo viên, Học viên, Trợ giảng …). Before-screenshots of the 6 shortlisted screens: `docs/ux-audit/before/*.png` (the other 33 pairs were rendered but not kept, to keep the PR small); raw automated metrics: `docs/ux-audit/metrics.json` (horizontal overflow, tables, text < 12px, icon-only buttons without label, unlabeled inputs, page height).
- Each screen was judged on the ui-ux-pro-max criteria: hierarchy, density, navigation consistency, forms, tables, mobile, accessibility/contrast, empty/error states.

## Overall verdict
The shell (sidebar → icon rail → drawer, sticky topbar), token system and badges are **consistent and good**; no page overflows horizontally at 390px and almost every input has a label. The debt is at **page level**: long pages where everything has equal weight, 3-line table cells, filters that take a whole mobile screen, floating bars overlapping content, and flows (receipt, timesheet, approvals) that are linear lists instead of task-shaped screens.

Cross-cutting findings
| # | Finding | Severity |
|---|---|:-:|
| X1 | Floating elements overlap content on mobile / long forms: sticky action bar over the receipt form, "ORDER" FAB over the teacher bottom bar | High |
| X2 | Equal visual weight: pages 3,000–5,500px tall at 390px (timesheet 5,531, dashboard 3,560, teacher home 3,683, notifications 5,308) with no grouping / collapse | High |
| X3 | Tables: 3-line cells (ca học, SLA, trạng thái), truncated text (`Facebook A…`, filter selects `Tất cả chi n…`) and icon-only row actions | Med |
| X4 | Filter rows: 5–6 selects always visible; on mobile they stack into a full screen before any data | Med |
| X5 | Small text: teacher home 8 and TA tasks 3 elements below 12px | Low |
| X6 | Unlabeled inputs: tuition history/overdue/finance expenses (1 each) | Low |
| X7 | Role landing for `reports/all`, `reports/daily` redirect or render nearly empty with no `<h1>` (empty-state guidance missing) | Low |

## Screen table (admin unless noted)
Severity = impact of the problems; Priority = severity × how often the role uses it. **★ = picked for redesign.**

| Screen | Route | Key problems | Sev | Pri |
|---|---|---|:-:|:-:|
| ★ Tổng quan | `/dashboard` | 5 identical "Chăm sóc tháng đầu" rows bury the rest of "Cần bạn xử lý"; unlabeled red numbers in alerts; below-fold charts; 3,560px on mobile | High | 1 |
| ★ Lịch dạy hôm nay (GV) | `/teacher/home` | 5 equal step cards per session, locked ones as dashed boxes; FAB overlaps bottom bar; 8 text nodes < 12px; 3,683px mobile | High | 2 |
| ★ Lập phiếu thu học phí | `/tuition/receipts/create` | 6 stacked cards (2,744px / 4,466px), summary in the middle, action bar floats over form, truncated selects | High | 3 |
| ★ Danh sách khách hàng | `/crm/customers` | 7 chips + 5 filters + bulk bar before data; truncated Nguồn; 2-line SLA; pencil-only actions | Med-High | 4 |
| ★ Chi tiết chấm công GV | `/payroll/timesheets/teachers` | 7 tabs, 6 truncated filters, 65 rows × 3-line cells, 5,531px mobile | High | 5 |
| ★ Việc cần duyệt | `/approvals` | Flat grouped lists, no amount/requester/age scan, no bulk path, no preview | Med-High | 6 |
| Tạo lớp mới | `/classes/create` | Good sectioning, but 2/3-width form leaves right column of prose; Phòng học select disabled with no inline path | Med | 7 |
| Lương – kỳ lương | `/payroll/periods` | Fine on desktop; table → horizontal scroll feel on mobile | Med | 8 |
| Quá hạn & Nhắc phí | `/tuition/overdue` | Dense group cards, 4,723px mobile; sound hierarchy | Med | 9 |
| Danh sách lớp | `/classes` | Dense but readable; filters row heavy on mobile | Low-Med | 10 |
| Học viên | `/students` | Clean list/cards; mobile OK | Low | – |
| Kanban tuyển sinh | `/crm/pipeline` | Horizontal columns OK on desktop; on mobile 4,678px single column of tiny cards | Med | 11 |
| Cổng học viên | `/portal/student/home` | Good, 430px layout; bottom nav overlaps lower content | Low-Med | 12 |
| Chấm công của tôi | `/cham-cong` | Clear single task; orange hero block high contrast | Low | – |
| QA Observation | `/class-quality/operations` | Empty state exists but no CTA hint on mobile | Low | – |
| Tickets / Tasks / Users / Roles / Rooms / Penalties / KPI / Question bank / Practice results | various | Standard list pages, consistent `Ui*` table + filter pattern; same X3/X4 issues | Low-Med | – |

Detailed metrics for all 39 screens (390px/1440px): see `docs/ux-audit/metrics.json`.

## Redesign shortlist (6) → `docs/ux-redesign/`
1. Dashboard · 2. Teacher "Lịch dạy hôm nay" · 3. Lập phiếu thu · 4. CRM customer list · 5. Chi tiết chấm công GV · 6. Việc cần duyệt.
Mockups: `docs/ux-redesign/screens/*.html` (static, sample data, real tokens), PNG previews + before/after notes: `docs/ux-redesign/README.md`. Design brief: `docs/ux-redesign/BRIEF.md`.

## Next step (needs owner approval)
Owner picks direction → implementation PRs per screen (Vue), each with tests, after approval of these images.
