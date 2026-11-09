# Smart ERP Architecture: Separate Backend and Frontend

Decided 2026-11-09. The backend and frontend are **completely separate applications** that only talk over
an HTTP API. They live in one repository for now, laid out so each half can move to **its own repository**
later without code changes.

## 1. Where we are

| | Today | Target |
|---|---|---|
| UI | 184 Blade views (~13k lines), Bootstrap 5 + Alpine | SPA in `frontend/` (Angular + TypeScript + Bootstrap 5 CSS) |
| Controllers | 62, of which 61 `return view()` | API controllers returning JSON only |
| API | none (`routes/web.php` only), no Sanctum | `/api/v1/...`, Sanctum auth |
| Validation / output | inline in controllers | FormRequests in, API Resources out |
| Modules | `nwidart/laravel-modules` installed, unused | one module per business area |

smart-erp is not in production yet, so the switch costs least now. **Rule from today: no new Blade UI.**
Every gap-list item is built API-first.

## 2. Repository layout (one repository now, ready to split)

```
smart-erp/
├── backend/            Laravel 13 API: own composer.json, .env, tests, CI job
├── frontend/           Angular SPA: own package.json, environments, tests, CI job
├── api-contract/       openapi.yaml, generated from backend, input for frontend types
└── docs/               architecture.md, hrms-gap-list.md, hrms-migration-mapping.md
```

**Split-readiness rules:**
- `frontend/` never imports anything from `backend/` and the other way round. The only link is `api-contract/openapi.yaml`.
- Each folder builds, tests and deploys on its own (separate CI jobs, separate `.env`).
- Splitting later = `git subtree split -P backend` / `-P frontend` into two repositories. The contract file is then published as a release artifact (or an npm package of generated types).

## 3. The contract between them

1. The backend generates **OpenAPI** from its routes, FormRequests and Resources (e.g. `dedoc/scramble`), with no hand-written docs.
2. The frontend generates **TypeScript types and an API client** from that file (OpenAPI generator, `typescript-angular`; the generated code uses only Angular's HttpClient).
   If the backend changes a field, the frontend build fails until it's updated, before it ever reaches users.
3. **Versioned URLs:** `/api/v1/...`. Breaking changes go to `v2` while `v1` keeps working, which matters once a mobile app exists.
4. **One response format for everything:**
   - list: `{ data: [...], meta: { page, per_page, total }, links }`
   - item: `{ data: {...} }`
   - validation error (422): `{ message, errors: { field: [..] } }`
   - other errors: `{ message, code }` with the correct HTTP status (401, 403, 404, 409, 500)
5. **One query style for every list:** `?filter[status]=active&filter[department_id]=3&sort=-start_date&page=2&per_page=25&include=employee`
   (`spatie/laravel-query-builder`), so one frontend table component works with every list endpoint.

## 4. Backend (Laravel API)

### Module layout (`backend/Modules/<Module>/`)
```
Modules/Leave/
├── Http/Controllers/Api/   thin: authorize → validate (FormRequest) → call Action → return Resource
├── Http/Requests/          all validation rules
├── Http/Resources/         all JSON output (never return models directly)
├── Actions/                one business operation per class: ApplyLeave, ApproveLeave, RecalculateBalance
├── Models/  Policies/  Events/  Listeners/  Jobs/
├── Database/Migrations/  Database/Seeders/
├── routes/api.php
└── Tests/Feature/          one test file per endpoint
```
Modules: **Core** (auth, companies, users, roles/permissions, settings, audit, workflow, attachments,
numbering, notifications, integrations), **Organization**, **Employee**, **Contract**, **Time** (roster, work entries,
attendance), **Leave**, **Allowance**, **Overtime**, **Payroll**, **SelfService**, **Reports**, plus the finance and
operations modules. The full catalogue and the rules between modules are in **section 8**.

### Code reuse in the backend
| Reused piece | Used by |
|---|---|
| **Actions** (business logic lives only here) | API controllers, scheduled commands, queue jobs, the HRMS import. One `GeneratePayroll` serves the button and the scheduler |
| `BelongsToCompany` trait + global scope | every company-owned model (no manual `where company_id` anywhere) |
| `HasWorkflow` trait + Core workflow engine | leave, overtime, allowances, salary adjustment, contract renewal, payroll sheet, attendance claims |
| `Auditable`, `HasAttachments`, `HasExternalIds` traits | any model that needs them |
| `NumberingService` | employee numbers (ST/PT/VOL/INT/RES), contract and document numbers, configurable per company |
| Base `ApiController` + `ApiResponse` helpers | consistent responses and error handling |
| Generic **reference-data** endpoints | banks, leave types, allowance categories, contract types, roster codes, holidays… One configurable controller instead of ~30 copies |
| Activity/attendance **source adapters** | integrations (see gap list) |

### Backend rules
- Returns **JSON only**. Exceptions: file downloads (PDF payslips/contracts, Excel/CSV exports, bank files),
  generated by the backend and streamed through the API. Emails use Blade/Markdown mail templates; those are not UI.
- **All business rules and authorization live in the backend.** The frontend can hide a button, but the
  API always re-checks permission (Policies) and re-validates (FormRequests).
- No module reads another module's tables directly. It calls that module's Actions/services or listens to its events.

### Auth
- **Sanctum SPA (cookie) auth** for the web app on the same parent domain (`erp.example` / `api.erp.example`), with CSRF, no tokens stored in the browser.
- **Sanctum tokens** for a future mobile app or external clients.
- `GET /api/v1/me` returns the user, employee, company, enabled modules and **permission list**. The frontend uses it to build menus and hide actions.
- The existing single-device login and OTP password reset become API endpoints.

## 5. Frontend (Angular SPA)

**Chosen for the fewest third-party dependencies** (decided 2026-11-09). Angular ships routing, forms with
validation, HTTP client with interceptors, guards, i18n, animations, testing and the CLI as one first-party
framework, released and upgraded together (`ng update`). Vue would need extra packages for form validation
and server-data caching.

**Stack (runtime):**
| Need | Choice | Third-party? |
|---|---|---|
| Framework, routing, guards, HTTP, interceptors | Angular (current LTS), standalone components, signals | No |
| Forms and validation | Angular Reactive Forms (typed) + custom validators | No |
| App state and server data | signals + services (`resource()` / small cache in services) | No |
| Tables, overlays, dialogs, drag-drop, virtual scroll, a11y | Angular CDK | No (Angular team) |
| Styling and icons | **Bootstrap 5 CSS only** (no Bootstrap JS, no ng-bootstrap) + Bootstrap Icons | CSS/icons only |
| Charts (later, dashboards) | decide when needed; prefer one small library | Yes, one |

**Dev-time only (not shipped to users):** OpenAPI client generator, Playwright, ESLint/Prettier.

**Dependency rules:**
- A new runtime package needs a reason no Angular/CDK feature covers, and is added in `core/` behind our own wrapper,
  so it can be replaced in one place.
- No UI component suite (PrimeNG, Material, ng-bootstrap). Our shared library (below) is built on Angular CDK + Bootstrap CSS.
- Stay on Angular's supported versions; run `ng update` each major release.

### Layout (`frontend/src/app/`)
```
app/
├── core/            generated API client, auth, interceptors (CSRF, errors, company), guards, permissions (*appCan), layout shell, i18n
├── shared/          shared UI library (below). No module-specific code in here
├── modules/
│   ├── leave/       pages/, components/, leave.service.ts, leave.routes.ts (lazy-loaded)
│   ├── employee/
│   └── ...          mirrors the backend modules one-to-one
└── app.config.ts, app.routes.ts
```

### Shared component library (build once, use everywhere)
| Component | Replaces |
|---|---|
| `DataTable` (server paging/sort/filter using the standard query style, column config, row actions, export button) | every list page and the jQuery DataTables copies |
| `FormField`, `FormSelect`, `FormDate`, `FormFile`, `FormMoney` + a form helper service (shows backend 422 errors on Reactive Form controls automatically) | hand-written form markup in every page |
| `EntityPicker` (employee, department, position… with search) | per-page select boxes |
| `Modal`, `ConfirmDialog` (incl. password confirm), `Toast` | per-page modals, toastr |
| `PageHeader`, `Breadcrumbs`, `Card`, `Tabs`, `EmptyState`, `StatusBadge` | inconsistent layouts |
| `WorkflowPanel` (current step, approve/reject/return with comment and files, timeline) | separate approval UIs per module |
| `AttachmentList` / `FileUpload` | document handling per module |
| `CrudPage` (list + create/edit form driven by a small column/field config) | ~30 reference-data screens |
| `WorkEntryCalendar`, `PayslipView` | domain components reused by HR, HOD and self-service |

### Frontend rules
- No business rules beyond form UX (required fields, formats). Totals, balances and statuses always come from the API.
- Talks to the backend **only** through the generated client in `core/api`. No hand-written URLs in pages.
- Every page uses the shared components. A new module should mostly be configuration plus a few domain components.

## 6. Quality gates
- **Backend:** feature test per endpoint (status, JSON shape, authorization), and a check that the OpenAPI file is up to date in CI.
- **Frontend:** type check (fails on contract changes), component tests (Angular CLI test runner), a few end-to-end flows (Playwright): login, apply leave, approve leave, generate payroll.
- Both: lint + format in CI, separate pipelines per folder.

## 7. Moving the current smart-erp code
Not in production, so module by module, no big bang:
1. **Foundation:** create `backend/` (move the Laravel app) and `frontend/` (new Angular CLI app). Add Sanctum, `/api/v1/me`,
   response helpers, query builder, OpenAPI generation, and the frontend shell + shared components (DataTable, forms, modal, layout, auth).
2. **Core + reference data** through the generic CRUD endpoint and `CrudPage`.
3. For each existing module: move logic from the Blade controller into **Actions** → add API controller/Requests/Resources/tests →
   build the SPA pages from shared components → delete the Blade views and web routes of that module.
4. **New gap-list items** (workflow engine, work entries, waiting contracts…) are built API-first directly, following the
   build order in `hrms-gap-list.md`.
5. When the last Blade module is gone, `backend/` has no UI left. Splitting into two repositories is then just a subtree split.

## 8. Growing to a full ERP

The ERP will grow well beyond HR: accounting, budgeting, planning, assets, fleet, projects, loans,
billing and more. The structure stays a **modular monolith**: one backend, one database, one SPA, with strict
module boundaries. Any module can later be pulled out into its own service if there is a real reason, such as
a separate team, very different load, or a separate client. Until then, separate services would add cost
without benefit.

### Module catalogue and dependencies

| Layer | Modules | Depends on |
|---|---|---|
| **Core** | Auth, Companies & branches, Users & roles, Settings, Workflow, Audit, Attachments, Numbering, Notifications, Integrations, Reports engine | — |
| **Master data** | Organization (departments = cost centres), Employees, **Parties** (customers, suppliers, staff as one shared list), Currencies, Fiscal years & periods, Tax codes | Core |
| **Finance backbone** | **Accounting** (chart of accounts, journals, general ledger, bank & cash, period closing) | Core, Master data |
| **Finance planning** | **Budgeting** (budget per account × cost centre × project × period, versions, approvals), **Planning** (work plans / activities linked to budget lines) | Accounting |
| **Money in / out** | **Billing** (customer invoices, receipts, receivables, statements), **Bills** (supplier bills, payments, payables), **Loans** (as its own module: staff loans and advances, later any loan with schedules, interest and repayments) | Accounting, Parties |
| **Operations** | **Procurement** (request → order → receipt), **Inventory**, **Assets** (register, depreciation, maintenance, disposal), **Fleet** (vehicles, fuel, trips, maintenance), **Projects** (projects, tasks, timesheets, costs, project budget) | Accounting, Budgeting |
| **People** | HR, Time, Leave, Allowance, Overtime, **Payroll**, Appraisal, Self-service | Core, Master data, Accounting (postings) |

A module can only depend on modules in the **same or a lower layer**. Lower layers never call upward. They publish events instead.

### Rule 1: modules talk through public services and events, never each other's tables
- Each module exposes a small **public service interface**, e.g. `Loans\Contracts\LoanService::instalmentsDue($employee, $period)`.
  Payroll calls that to deduct repayments. It never queries `employee_loans`.
- Things that "just happened" are **events**: `PayrollApproved`, `InvoiceIssued`, `AssetAcquired`, `FuelLogged`,
  `PurchaseOrderApproved`. Other modules listen if they're enabled.
- Each table belongs to exactly one module (its migrations live there). Others only read it through that module's service or a reporting view.

### Rule 2: accounting is fed by one posting engine
Every module that moves money does it the same way:
1. The module raises a business event (`PayrollApproved`, `InvoiceIssued`, `DepreciationRun`…).
2. A **posting rule** (configured per company, not coded per module) maps it to accounts:
   e.g. gross salary → Salary expense (by department), PAYE → PAYE payable, net pay → Salaries payable.
3. The **Posting service** in Accounting creates a balanced journal entry linked back to its source document.
   Nobody else writes journal lines.

This gives one audit trail from any ledger line back to the payslip, invoice or fuel log behind it.
A company that doesn't use Accounting simply has no posting rules, and the modules still work.

### Rule 3: shared dimensions on every money line
Every journal line, budget line, bill, invoice, payroll cost and project cost carries the same **dimensions**:
company, branch, **cost centre (department)**, **project**, and optional fund/donor and activity (planning).
That's what makes "budget vs actual" by department, project or activity a single query, not a separate report per module.

### Rule 4: budget control is a shared service
`Budgeting\Contracts\BudgetService::check(dimensions, account, amount)` is called before committing money:
purchase request, purchase order, bill, payroll, project expense. Per company it can be off, warn, or block.
Commitments (approved but not yet paid) reduce the available budget until they're paid or cancelled.

### Rule 5: every module is switchable per company
- Backend: each module has a **manifest**: name, layer, dependencies, permissions, settings, events it listens to,
  posting rules it offers. The existing `company_modules` table decides which are enabled. A disabled
  module's routes return 403 and its listeners don't run.
- `/api/v1/me` returns the enabled modules and permissions. The frontend builds its menu from them.

### Frontend at this scale
- **One SPA, lazy-loaded modules.** Each `app/modules/<name>/` registers its own routes, menu items and permissions
  through a small module manifest. Its code is downloaded only when the user opens it, so 20+ modules don't slow the first load.
- Modules still only use the shared component library and the generated API client. Accounting, billing and budgeting
  need a few extra shared components (`MoneyInput`, `AccountPicker`, `DimensionPicker`, `JournalLines` editor,
  `BudgetVsActual` table), which go into `shared/`, not into one module.
- No micro-frontends: one team, one design system and one build are simpler. The module manifests keep the option open.

### Suggested build order after HR
1. **Master data + Accounting backbone** (fiscal periods, chart of accounts, dimensions, posting engine). Payroll posting is the first user.
2. **Bills + Billing + Loans module** (money out / in, with the existing loans moved out of HR into a standalone module that Payroll consumes).
3. **Budgeting** with budget control, then **Procurement** checking budgets.
4. **Assets** (depreciation postings), **Fleet**, **Inventory**.
5. **Projects** and **Planning** (they mainly reuse dimensions, budgets and timesheets).

## 9. Backend technology independence

The frontend depends only on the API contract, not on Laravel. The backend could later be rewritten
(e.g. Spring Boot) without touching the frontend, **as long as the contract stays identical**:

| Contract piece | Must stay the same |
|---|---|
| Response shape | `{ data, meta, links }`. In Spring, wrap results in DTOs, don't expose the default `Page` JSON |
| Validation errors | 422 `{ message, errors: { field: [...] } }` (Spring: Bean Validation + `@ControllerAdvice`) |
| List queries | `filter[..]`, `sort=-field`, `page`, `per_page`, `include` (Spring: JPA Specifications / QueryDSL) |
| Auth | cookie session + CSRF for the SPA (Spring Security), or OAuth2/OIDC if SSO/mobile is needed |
| `/api/v1/me` | same JSON: user, employee, company, enabled modules, permissions |
| Files | same download URLs for PDFs, Excel and bank files |
| OpenAPI | generated by the backend (`springdoc-openapi` in Spring), so frontend types keep being generated the same way |

A migration can then go module by module behind the same `/api/v1` paths (e.g. via a reverse proxy routing
some paths to the new service), with the contract tests from section 6 proving nothing changed.
The Angular frontend works the same with either backend. The decision (2026-11-09) is to stay on a Laravel modular monolith, no microservices.
