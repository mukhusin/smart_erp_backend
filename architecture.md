# Smart ERP Architecture: Separate Backend and Frontend

Decided 2026-11-09. The backend and frontend are **completely separate applications** that only talk over
an HTTP API. They live in one repository for now, laid out so each half can move to **its own repository**
later without code changes.

## 1. Where we are

| | Today | Target |
|---|---|---|
| UI | 184 Blade views (~13k lines), Bootstrap 5 + Alpine | SPA in the `smart-erp-frontend` repo (Angular + TypeScript + Bootstrap 5 CSS) |
| Controllers | 62, of which 61 `return view()` | API controllers returning JSON only |
| API | none (`routes/web.php` only), no Sanctum | `/api/v1/...`, Sanctum auth |
| Validation / output | inline in controllers | FormRequests in, API Resources out |
| Modules | `nwidart/laravel-modules` installed, unused | one module per business area |

smart-erp is not in production yet, so the switch costs least now. **Rule from today: no new Blade UI.**
Every gap-list item is built API-first.

## 2. Two repositories (decided 2026-11-09)

| Repository | Folder | Contains |
|---|---|---|
| `smart_erp_backend` (GitHub: mukhusin/smart_erp_backend) | `Dev/outside/smart_erp_backend` | Laravel 13 API, `api-contract/openapi.json`, these docs |
| `smart_erp_frontend` (GitHub: mukhusin/smart_erp_frontend) | `Dev/outside/smart_erp_frontend` | Angular SPA (foundation committed: shell, module manifests, Sanctum-ready HTTP setup, Bootstrap CSS) |

**Rules:**
- Neither repository imports code from the other. The only link is the OpenAPI contract.
- The backend generates `api-contract/openapi.json` and commits it, so each contract change is visible in review.
- The frontend runs `npm run api:generate`, which reads the contract (locally `../smart_erp_backend/api-contract/openapi.json`,
  in CI a URL or the backend's release artifact) and regenerates `src/app/core/api/generated/`.
- Each repository has its own CI, `.env`/environment settings, versioning and deployment.
- **Local development:** `php artisan serve` (port 8000) + `npm start` in the frontend (port 4300). The Angular dev server
  proxies `/api` and `/sanctum` to port 8000, so the browser sees one origin and Sanctum cookie auth needs no CORS.
- **Production:** one domain. The web server serves the built SPA and forwards `/api`, `/sanctum` and `/storage` (uploaded files) to Laravel
  (or `app.` and `api.` subdomains of the same site, with `SANCTUM_STATEFUL_DOMAINS` and CORS set).

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

### Module layout (`Modules/<Module>/` in the backend repo)
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
| Material 3 components (side menu, toolbar, menus, dialogs, snackbars, ripple, badges; tables and form fields next) | Angular Material, themed from each company's brand color, light/dark | No (Angular team) |
| Styling and icons | **Bootstrap 5 CSS only** (no Bootstrap JS, no ng-bootstrap) + Bootstrap Icons | CSS/icons only |
| Charts (later, dashboards) | decide when needed; prefer one small library | Yes, one |

**Dev-time only (not shipped to users):** OpenAPI client generator, Playwright, ESLint/Prettier.

**Dependency rules:**
- A new runtime package needs a reason no Angular/CDK feature covers, and is added in `core/` behind our own wrapper,
  so it can be replaced in one place.
- No third-party UI suite (PrimeNG, ng-bootstrap...). Angular Material (Angular team) + Bootstrap grid/utilities; shared components wrap them.
- Stay on Angular's supported versions; run `ng update` each major release.

### Layout (`src/app/` in the frontend repo)
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
- Both: lint + format in CI, one pipeline per repository.

## 7. Moving the current smart-erp code
Not in production, so module by module, no big bang:
1. **Foundation:** frontend repo created (Angular shell done). Backend: add Sanctum, `/api/v1/me`,
   response helpers, query builder, OpenAPI generation, and the frontend shell + shared components (DataTable, forms, modal, layout, auth).
2. **Core + reference data** through the generic CRUD endpoint and `CrudPage`.
3. **The Blade UI was removed on 2026-11-09** (views, Alpine, Vite and the web controllers). The last version with it is
   tagged `blade-ui-final`. Use it as reference: for each module, move the logic from the old controller into **Actions**,
   add API controller/Requests/Resources/tests, then build the Angular pages from shared components.
4. **New gap-list items** (workflow engine, work entries, waiting contracts…) are built API-first directly, following the
   build order in `hrms-gap-list.md`.

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

## 10. Multi-tenancy (decided 2026-11-09)

One system serves many client organizations (companies). One database, every company-owned row has `company_id`.

| Level | Who | What they see |
|---|---|---|
| Platform | System owner (`users.is_platform_admin = true`, no company, no employee record) | Client companies only: register, suspend, modules, resend invite (`/api/v1/platform/*`). No company data |
| Company | Every other user (`users.company_id` required) | Only their company's data. Each company has its own branches, roles and settings |
| Branch | `company_branches` (one head office per company) | A dimension on users, employees and departments; other records get it through the employee |

**Data rules (easy relations, easy maintenance)**
- **Company-owned main records** (45 tables: employees, departments, leave types, payroll sheets...) have a required `company_id`
  and use the `BelongsToCompany` trait: queries only see the current company, new rows get it automatically.
- **Child rows** (contracts, payslip lines, order items...) have **no** `company_id`: they are reached through their parent.
- **Shared lists** (banks, ERP modules, permissions) have no company.
- Uniqueness is per company: `(company_id, employee_no)`, `(company_id, plate_number)`. Usernames and emails are unique system-wide (login).
- The company filter **fails closed**: in a request with no company (guest, platform admin) it returns nothing.
  Cross-company code must say so: `Model::withoutCompanyScope()` or `CompanyContext::run($company, fn () => ...)` (scheduled jobs).
- Companies are suspended (`is_active = false`), not deleted. Suspended companies' users cannot log in or use the API (`company_suspended`).

**Registering a company** (`POST /api/v1/platform/companies`, one transaction): company → branches (one head office) →
enabled modules (optional expiry) → the company's default roles → HR department and HR Manager position →
the first HR admin as **employee + user** with role `company-admin` → invite email with a link to set their own password
(`POST /api/v1/auth/set-password`, link valid 3 days). The platform admin never knows the company's passwords.
After that the company admin registers staff, branches, departments and roles.

## 11. Access control (decided 2026-11-09)

**Permissions** are defined in one catalog in code (`Modules/Core/Support/PermissionCatalog.php`), named `<module>.<action>`
and grouped by module (the module is the permission's category). `php artisan erp:sync-permissions` loads them.
HRMS permissions map to these names in `hrms-migration-mapping.md` section 5.

**A user may do X** when all of these hold (`Modules/Core/Services/AccessService.php`, wired into Laravel's Gate):
1. X's module is **enabled for the company** (`company_modules`, set by the platform admin), and
2. the module is **not locked for the user's branch** (`module_locks.company_branch_id`), and
3. the module is **not locked for the user** (`module_locks.user_id`), and
4. the user holds X through **any** of: a **direct permission**, **any of their roles** (a user can have many),
   or a role **handed over** to them right now (`role_handovers`: from user, to user, start, optional end, reason, revocable).

Roles are per company (Spatie teams with team = company): each company can change its roles without affecting others.
New companies start with `company-admin` (all), `hr-officer`, `hod`, `employee` (`DefaultRoles`).

`GET /api/v1/me` returns the result of these rules: `modules` (what the user can open), `permissions` (effective),
`roles` (assigned + handed over) and `acting_roles` (handover details). **The frontend uses only these lists** to show menus,
pages and buttons; the API still checks every request (`can:` / `permission:` / `module:` middleware and policies go through the same service).

Still to design: **data scope** (own / section / department / branch / company) for permissions like
"working hours of my department"; until then screens filter by the user's own department.

## 12. Frontend UI standards

**Layout (2026-11-10):** Material shell. The Dashboard is a module launcher: tiles for every module available to the user's company
and branch; modules locked for the user (or without any permission) are shown faded and cannot be opened; modules locked for the
company or the user's branch are hidden. Opening a module switches the side menu to that module's own menu (module dashboard + pages,
"All modules" to go back). Motion: page cross-fade (view transitions), staggered slide-up of cards/rows, top progress bar while loading;
all disabled for users who prefer reduced motion.

Professional and friendly by default. Built once in `shared/`, used by every page:

| Situation | What the user sees |
|---|---|
| Loading a page or list | **Skeleton placeholders** in the shape of the content (table rows, cards, form fields), not a blank page or a lone spinner |
| Saving / a button action | The button shows a small spinner and is disabled until done (no double submit) |
| Empty list | An empty state with an icon, one sentence and the main action ("No employees yet. Register employee") if the user may do it |
| Validation error (422) | The message under each field (from the API `errors`), focus moves to the first wrong field |
| Other errors | A toast with the API `message`; for lists and pages, an inline error card with **Retry** |
| No permission (403) | A friendly "You don't have access" page; the menu never shows modules or actions the user cannot use |
| Session ended (401) | Back to the login page with the reason (expired, logged in elsewhere) |
| Offline / server down | A clear "Can't reach the server" message with Retry |
| Success | A short toast ("Employee registered") |

Other rules: responsive down to phone width, keyboard-accessible forms, consistent page header (title, breadcrumbs, main action),
dates and money formatted for the company (currency, fiscal year), and `*appCan="'hr.employees.create'"` to show actions by permission.

## 13. Company administration and approvals (decided 2026-11-10)

**Company admin** (role `company-admin`, one or more per company; a company always keeps at least one active):
- may **deactivate / reactivate** users (reason required; the user is logged out at once), **assign roles** (several per user),
  give **direct permissions**, **create roles** and choose their permissions, hand over roles, lock modules for a branch or user,
  and manage the login page announcements;
- may **not** create, edit or delete users' personal details: users come from HR (the employee record) and are changed there.
  There is no update or delete endpoint for users in the admin API.
- `company-admin` always has every permission and cannot be edited; built-in roles cannot be deleted; custom roles only when unused.

**Every approval in the ERP uses the dynamic workflow engine** (Core module), configured **per company by the company admin**
(steps, approver roles or users, conditions such as amount or department). No module hard-codes its approval chain.
- A module asks the engine whether a workflow is configured for its process (e.g. "leave request", "user access change").
- If yes, the record is saved as `pending`, a workflow instance starts, and the engine calls the module's apply/reject action
  at the end. If no workflow is configured, the action applies at once.
- Built in `Modules/Workflow` (2026-11-10): processes are registered in `WorkflowCatalog` (each implements `WorkflowProcess`:
  summary for the inbox, branch/department of the request, `onApproved`, `onRejected`). Per process the company admin defines
  ordered steps; approver = a role (optionally same branch; handed-over roles count), a specific user, or the head of the
  requester's department. A branch may have its own workflow that overrides the company one. Steps are copied when a request
  starts, so editing a workflow never changes requests in progress. Approvers can approve, reject (comment required) or send back
  to an earlier step; the requester can withdraw; nobody approves their own request.
- User access changes already work this way: `ChangeUserAccess` records every change in `access_changes`
  (type, before, after, reason, requested by, status). Today status is `applied`; with a configured workflow it will be `pending`
  until approved. The API and screens do not change.

## 14. HR employee registration (2026-11-10)

- `POST /api/v1/hr/employees` (`hr.employees.create`) creates the employee and, with `create_account`, the user account in one
  transaction. The invite email is sent after the commit; `meta.invite_sent` tells the frontend whether it went out.
- A new account gets only the `employee` role. Any other role is given by the company admin in Administration → Users, so it
  goes through the access-change workflow. HR never assigns roles.
- An employee without an account can get one later (`POST hr/employees/{id}/account`). The invite can be resent while the
  password is not set (`POST hr/employees/{id}/invite`).
- Lookups for forms: `hr/options` (fixed choices), `hr/branches`, `hr/departments` (sections with `parent_id`), `hr/positions`
  (filtered by department), `hr/employees/next-number` (continues the last number's prefix and padding).
- Invites for any user go through `Modules/Core/Actions/SendUserInvite` (invites broker); company and staff invites only differ by email.
- Not yet: editing employees, photos, contracts, and data scope (HOD sees own department only).

