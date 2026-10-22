# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

MEnglish: management system for an English-language center (CRM → placement test → trial lesson → enrollment →
classes / syllabus / attendance → tuition / invoices → timesheets / penalties / KPI / payroll). Two codebases live here:

- **Repo root — Laravel 13 (PHP 8.4) + Inertia 3 + Vue 3 + Tailwind 3.** This is production
  (`portal.meducation.vn`, staging `dungthu.meducation.vn`) and the source of truth for business rules.
- **`v2/` — rewrite in Go API + React 19 + PostgreSQL** (beta at `beta.meducation.vn`). Read `v2/README.md` and
  `docs/thiet-ke-he-thong-moi.md` before touching it.

UI strings, code comments, and docs in the repo are Vietnamese. `README.md` documents demo data and demo accounts per
phase; `docs/` holds design docs (`rbac.md`, `deploy-docker.md`, `audit-report-and-roadmap.md`, …).

## Commands (Laravel)

PHP is not installed on the deploy server host; there, run artisan through Docker (`docker/menglish.sh <production|staging> artisan …`).

```bash
composer dev                                   # serve + queue:listen + pail + vite
npm run build                                  # Vite build (resources/js/app.js, resources/css/app.css)
php artisan test                               # full suite (what CI runs before deploy)
CACHE_STORE=database php artisan test tests/Feature/HolidayModalTest.php   # one file
php artisan test --filter=test_method_or_class # one test / class
php artisan test --filter='Phase[1-4]AcceptanceTest|DemoPhase[1-4]SeederTest|FullBpmnSmokeTest'  # end-to-end acceptance
vendor/bin/pint                                # PHP formatter (Laravel preset, not enforced in CI)
php artisan ziggy:generate resources/js/ziggy.js   # REQUIRED after changing routes/web.php
php artisan migrate:fresh --seed               # system + Phase 1–4 demo data (demo only when APP_ENV is local/testing/staging or SEED_DEMO=true)
```

Test infrastructure (see `tests/TestCase.php`):
- SQLite file DB `database/testing.sqlite` (auto-created). Roles/permissions are seeded **once** via
  `tests/TestBaseSeeder.php`; `seed(PermissionSeeder/RoleSeeder)` inside a test is a no-op — use `runSeederForReal()`
  when testing the seeders themselves.
- Inertia pages render to real HTML through a Node SSR server (`tests/Support/InertiaSsrServer`), so `assertSee` works on
  Vue output. Node is required; the SSR bundle `bootstrap/ssr/ssr.js` is rebuilt automatically when `resources/js` is newer.
- Props: `assertInertia(...)`; modal requests: `Tests\Concerns\InteractsWithInertia` (`self::MODAL` header).
- Guard-rail tests that commonly fail on new code: `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`,
  `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`.

## Commands (v2)

```bash
cd v2/api && go test -p 1 ./...     # needs TEST_DATABASE_URL (Postgres) and TEST_REDIS_URL; -p 1 because packages share one DB
cd v2/api && gofmt -l . && go vet ./... && sqlc generate   # sqlc after editing migrations / *queries.sql (CI runs `sqlc diff`)
cd v2/api && php internal/iam/catalog/export.php > internal/iam/catalog/catalog.json   # after changing Laravel permission config
cd v2/web && npm test && npm run build
v2/tools/parity.sh [origin/main]    # which v2 areas track Laravel files changed since last sync
```

## Laravel ↔ v2 coupling

- `v2/doi-chieu-laravel.txt` maps each v2 area to the Laravel files it mirrors and the Laravel commit it was last
  synced to. The `v2-parity` workflow warns (non-blocking) when a PR edits those files; the v2 port then needs updating
  and the commit in the manifest bumped.
- v2 CI runs Laravel migrations on MariaDB to test `import-legacy`, so changes under `database/migrations/` can break v2 CI.
- v2's permission catalog is exported from `config/permission_catalog.php` + `config/access.php`; CI fails if out of sync.

## Laravel architecture

**Frontend (Inertia).** Controllers return `Inertia::render('Module/Page', $props)` → `resources/js/Pages/Module/Page.vue`.
`resources/js/README.md` is the binding conventions doc — read it before any UI work. Key points: send only the fields
the page needs (props are visible in HTML), money as numbers and dates as `Y-m-d`/ISO (Vue formats them), `Ui*`
components in `Components/ui/` are globally registered, `route()/can()/formatMoney()` are available in templates.
Add/Edit/Detail screens use one route that serves both a modal and a full page via the `App\Http\Concerns\RendersModals`
trait (`modalPage`, `modalSaved`, `modalFailed`). Reference module: Holidays (`HolidayController`, `Pages/Holidays/*`,
`HolidayModalTest`). Blade remains only for print views / exports / emails.

**Code layout.** Controllers are large and per-module; reusable domain logic lives in `app/Services/<Domain>` and
cross-cutting helpers in `app/Support`. All web routes are in `routes/web.php`; scheduled jobs in `routes/console.php`
(all designed to be idempotent; many enforce SLA thresholds configured in-app and create tasks / penalties).

**RBAC (`docs/rbac.md`).** Spatie permissions with a fixed set of 9 roles (`App\Support\Roles`).
- `config/permission_catalog.php` is the single source of permissions (`module.action`, scope permissions
  `module.scope_own|scope_branch|scope_all`, object permissions like `portal.student`, `class.teach`, and dynamic
  `user.assign_role.<role>`). `config/access.php` holds default grants applied only when a role is first created;
  seeders are add-only and must never overwrite admin-edited config. New permissions usually need a migration that
  grants them to existing roles.
- Authorize with `can()` / `can:` middleware / Gate. Never compare role names as strings — use `Roles::*` constants;
  `User::isSuperAdmin()` call sites are allowlisted in `RbacNoHardcodedRolesTest`.
- `AppServiceProvider` registers the per-user override `Gate::before` ahead of Spatie's so overrides can **deny** a
  role-granted permission; Super Admin passes all action permissions but not object permissions.

**Automatic data scoping.** Models using `App\Models\Concerns\ScopedToViewer` get the `VisibleToViewer` global scope
(rules in each model's `constrainToViewer()`, levels from `App\Support\DataScope`). `EnforceDataScope` is prepended to
the `web` group, so every query in a web request — including route-model binding (out-of-scope id → 404) — is filtered.
Opt out deliberately with `->withoutViewerScope()` or `DataScopeGuard::bypass(fn () => …)`. Queued jobs run unscoped.
Tuition/finance also uses `App\Support\TuitionBranchScope`.

**Approvals inbox.** Every approvable flow is an `ApprovableSource` adapter living next to its module
(`app/Services/*/Approvals`) and registered in `ApprovalServiceProvider::SOURCES`.

**Other cross-cutting pieces.** Audit log via spatie/activitylog + `AuditsChanges` + `AuditOperationMiddleware`
(`App\Support\SensitiveData` masks fields); sidebar/menu from `App\Support\Navigation\SidebarMenu` + `AppShell`;
notifications via `NotificationService`, parent messages via `ParentMessenger` / `ZaloZnsService`; SePay bank
webhook (`SepayWebhookController`, HMAC, CSRF-exempt); uploads through `SafeUploadService`, stored locally or on
Cloudflare R2 (`UPLOAD_STORAGE=r2`, see `config/filesystems.php`, `docs/chuyen-upload-sang-r2.md`).

**Demo seeders.** `DemoPhase1..4Seeder` drive real controllers with the clock set to past dates, are idempotent, and
are covered by `DemoPhase*SeederTest`. Demo records are recognizable by names prefixed `# ` and phone prefixes
`039`, `0377`, `0388`. If you change a flow they exercise, update the seeder.

## Deployment

Docker on one server behind Caddy (`docs/deploy-docker.md`): `docker-compose.yml` is shared by the `production` and
`staging` projects, each with its own DB and scheduler container. Operate via `docker/menglish.sh <env> deploy [ref] |
migrate | artisan … | backup-db | logs -f app`. Deploys are manual only (GitHub Actions "Deploy server"; production
only from `main`); pushing/merging to `main` does not deploy. `.env.production` / `.env.staging` on the server are live
secrets — do not edit or print them.
