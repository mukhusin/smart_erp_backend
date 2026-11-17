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

### Attendance is optional (agreed 2026-11-12)

A company can decide not to use attendance at all. Allowances, overtime and payroll must then work fully from
contracts, schedules, rosters, leave and approved requests (payroll "Mode A", hrms-gap-list.md). Allowances or
overtime that depend on duty are checked against the employee's **roster and timetable** for that day; attendance,
when the company uses it, is an extra check, never a requirement. Everything reads daily **work entries**, built
from the roster/schedule (and attendance when used).

### Suggested build order after HR
1. **Master data + Accounting backbone** (fiscal periods, chart of accounts, dimensions, posting engine). Payroll posting is the first user.
2. **Bills + Billing + Loans module** (money out / in, with the existing loans moved out of HR into a standalone module that Payroll consumes).
3. **Budgeting** with budget control, then **Procurement** checking budgets.
4. **Assets** (depreciation postings), **Fleet**, **Inventory**.
5. **Projects** and **Planning** (they mainly reuse dimensions, budgets and timesheets). Planning scope: §17.

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

**User manuals (2026-11-17): every module ships with one, inside the system.** One fixed pattern (frontend `src/app/help/`):
a manual is data (`Manual`: summary, who uses it, how it flows, topics), rendered the same way for every module:

- Topic = title, who it is for, intro, numbered steps (each can link to the page), status table with the real badges,
  tips, warnings, questions. Topics carry permissions, so users see what applies to them ("Only what applies to me"
  can be switched off); printable.
- Help center `/help` (manuals of the user's modules, search across all steps); manual `/help/:key#topic`.
- The top-bar **?** opens the current module's manual; each page header's **?** (`help="tasks#report-progress"`)
  opens the topic for that page.
- **Two languages: English and Kiswahili** (switch on the help pages, remembered per browser). Each manual has a
  Kiswahili twin in `help/sw/` with the same topic ids; button and menu names stay in English so users find them on
  screen. A manual without a translation is shown in English with a note.
- All 16 modules have manuals in both languages (2026-11-17), and every page header links to its topic.

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

## 15. Employee profile (2026-11-11)

After registration the profile guides HR to complete the record. Sections are tabs that load on demand:
Overview, Contracts, Qualifications, Licences, Experience, Documents, Dependants. A completion card counts
contract, qualifications, documents, next of kin and photo as required; licences and experience are optional.

Contract rules (ported from HRMS `EmployeeController`/`EmployeeContract`, in `Modules/Hr/Actions/ManageContracts.php`):

- one active contract; end date = start + period months − 1 day;
- a renewal can be added only in the last 90 days, must start after the active one ends, one waiting at a time;
- `contract:status` (every two hours, per company) ends expired contracts and starts waiting ones;
- the active contract's type sets `employees.emp_type`; every change is logged in `employee_contract_events`;
- ending with a reason other than `ended`/`change_of_contract` marks the employee terminated and cancels the renewal;
- "Remove wrong contract" deletes only the latest contract not used in payroll and restores the previous one;
- ending and removing need the user's password (as in HRMS).

Employee numbers (HR settings → Employee numbers, permission `hr.number-formats.manage`):

- a number format is an ordered list of segments: fixed text, running number (digits, start), year (YY/YYYY),
  department code (department short name); joined by a separator (none, `/`, `-`, `.`, `:`);
- each contract type uses one format, one format can serve many contract types; the default format is used at
  registration (HR can pick another) and for contract types without one;
- a company without formats of its own numbers like HRMS (built-in, `EmployeeNumbers::BUILT_IN`): ST / PT / VOL / IND
  by employee type, interns `INT/{company code}/{department code}:0001/YY`, residents `RES/{company code}/SN:001/YY`
  (company code = short name or initials), and renumbering on contract change is on unless HR turns it off;
- running number = highest in use for that format + 1 (removed employees and old contract numbers count);
- with "Give a new number when the contract type changes" on, a starting contract renumbers the employee into its
  contract type's format (HRMS ST/PT/VOL). The old/new number is saved in `employee_contract_events.changes`, so
  "Remove wrong contract" restores it exactly (or gives a fresh one in the same format if it was taken).

Leavers: a leaving reason deactivates the user account on the effective date (at once if today or past, else by
`contract:status`), without approval, recorded in `access_changes` with the reason. The only company admin is kept
active and HR is warned.

SMS (Administration → SMS settings): `Modules/Core/Sms` with drivers `log` and `afyabox` (rows in AfyaBox's
`messaging` table, as HRMS; connection `AFYABOX_DB_*` in `.env`). Messages are kept in `messagings`/`message_recipients`.
`hr:contract-reminders` (daily 08:30) ports HRMS `app:sms-notify-three-month-contract`: active contract ending within
90 days, no renewal waiting, valid Tanzanian mobile, at most 3 per contract end date, 28 days apart (failed sends do
not count, so they are retried). Not ported: the 90/60/30/10/5 jobs (disabled in HRMS), AfyaBox delivery-status sync.

Files live on the private disk and are served only by authenticated HR endpoints. Updates that carry files
use POST with multipart form data; fields left out are cleared, files are kept unless replaced or removed.

## 16. Data scope, departments and interns (2026-11-11)

Employee data scope (`Modules/Hr/Support/EmployeeScope.php`, middleware `hr.scope` on every HR route), from HRMS
(HR office sees all, `department-office` its department, `department-section` its section):

- `hr.employees.view`: all employees; `view-branch`: own branch; `view-department`: own department plus departments
  they head; `view-section`: own section. Permissions combine (union). The default `hod` role has `view-department`,
  the new `section-head` role `view-section`.
- Lists and dashboard counts only include employees in scope; an employee outside it is 404 for every HR endpoint
  (records, files, writes). Lists return `meta.scope` with a label for the UI.

Departments (HR → Departments, `hr.departments.manage`): departments with one level of sections, short code, branch.
One active head per department (`hod`) or section (`hos`), history kept (`started_at`, `ended_at`, reason). Appointing
gives the head's account the `hod` / `section-head` role and takes it from the previous head (unless they still head
another), through `ChangeUserAccess` (logged, follows the user-access workflow). Departments with staff or sections
are deactivated, not deleted.

Interns and residents (HRMS `internship_periods`, `resident_periods`): `employee_placements` (type `internship` |
`residency`). End date = start + months - 1 day, year = start year; status upcoming → active → ended (`contract:status`),
or cancelled/ended early. The type must match the employee type, periods cannot overlap. Profiles of interns and
residents show their periods instead of contracts; HR → Interns and residents lists them with filters.

Login needs employment (HRMS `LoginController::hasContract`): a user of a company with the HR module logs in only
with a linked employee who has an active contract (interns/residents: an active period). Platform admins and
company admins are exempt, so a new company is never locked out. Contracts are synced first (a renewal starting
today works before the nightly job). Open sessions are re-checked every 15 minutes and end with 401
`code: access_ended`. Modules add such rules by implementing `Modules\Core\Contracts\LoginCheck`, tagged `login.checks`.

Names across branches: departments are per branch or company-wide. The same department name is allowed in different
branches, but not next to a company-wide department of that name, nor twice in one branch (sections: within their
parent). Job positions (HR → Job positions, `hr.positions.manage`) belong to a department and are unique per department,
so each branch's "Nursing" can have its own "Registered Nurse". Pickers show the branch next to the name. An employee's
department must be company-wide or of the employee's branch, and the position must belong to that department.
Positions ever held are deactivated, not deleted.

Banks (HR settings → Banks, `hr.banks.manage`; was `payroll.banks.manage`, moved to HR because employee bank accounts
live in HR) are per company. Each employee has one active bank account (HRMS rule); changing it keeps the old one
inactive. The same account number at the same bank cannot belong to two employees.

Supervisors (HRMS emp_supervisors): by default every employee reports to the head of their department (shown as the
role, "Head of Nursing", not the person; the head has no default). An assigned supervisor overrides it; assigning a new
one closes the current one the day before (history kept, with reason); "reset" ends the override. `App\Services\ReportingLine`
works this out (not stored), so a new department head takes over at once. An employee cannot supervise themselves or anyone above them in the chain.
Approval workflows have a `supervisor` approver type (`workflow_instances.employee_id`, from
`WorkflowProcess::employeeId()`): the step goes to whoever the employee reports to (assigned supervisor, else head of department).

Deductions (profile tab, shown when the company uses Payroll; edit with `payroll.statutory.manage`), HRMS rules in
`Modules/Hr/Support/DeductionSchemes.php`: NSSF 20% of basic shared (employee share chosen, employer pays the rest),
NHIF 6% shared the same way, HESLB and TUGHE a percentage paid by the employee, NIC and employee fund a fixed monthly
amount. NSSF/NHIF numbers are unique and copied to the employee record. One membership per scheme; a membership can be
paused. The tab estimates monthly amounts from the active contract's basic salary. Payroll (step 6) will read these.

## 17. Planned modules (agreed 2026-11-11, not built yet)

### Planning (new module)

- **Strategic plan** for a period of several years (e.g. 2026–2030) per company.
- **Annual plan** for a financial year (period) of the company: the activities planned for that year, each with its
  objective, service output, target and KPI.
- Reuses fiscal periods and dimensions from the accounting backbone; budgets can be attached later.
- Likely link (to confirm): annual plan activities feed the department activities used in performance appraisal below.

### NOTE: planning, budgeting and projects (to discuss, 2026-11-16)

**Planning and budgeting: one module or two?** Suggestion: **two modules over one shared structure.**

- Shared: fiscal periods, departments/cost centres, the chart of accounts and the **activity** (one record used by
  planning, budget, tasks and appraisal; no copies).
- **Planning** (strategic plan → annual plan → activities with objective, output, target, KPI) answers *what* we do.
- **Budgeting** (budget per activity and account line, versions draft/approved/revised, approval through the
  workflow engine, budget vs actual from accounting, commitments from purchase requests) answers *with what money*.
- Why separate: different users and approvals (planners/heads vs finance), a company can use one without the other
  (budget without a strategic plan is common), and budget control must hook into purchasing/accounting.
- Why one screen still works: the annual plan page shows each activity's budget and spend; "plan and budget" can be
  prepared together in one wizard, saved into the two modules.
- Alternative (one module "Planning & Budgeting"): simpler menus, but budget control then depends on planning being
  switched on. Decide before building Planning.

**Project module (to consider).**

- A project = a time-bound piece of work with its own budget, team, milestones and deliverables (e.g. a donor-funded
  programme, a building or IT project), possibly across departments and years.
- Reuses: **tasks** (project tasks are ordinary tasks with `project_id`; milestones = parent tasks), **budgeting**
  (project budget lines, budget vs actual), accounting dimensions (project as a cost dimension on journals, purchases,
  payroll cost allocation), **workflow** (project approval, budget revision), HR (team members, time spent).
- Links: an annual-plan activity can be delivered by a project; staff time on project tasks can feed appraisal and
  donor reporting (timesheets).
- Order to discuss: tasks (now) → appraisal → planning/budgeting → projects, keeping `project_id` in mind on tasks.

### Contract renewal (built 2026-11-12)

Two ways to renew, both through the dynamic approval workflow (process `contract-renewal`, module hr), in
`Modules/Hr/Actions/ManageRenewals.php` and `contract_renewals`:

- **HR-initiated:** HR → employee → Contracts → "Start renewal" with the terms (type, period, basic salary).
- **Employee-requested:** Self service → My contract → "Request renewal" with a comment, optional type/period and
  up to 5 attachments (e.g. a timesheet).
- **One open renewal per employee** (pending, or approved without contract): while the employee's request is open HR
  cannot start one, and the other way round; the refusal says why.
- Opens in the last 90 days of the active contract, never with a waiting contract or for someone who has left.
- **Approved** with complete terms: the waiting contract is created at once, starting the day after the current one
  ends. Without complete terms (usually employee requests) HR creates it from the renewal ("Create contract").
  No workflow set up = approved at once. Rejected keeps the approver's reason; the starter can withdraw while pending.
- HR → Contract renewals lists them (open by default). Approvers see the current contract, proposal, comment,
  attachments and previous contracts in the approval request.

### Task management and appraisal (decided 2026-11-16)

One system in two layers: **task management** records the daily work; the **appraisal** reads it at period end.

- Department objectives/activities (head of department, per year) → tasks and sub-tasks (assigned by a head, or added by
  the employee) → progress updates (what was done, % done, capped per update) approved by the supervisor through the
  workflow engine → appraisal.
- Tasks: priority, start/due date, attachments, checklist, status To do / In progress / Waiting for review / Done /
  Returned, overdue flag, comments, notifications, recurring tasks. Views: My tasks (self-service), Team tasks (heads,
  My team), company view (HR). Reports on completion and timeliness, never pay.
- **Visibility: staff see only their own tasks** (not colleagues'); heads see their team's.
- **Appraisal period: configured by the HR manager; default yearly with a mid-year review** (quarterly possible).
- **Score parts and weights configured by the company** (e.g. objectives/tasks, competencies, attendance; any part can
  be switched off).
- **Raters: both the direct supervisor and the head of department**; the HOD can rate even when the supervisor is not
  the HOD (supervisor rates, HOD confirms or adjusts).
- **Ratings: configurable; default percentage** (a 1–5 labelled scale is an option).
- Flow (workflow engine): goal setting → mid-year review → self-assessment → supervisor review → HOD rating/confirmation
  → HR moderation → employee acknowledgement. Leave-aware due dates. Appraisal PDF, distribution per department,
  history on the profile (HR).
- Build order: 1) task management, 2) appraisal on top, 3) link to Planning (annual plan activities).

**Built 2026-11-17.**

- **Tasks** (`Modules/Tasks`, module `tasks`): department activities per year, tasks/sub-tasks, checklist, comments,
  weekly/monthly repeat, progress updates approved through workflow `task-progress` (default: supervisor), cap per
  update in HR settings (`task_max_step`, default 30). Finishing a task given by someone else goes to review. Daily
  `tasks:daily` (06:00) repeats tasks and sends due/overdue reminders.
- **Appraisals** (`Modules/Appraisals`, module `appraisals`): HR sets the scale, mid-year review, parts with weights
  (must add to 100) and rating bands; competencies list. A period (draft → open → closed) snapshots the settings and
  competencies when opened and gives each active staff member an appraisal. Employee self-assessment → workflow
  `appraisal` (default Supervisor → Head of department → HR moderation). Each reviewer rates on the appraisal page and
  approves or returns it; when the supervisor is also the HOD both steps pass in one go. Final score = weighted parts,
  taken from the latest rater (HR > HOD > supervisor > self). Employee acknowledges (agree/disagree). Tasks of the
  period are shown with a suggested tasks score. PDF per appraisal; period page shows status, ratings and departments.
- Not yet: leave-aware due dates, appraisal history on the HR profile, renewal request showing the last score.

**Planning built 2026-11-17** (`Modules/Planning`, module `planning`; decision: Planning and Budgeting are two modules
sharing one activity record):

- Strategic plan (years, vision, mission, one active at a time) → strategic objectives (code, title).
- Annual plan per financial year (dates default from the company's `fiscal_year_start`), optionally following a
  strategic plan. Opening it gives every top-level department a department plan.
- The HOD (`planning.department`) adds activities (code, objective, output, target, KPI, quarters) and submits;
  approval through workflow `department-plan` (default: director general). Approved activities become active.
- Activities are the same `task_activities` records Tasks and Appraisal use. When Planning is used for a year,
  activities for that year can only be changed in Planning; tasks can only go under active (approved) activities.
  Companies without Planning keep department activities in Tasks.
- Progress of activities, departments and objectives comes from the tasks under them.
- Next: Budgeting (budget lines per activity and account, versions, workflow approval, budget vs actual); projects
  later, with tasks designed so a `project_id` can be added.

### Performance appraisal (new design, replaces the HRMS one)

The HRMS appraisal is not used in production, so it is redesigned instead of ported. To be discussed in detail
before building; agreed so far:

- The **head of department** creates the department's activities for the financial year.
- Each **employee** of the department adds **sub-activities** under each activity, and records progress on them as
  they do the work.
- **Progress entries:** each time the employee does work on a sub-activity they record a progress entry (what they
  did) and give it a percentage. The percentage of one entry is capped by a configurable maximum, so progress cannot
  jump in a single entry.
- **Approval:** a progress entry is a draft the employee can still edit until they **submit it for approval**. Once
  submitted it goes to the employee's supervisor (by default the head of their department, §16 reporting line), who
  approves or rejects it; only approved entries count towards progress. Uses the workflow engine's `supervisor` approver.
- **Configurable per company:**
  - the **maximum percentage per progress entry**; and
  - optionally, filling **by quarter** with a **minimum percentage** that must be reached each quarter.
- **Leave is taken into account:** days on approved leave do not count against the employee (targets and
  minimums are prorated).
- Results are used for decisions such as **contract renewal** (shown on the renewal request, gap list E-17).


## 18. Planning, budgeting and accounting as one chain (designed 2026-11-17)

Based on how Oracle (budgetary control and encumbrance), PeopleSoft (commitment control), Odoo (budget vs committed vs
achieved) and FP&A tools (versions, scenarios, drivers, rolling forecasts) work, and on the Tanzanian public-sector
chain (PlanRep → FFARS/MUSE: activity, GFS code, fund source, payment voucher, ledger). It follows §8 rules 2–4.

**The chain:** strategic objective → annual-plan activity → **sub-activity** → **budget item** (sub-activity × account
with GFS code × fund source × department, phased by month or quarter) → **commitment** (payment request, purchase request
or order reserves the money) → **payment voucher** → **journal** (posting engine) → **general ledger** → budget vs actual
→ back into planning (rolling forecast, next year's baseline).

**Shared master data (Accounting):** fiscal years and periods (open/closed), one chart of accounts (each postable
account may carry a GFS code and is marked budgetable), fund sources (own revenue, insurance/NHIF, donor, grant…),
departments as cost centres, projects later. Every money line carries the same dimensions (§8 rule 3).

**Budgeting:**
- A budget per fiscal year, prepared from the approved annual plan: each department budgets its sub-activities, with
  last year's actuals shown beside the input. Line = quantity × unit cost × frequency (simple drivers), phased by period.
- Versions: **original** (approved and locked) and **revised** (original + approved transfers and supplementary
  budget). Department submission and company approval through the workflow engine; every change audited.
- **Transfers (virement):** from one budget item to another, with reason and approval; original stays visible.
- **Control** (`BudgetService::check`): available = revised budget − actual − open commitments, per item. Per company:
  off, warn, or block; an override needs a permission and goes to approval. Funds are reserved when a request is
  submitted, converted when it is ordered, and consumed when the voucher is posted (released if rejected/cancelled).

**Accounting:** journals only through the posting service (manual journals with approval for adjustments), period
closing, general ledger, trial balance. Payment request (lines against budget items) → approval → payment voucher
(bank/cash, reference) → posting: Dr expense (account + dimensions) / Cr bank. Payroll posts through posting rules.

**Reports:** budget vs actual with budget, revised, committed, actual, available, variance and %, by period, department,
activity, account/GFS and fund source; every actual drills down to the journal line and its source document.

**Phases:** A) accounting foundation (periods, chart of accounts with GFS, fund sources, journals, posting, ledger,
trial balance); B) budgeting (sub-activities, items, phasing, versions, approval, transfers, budget vs actual);
C) expenditure (payment requests with funds check and commitments, vouchers, postings, payroll posting);
D) procurement (PR/PO encumbrance, three-way match), revenue/billing, driver-based scenarios and rolling forecasts.

### 18.1 Requirements document and decisions (2026-11-17)

Requirements: "Planning, Budgeting & Accounting — Functional Requirements" (IDs GEN, PLN, BUD, ACC, RPT, NFR). Decisions:

- **Release (BUD-30..36): configurable per company, default direct.** Opening a quarter (with approval) releases that
  quarter's budget at once; a company can switch on fund requests + allocation (government style).
- **Reservation (BUD-43): on submission** of a payment/purchase request; released on reject, withdraw or cancel;
  converted to spent when paid; balances re-checked at approval. Concurrent spending locks the line (BUD-45).
- **Statements (ACC-84): IFRS for SMEs** (accrual): financial position, income statement, cash flow, changes in equity,
  budget vs actual; account-to-line mapping without code changes (ACC-85).
- **Order: control first.** (1) budget balance ledger: every movement (approved, released, requested, committed, spent,
  transfer) stored as an entry and balances = sum of entries (BUD-40..48) + quarter release/fund requests + amount-based
  approval steps in the workflow engine (GEN-31); (2) payment requests → vouchers → posting (many payees, withholding
  tax, voucher approval, bank lists, cancel/void), imprest and petty cash; (3) payables, receipts/billing, bank
  reconciliation, tax; (4) year-end closing and carry-over, financial statements, planning targets/KPIs/weights,
  progress and performance report.

**Coverage after phases A–B (backend):** built — GEN-01..03, 10..12, 20..24, 30, 32..36, 40..41; PLN-01..03, 10, 12,
14 (as activities' targets), 20..21, 24, 28; BUD-01..04 (department ceilings), 10, 17, 20..22, 50, 55..56 (transfer
register); ACC-01, 03 (partly), 04, 51..55, 60, 80, 83; RPT-04, 06, 08, 13. Next: GEN-31, 42..43; BUD-30..48, 51..54,
60..61; ACC-02, 10..23, 30..33, 40..45, 50, 61..65, 70..72, 81..85; PLN-11, 15..16, 22..23, 30..34; remaining RPT.

### 18.2 Target users: private institutions and NGOs, not government (2026-11-17)

Smart ERP is built for **private companies (e.g. private hospitals like Kairuki) and NGOs**. The requirements document
came from a government ERP; we take its control ideas, not its government processes:

- **Kept:** plan → budget → commitment → payment → ledger chain, budget balances and control, approvals (amount-based),
  audit trail, reallocation/supplementary budget, payments/imprest/petty cash, payables/receivables, bank
  reconciliation, tax (VAT, withholding), year-end, IFRS for SMEs statements.
- **Optional / low priority:** GFS codes (off by default), quarter release with fund requests and allocation
  (default: opening a quarter releases it, or no release step).
- **Out of scope:** national planning/budget systems, treasury submission, government consolidation and inter-entity
  elimination (BUD-62, ACC-86, NFR-01, NFR-04), government payment gateway.
- **Added for NGOs:** donor grants as fund sources with donor, grant period, currency and amount; restricted vs
  unrestricted funds; budget lines per grant (and donor budget line codes); spending checked against the grant as well
  as the budget line; multi-currency (donor currency vs local); indirect cost / overhead rate; donor reports (budget vs
  actual per grant, in the grant's currency and periods).

### 18.3 Control layer built and payment flow refined (2026-11-17)

**Built:** amount-based workflow steps (`min_amount` per step, processes implement `HasAmount`; GEN-31); budget balance
ledger `budget_entries` with five balances per item — approved, released, reserved (held by a request), committed,
spent — every change an entry with its document and user, balances = sum of entries (BUD-40, 44, 56 line statement);
row locking when holding money (BUD-45); over-budget holds refused or flagged per the control setting (BUD-42); quarter
release through approval, or no release step (BUD-30, 35; fund requests not built — government only); transfers hold
money on the giving item until approved and may not exceed revised − held − committed − spent (BUD-52, 53); spending
recorded from posted journals through a `JournalPosted` event (lower layer publishes, Budgeting listens); daily
`budget:check-balances` (BUD-48). Demo data: `php artisan erp:demo-finance --company=1` (refused in production).

**Payment flow (next, ACC-10..18):** three approved documents.
1. Payment request (one department; budget lines and amount per line; payees with bank/mobile details split across
   lines; attachment required) → on submit money is **held** on each line.
2. Payment voucher, created from the approved request (paying bank account, withholding tax/deductions, journal lines;
   total must equal the request) → on final approval the hold becomes **committed** and the journal posts
   Dr expense (line dimensions + budget item) / Cr payee payable (and Cr withholding tax payable).
3. Payment list (cashier groups approved vouchers of one bank account; transfer, cheque or mobile money; refused if the
   bank balance is lower than the list) → on approval each voucher is settled: Dr payee payable / Cr bank, and the
   commitment becomes **spent**; bank reference stored per payment.
Cancelling at any stage releases the money and reverses anything posted; a paid payment is undone only by a separate,
approved void. Imprest = payment to a staff advance account, retired later into expense.

**External APIs (later):** each client registered to one company; signed request/response envelopes; caller reference
for idempotency; fixed result codes; all field errors at once; same approvals unless pre-approved; callbacks with retry
plus status enquiry; payload log; rate limits (API-01..14).

### 18.4 Payments built; receivables and payables next (2026-11-16)

**Built (module `Payments`, slug `payments`):** payment request → voucher → payment list, each through the dynamic
workflow (`payment-request`, `payment-voucher`, `payment-list`; defaults: head of department then finance; chief
accountant plus director general from 10,000,000; chief accountant). Budget effect, final: request **submitted** =
held (refused in block mode, flagged “over budget” in warn mode); request **approved** = committed; voucher
**approved** = journal Dr expense per budget line (dimensions + `budget_item_id`) / Cr payables / Cr withholding tax,
so the commitment becomes spent (accrual: the expense is recognised at the voucher, not at payment); payment list
**approved** = Dr payables / Cr bank, refused when the bank's ledger balance is lower. Void of an unpaid voucher
reverses the journal and commits the money again on the request. Attachments required by setting. Numbers
PR/PV/PL-YYYY-#####. Permissions `payments.request|view|vouchers|pay|manage`. Bank file CSV per list; bank references
per payee. Not yet: void of a paid payment (needs approval), imprest and retirement, payroll posting.

**Next — receivables (billing):** customers (patients, insurers such as NHIF, companies); invoices Dr receivable /
Cr revenue (revenue budget line); receipts **against invoices** (full or part, allocated, Dr bank / Cr receivable)
and receipts **without invoice** (cash sale, donation: Dr bank / Cr revenue); credit notes; customer statement and
ageing. **Then payables:** supplier master and supplier invoices (bills) Dr expense / Cr supplier payable, paid
through the same voucher → payment list, supplier ledger and ageing, debit notes.

### 18.5 Dashboards and receivables built (2026-11-16)

**Dashboards:** `GET budgeting/dashboard` (totals, plan vs actual by month from journal dates, departments, spending by
account, lines ≥ 90 % used, pending approvals; scoped to the user's departments) and `GET planning/dashboard`
(department plan status, activities per objective and quarter, task status and progress by department). Both are the
start pages of their modules, with charts from `shared/charts`.

**Receivables (module `Receivables`, slug `receivables`, "Billing & Receipts"):** customers (patient, insurer, company,
donor…; terms, credit limit); invoices INV-YYYY-##### Dr receivables / Cr revenue lines (department + revenue budget
line), optional approval `customer-invoice` (no default steps = issue at once); receipts RCT-YYYY-##### **against
invoices** (allocations, part payment, Dr bank / Cr receivables) or **without invoice** (Dr bank / Cr chosen accounts);
credit notes CN-YYYY-##### through `credit-note` approval, reversing revenue in proportion to the invoice lines;
receipt void through `receipt-void` approval (journal reversed, invoices owed again); invoice void only with no
receipts or credit notes. Statement with running balance, ageing (not due, 1–30, 31–60, 61–90, 90+), dashboard.
Permissions `receivables.view|bill|receipts|manage`. Document numbers shared via `Modules\Core\Support\Numbering`.

**Next:** payment list item status (sent/paid/failed, re-pay failed items), supplier bills (payables), imprest,
payroll posting, paid-payment void with approval.

### 18.6 Finance reports and module grouping (2026-11-16)

**Finance and planning reports** (`Modules/Reporting`, `GET reports/finance[/{key}[/csv|/pdf]]`, same shape as HR
reports, each report gated by its module and permissions): Accounting — statement of income and expenditure, statement
of financial position, cash book, trial balance, income and expenditure by fund source, journal register, payments and
receipts registers; Payroll — employee earnings for the year, changes from last month; Loans — outstanding, by
provider, repayments by month, salary advances; Budgeting — budget vs actual (group by department/account/activity/
fund/line), by period, held and committed, transfers; Planning — activities with budget and progress, performance by
department and by strategic objective. Missing choices (no budget, plan, payroll) give an empty report, not an error.

**Grouping:** Payments and Billing & Receipts stay separate modules (licensing, permissions, workflows) but open from
inside Accounting (`ErpModuleSeeder::INSIDE_ACCOUNTING`, `parent_slug = accounting`), like the HR sub-modules.

## 19. Procurement, stores and assets — plan from the requirements (2026-11-16)

Source: "Procurement Requirements", "Asset and Store Management Requirements", "Procurement, Assets and Accounting:
How They Connect" (16 Nov 2026). Adapted for private institutions and NGOs (§18.2): no national e-procurement
portal, regulator returns or central asset register by default (optional adapters later); thresholds, methods and
minimum quotes are configuration, and a fund source (donor) may carry its own procurement rules.

**Money path (one purchase):** requisition approved = budget **held** (our `reserved`); contract/LPO signed = hold
adjusted to the contract amount, surplus released (our `committed`); goods accepted (GRN) = Dr inventory or asset cost
/ Cr **goods-received clearing** (budget line becomes spent through the journal's `budget_item_id`); supplier bill
from the GRN = Dr clearing / Cr supplier payable → existing payment voucher → payment list (Dr payable / Cr bank).
Store items become expense on issue (Dr department/activity expense / Cr inventory, no budget effect — already spent);
assets through monthly depreciation. Clearing must be zero per completed GRN (reconciliation report).

**Rules to keep:** one GRN = one stock entry or asset set = one payment request (idempotent by GRN); approved documents
are never edited — reversal only; every cross-module step carries the source document number and links back
(X-01..03); commitments change in the same transaction as their cause (INT-01); year cannot close with pending
postings or unexplained reconciliation differences (X-17); one owner per master: item catalogue (stores), supplier
register (procurement, also used by payables), budget lines (budgeting). Separation of duties: requester ≠ evaluator ≠
approver, receiver ≠ inspector; conflict-of-interest declaration before seeing bids. All approvals through the workflow
engine with amount steps; escalation after a configurable time and handovers (ROL-04/05) to be added to the engine.

**Build order:**
1. Finish payments: payment list item status (sent / paid / failed / returned, re-pay failed items), payroll posting
   to the ledger, void of a paid payment with approval.
2. Shared masters: supplier register (tax/registration numbers, bank accounts with second-person approval, documents
   with expiry, blacklist, categories), item catalogue (units and conversions, categories carrying account codes,
   store/asset/intangible/WIP type, reorder levels), stores (branch, bins, in-transit store).
3. Procurement core: procurement plan (department needs → packages/lots, method suggested from thresholds, planned vs
   actual dates), requisition with budget hold (normal, framework call-off, special/emergency), request for quotations
   and direct purchase, purchase order (LPO) from an accepted quote or contract, delivery + inspection → GRN → stores/
   assets + supplier bill (payment request from GRN, clearing account).
4. Stores: store ledger (bin card), weighted average default with FIFO option, requisition → issue (expense to
   department/activity), transfers with in-transit and shortages, stock take with frozen snapshot and approved
   adjustments, reorder/expiry/slow-moving alerts, employee store requests (supervisor then store, confirm receipt).
5. Opening balances and year-end close (business practice, no government formats): go-live loader for account
   balances, creditors, debtors and breakdowns (CSV and API, row checks, approval, control-account reconciliation);
   year-end close to retained earnings with locked periods, carry-forward and statements kept as approved.
6. Assets: register (number pattern, type-specific fields, one record per unit, tags/QR), assign/return, HR leaver
   clearance, transfers (temporary with reminders), improvements, useful-life review, straight-line daily
   depreciation posted monthly per category (land and WIP never), impairment, revaluation, disposal by committee,
   employee "my assets", fault reports, yearly confirmation.
7. Procurement advanced: tenders (documents, sealed bids, opening record, evaluation with scores, negotiation, award,
   standstill, complaints), contracts (types, milestones, retention, liquidated damages, securities, variations within
   a limit, progress), supplier performance, special-group share; external APIs per API-01..07.

**Default answers to the open questions (configurable, confirm with the user):** weighted average per organisation
(FIFO optional); capitalisation threshold per category (below it the item is a store item/expense); depreciation from
the date in use, daily rate; cost model, revaluation optional per category; commitment held at requisition and firmed
at contract/LPO; supplier portal later; sales pricing stays in Billing & Receipts.

**Step 1 done (2026-11-16):** payment list items have a status (paid / failed / returned with reason; a failed or
returned line posts Dr bank / Cr payables and the payee can go on a new list — only unpaid payees are listed);
voiding a paid voucher goes through the `payment-void` workflow (money recovered to the bank, expense reversed, budget
committed again); approved payrolls post to the ledger through the `PayrollApproved` event (Accounting listens;
expense per department on the department's salary budget line, deductions and net pay as payables; posting errors
are kept on the sheet and can be retried) and net salaries are recorded as paid from a bank. Report menus split into
Accounting, Payroll (finance + HR payroll reports), Loans, Budgeting and Planning.

**Step 2 done (2026-11-16):** modules `Procurement` (supplier register: one record per TIN, bank accounts through the
`supplier-bank-account` workflow, documents with expiry flags, blacklist by `procurement.manage`) and `Inventory`
(units, categories carrying inventory/expense/revenue/asset/depreciation accounts and useful life, residual % and
capitalisation threshold; items numbered by category prefix with unit conversions, tracking flags and levels; CSV
import; stores with kind, branch, department, nested bins and officer/manager staff; per-store levels and shelf).
Legacy empty Blade-era tables (suppliers, bills, purchase_*, inventory_*, stock_movements) are dropped by the
migration only when empty. Permissions `procurement.view|suppliers|manage|approve`, `inventory.view|catalogue|stores|
manage`. Next: step 3 procurement core (plan, requisition with budget hold, quotations, purchase order, GRN with the
clearing account) and step 4 store ledger.

**Step 4 done (2026-11-17):** store ledger in `Inventory`: `StockService` is the only writer of stock (balance row
locked, one movement per layer, costing from the accounting policy at the movement date: FIFO, weighted average,
specific batch, LIFO only where the framework allows; expiry-tracked items go out expiring first). Accepted GRNs
bring store items in at PO price (`GoodsAccepted` listener). Store requests (`store-requisition` workflow, head of
department) → partial issues → employee confirms receipt; also in Self Service. Stock documents: issue (Dr category
expense per department/activity), transfer (via the transit store, shortages stay in transit), receipt without an
order (opening / donation / found / return, `stock-receipt` workflow), count (frozen snapshot, `stock-adjustment`
workflow, gains/losses at average cost); posted documents can be reversed. Daily `inventory:alerts` (reorder and
expiry) to store managers. Reports: stock balance, movements, consumption, expiry, reorder, slow moving, in transit,
count variance. Settings: opening and donation accounts, expiry warning and slow-moving days. Next: step 5 opening balances and year-end close.

**Step 5 done (2026-11-17):** opening balances and year-end close in `Accounting`, following business practice only
(no ministry submission formats, budget classification codes or central-body consolidation). Opening balances: batches
of account balances, creditors, debtors or breakdowns dated in the first fiscal year, loaded row by row, from CSV or
the API with the same checks (unique reference, account takes postings, supplier/customer found or created, good
rows kept and each bad row explained); account balances must balance and post one `opening` journal; creditors
become approved supplier bills (payment requests without a budget line, paid through vouchers) and debtors become
open invoices against the receivables control account, neither posted again; their total per account cannot exceed
the approved account balance; `opening-balance` workflow; reconciliation per control account; permission
`accounting.opening`; no new loads once a year is closed. Year-end: checks (earlier years closed, no waiting
journals or opening batches, retained earnings account), `year-end-close` workflow, one `closing` journal per fund
source to the retained earnings account, all periods locked, next year created, income statement / financial
position / trial balance kept as approved and reprintable; reopen reverses the closing journal. Closing entries are
left out of income statements, fund statements, dashboards and the year's trial balance (option to include them).
Next: step 6 assets.

**Reports batch (2026-11-17):** statement of changes in equity, cash flow statement (direct method; accounts carry a
cash flow section: operating, investing or financing, defaulting by type), income and expenditure by month, ledger
integrity check, aged payables, supplier statement, suppliers with balances, approved requests not yet vouchered,
approved vouchers not yet paid, collections summary (by month, method, bank, cashier or kind). Left for their steps:
asset reports and PPE note (step 6), imprest, bank reconciliation, net realisable value, multi-currency statements.
Not planned: government formats (GBT, Treasury Registrar), group consolidation/elimination, IFRS 17.

**Step 6 done (2026-11-17):** module `Assets` (replaces the unused legacy asset tables and permissions; `assets.view`,
`assets.manage`). Register with numbering pattern, one record per unit, type fields (vehicle, land, building, road,
software), acquisition by GRN (automatic, `GoodsAccepted`), donation, transfer in or opening balance (CSV upload of
drafts); `asset-register` workflow posts donations/opening values. Value history in `asset_entries` gives cost,
accumulated depreciation and book value at any date. Assign/return with holder history; transfers internal (permanent
or temporary with reminders) or external (derecognised), `asset-transfer` workflow. Changes (improvement, life review,
revaluation only under the revaluation policy, impairment), `asset-change` workflow, each restarting the depreciation
basis. Straight-line daily depreciation from in-use (or acquisition) date, monthly runs in order (automatic on the 1st,
failed runs kept with the reason), journal per category/department/fund; land, WIP and no-life assets never
depreciated. Disposal (sale, auction, donation, write-off, retirement) with board minutes, `asset-disposal` workflow,
catch-up depreciation and gain/loss. Self service My assets: fault reports with photo, hand back, yearly confirmation;
officers answer in Faults and returns; weekly alert for assets held by leavers. Reports: register at a date, PPE
movement note, depreciation schedule, by holder/location, due for disposal, disposals, transfers. Not yet: QR tag
printing and phone scan, WIP project capitalisation, foreign-currency cost, HR exit clearance blocking (alerts only).
Next: step 7 procurement advanced; fleet plan in §21.

**Step 7 done (2026-11-17):** configurable procurement methods (`procurement_methods`: sourcing, value limits, minimum
bidders, evaluation lowest / technical / combined with weight and pass mark, negotiation, highest-wins for disposals,
standstill days, reason needed; generic business defaults, no government list). Tenders from an approved requisition:
lots, criteria, document approval (`tender-document`), clarifications and amendments, bids sealed until opening (prices
encrypted and never returned before `opened_at`; late bids refused), committees with conflict-of-interest declarations
(requester and preparer cannot evaluate), opening record, scoring, evaluation report (`tender-evaluation`) with ranking
per lot, award per lot (`tender-award`, negotiated amount only where allowed), standstill with complaints (dismiss,
reopen, cancel), award letters and acceptance. Contracts from an accepted award: type and category (goods by call-off
orders; works/services/consultancy by certificates), vetting (`contract-vetting`), signing moves the requisition hold
to a contract commitment, call-off POs move commitment from contract to order (cancel gives it back), certificates
(`contract-certificate`) post Dr budget-line account / Cr clearing (net), retention, damages (capped), advance recovery
and raise one supplier bill; advance and retention release milestones; variations (`contract-variation`) limited to a
percentage with budget check; extensions; progress log; close/terminate releases the rest; balance = value +
variations − ordered or certified. Daily `procurement:daily` ends standstills and warns before contract end and
security expiry. Reports: tender register, bid opening record, evaluation summary, awards against estimate, contract
register, expiring contracts and securities, outstanding orders, supplier performance, spend by method, special-group
share. Not yet: annual procurement plan and packages, e-bidding by suppliers, escalation and handovers in the engine.

## 20. Kairuki Hospital accounting requirements — coverage (2026-11-16)

Source: "KH Account System requirements" (Kairuki Hospital). ✓ built · ◐ part · ✗ not yet.

| Area | Status | Notes |
|---|---|---|
| Chart of accounts, journals with approval, period close, year-end close, opening balances, trial balance, audit trail | ✓ | |
| Departmental / cost-centre tracking, budgeting and variance | ✓ | line dimensions department, fund, activity, budget line |
| Multi-currency, revaluation | ✗ | currency only on bank accounts |
| Multi-entity / branches | ◐ | multi-company and branches ✓; consolidation and intercompany ✗ |
| AP: vendor register, WHT, payment requests → vouchers → payment lists, bank file | ✓ | |
| AP: supplier invoices, 3-way match (PO, delivery, invoice), AP ageing, scheduled payments, cheque printing | ✗ | supplier bills come with procurement (step 3) |
| AR: customers, invoices, receipts with/without invoice, credit notes, ageing, statements | ✓ | |
| AR: credit control (limit blocking), payment plans, co-payment, insurance claim lifecycle, Afyabox patient billing | ✗ | credit limit stored, not enforced |
| Bank: several accounts, cash book | ✓ | |
| Bank reconciliation, cash flow forecast, petty cash / imprest, cashier-system link | ✗ | |
| Fixed assets: register, depreciation, transfer, disposal, revaluation, impairment | ✓ | maintenance schedule, QR scan ✗ |
| Inventory & pharmacy: catalogue, stores, batch/expiry, ledger, FIFO/weighted average, consumption, reorder alerts | ✓ | automatic purchase requisition from reorder ✗; LIFO not allowed under IFRS |
| Payroll: attendance link, allowances, cost per department, posting to GL, statutory reports | ✓ | gratuity / benefit accruals ✗ |
| Reports: income statement, financial position, cash book, fund statement, budget vs actual, dashboards, PDF/CSV | ✓ | cash flow statement ✗, departmental profitability ◐, service line and insurance-vs-cash trends ✗, custom dashboards ✗ |
| Tax: payroll statutory (PAYE, NSSF, NHIF, WCF, SDL), WHT | ✓ | VAT input/output and returns ✗ |
| Security: roles, permissions, multi-level workflow with amount steps, audit log | ✓ | default roles for auditor (read-only), AP clerk, AR clerk, cashier ✗ |
| Integration: CSV import (chart, items), CSV/PDF export | ✓ | APIs for Afyabox / HIS / lab / pharmacy / NHIF e-claims ✗; Excel/XML export ✗ |
| Web, responsive | ✓ | mobile app ✗; backups are a server task |

**Added to the plan from this document** (after procurement step 3, in this order): default finance roles (auditor
read-only, AP clerk, AR clerk, cashier); bank reconciliation; supplier invoices with 3-way match and AP ageing; VAT
(input/output accounts, VAT on invoices and bills, VAT return); cash flow statement (indirect, IFRS for SMEs); petty
cash and imprest with retirement; external system integration — generic, not vendor specific: any HIS/billing/lab/pharmacy/insurer
system (Afyabox is only the first connector) sends patient bills, receipts and insurance claims into Billing &
Receipts through the signed external API (claims: submitted, partly paid, rejected, resubmitted; departmental and
service-line revenue); recurring journals; multi-currency with revaluation; gratuity accrual; asset maintenance schedule (with step 5).

**Accounting framework (decided for discussion, 2026-11-16):** not IFRS only. A company setting `framework` = IFRS
(full) | IFRS for SMEs (default) | US GAAP | local framework. It drives: allowed stock costing methods (FIFO, weighted
average, specific identification; LIFO offered only under US GAAP or a local framework, with a warning that tax rules
differ by country), statement wording (company vs non-profit) and whether revaluation is allowed. Built with stock
costing in step 4. Recommendation to Kairuki: FIFO for medicines (also matches expiry control).

**Step 3 done (2026-11-16) — procurement core:** purchase requisition (lines from the catalogue, budget line per line,
normal or special with reason) → submit holds the estimate (`purchase-requisition` workflow: head of department, then
finance) → request for quotations with recorded quotations, comparison and award per line (minimum quotes from
settings or a written reason; `quotation-award` workflow drafts one purchase order per winner) or a direct order
(direct under the limit, single source or framework with reason) → purchase order (`purchase-order` workflow with
amount steps; approval releases the requisition hold for the ordered part and commits the order amount; PDF,
acknowledgement, cancel releases) → goods received note (delivered / accepted / rejected with reason, batch, expiry,
serials; `goods-receipt` inspection workflow) → on acceptance: journal Dr inventory / asset / expense (from the item
category) with the budget line, Cr goods-received clearing; the order commitment becomes spent; order partly or fully
delivered; exactly one supplier bill (payment request already approved, lines on the clearing account, payee = the
supplier's approved bank account) and the `GoodsAccepted` event (stores and assets listen in steps 4–5). 3-way match:
the supplier invoice recorded on the delivery must equal order price × accepted quantity (tolerance in settings)
before a voucher can be prepared; the voucher debits clearing (no second budget effect). Not yet: procurement plan and
packages, tenders, contracts, framework agreements (step 6).

### 18.7 Configurable accounting framework (design, 2026-11-16)

A company chooses a framework profile: IFRS | IFRS for SMEs (default) | US GAAP | IPSAS | Local / regulatory (named by
the company, every option open). A profile is data (`FrameworkCatalog`): for each policy the allowed values and the
default; the company may adjust policies only within what its profile allows. Code reads policies, never the profile
name, so new profiles are data only.

Policies: stock costing (FIFO, weighted average, specific identification; LIFO only US GAAP / local), stock write-down
(lower of cost and NRV; reversal not under US GAAP; IPSAS current replacement cost for items given away), asset
measurement (cost / revaluation; US GAAP cost only), impairment reversal (not under US GAAP), component depreciation
(required IFRS / IPSAS), cash flow method (direct / indirect), budget-vs-actual statement (required IPSAS 24 when the
budget is public), non-exchange revenue rules (IAS 20 / IPSAS 23 / ASC 958). Terminology per profile drives statement
titles (financial position vs balance sheet, profit or loss vs financial performance vs income and expenditure,
equity vs net assets vs fund balances). Regulatory returns are report packs added on top of any profile.

Safeguards: once there are postings the profile and policies change only from the start of a fiscal year; each change
is kept with its effective date (IAS 8); closed periods keep their policy; one framework per company (parallel
multi-GAAP books later if a group needs it). Hooks: step 4 stock costing and write-down, step 5 asset measurement,
impairment reversal and components, reports now (titles, IPSAS budget comparison), cash flow method when built,
grant/donation recognition in Billing & Receipts and fund sources.

### 18.8 Plan note: sectors, multi-book ledger and local compliance (2026-11-16)

Kept from the user's note; not built yet.
- **Feature packs, not sectors** (user, 2026-11-16): the sectors below are only examples. No company is tied to a sector; each
  company chooses its framework profile, its policies and which optional feature packs it switches on. Examples on top of
  the framework profiles (§18.7): banks — full IFRS / IFRS 9 (expected credit loss, effective
  interest, instrument classes) as a separate sub-ledger pack; public hospitals and schools — IPSAS accrual or cash, fund
  and grant (non-exchange revenue) tracking, budget-to-actual encumbrance; private hospitals and schools — IFRS or IFRS
  for SMEs with IFRS 15 revenue over time (tuition terms, multi-day inpatient stays) through deferred revenue schedules,
  and insurance claim write-offs.
- **Multi-book ledger:** replaces "one framework per company" in §18.7 as the target. A journal line belongs to a book
  (primary IFRS / IPSAS book; optional tax or local regulatory book); one operational transaction posts to every active
  book through posting rules, with book-specific adjustments only where policies differ. Today everything posts to the
  primary book; the book column is added before multi-book is needed so no data is migrated twice.
- **Segmented accounts:** keep the dimensions on journal lines (entity/branch, fund, department, natural account, cost
  centre, project/grant, activity, budget line) rather than encoding them in account codes; add project/grant as a
  first-class dimension for NGOs and schools.
- **Sub-ledger rule engines:** complex schedules (IFRS 16 leases, IFRS 9 impairment, IFRS 15 deferred revenue,
  depreciation) live in sub-ledgers that post automatic, traceable journals to the general ledger.
- **Period controls:** date-effective exchange rates, posted journals immutable (reversal only — already so), fiscal
  calendars per company (academic year for schools, calendar year for banks — already configurable).
- **Tanzania / East Africa:** NBAA-adopted IFRS and IPSAS; TRA rules (VAT, WHT, PAYE — PAYE and WHT built, VAT planned);
  EFD/VFD fiscal receipt integration for receipts and invoices; BoT regulatory returns for financial institutions
  (with the bank pack).

**§18.7 built (2026-11-16):** `FrameworkCatalog` (profiles, policies, allowed values, wording), `AccountingPolicies`
(policies in force on a date; change history `accounting_policy_changes` with effective date and reason; after the first
posting a change starts only at an open fiscal year), `accounting_settings.framework|framework_name|policies`, endpoint
`GET/PUT accounting/framework`, statements titled by the framework wording, report "Statement of comparison of budget and
actual amounts" (IPSAS 24 style), and `journal_entries.book` (default `primary`) ready for the multi-book ledger.

## 21. Fleet management — plan from the requirements (2026-11-17)

Source: "Fleet Management Requirements" (FLT, DRV, TRN, FUE, MNT, CMP, HIR, TRK, SS, INT). Adapted for private
institutions and NGOs: no government fleet formats; a national or contracted fuel supplier is one generic
external-system adapter (§18), not a built-in vendor. The legacy Blade-era `vehicles`, `fuel_records` and
`vehicle_maintenance_logs` tables (unused in production) are replaced, like the asset tables in step 6; module slug
`fleet` and permissions are reworked (view, manage, dispatch, fuel, maintenance, tracking, commands).

**Ownership:** fleet owns day-to-day use (vehicles, generators, drivers, trips, fuel, maintenance, compliance,
tracking). The asset register keeps cost, depreciation and disposal (a bought/donated vehicle links to its `assets`
record; borrowed and hired vehicles have none; disposing starts an asset disposal and the fleet record closes when it
is approved). Procurement buys repairs and fuel; accounting pays; budgets are checked and committed through
`BudgetService`; employees, departments and suppliers are read from HR and the supplier register, never copied.

**Design decisions:**
- Vehicles and generators share one register (`fleet_units`, kind vehicle | generator); generators use running hours
  in place of the odometer. Status operational | non_operational | off_limit | lent | disposed; only operational
  units can be dispatched or fuelled.
- Odometer/hours only move forward through logbooks, fuel issues, maintenance or tracking; corrections need approval
  and every reading is kept with its source (`fleet_readings`).
- Running cost ledger per unit (fuel, maintenance, hire, other) with the source document, for cost per km.
- Drivers: employee (HR link, leave status) or hired (supplier, contract dates); licences with classes and expiry
  (history kept); licence-class eligibility per vehicle type; one active driver per vehicle; assignments and hires
  close automatically on their end date.
- Transport requests and dispatch (with or without a request, cargo details), availability check that blocks
  double-booking of a vehicle or driver, postpone/return/reject, agency trips (contracts with modes and validity)
  gathered into payment batches that create one payment request each; logbook per journey confirmed by a supervisor,
  filled from the tracked trip when a device is fitted.
- Fuel: requisition checked against tank size, distance since last fill and average use (outliers flagged); fuel
  stores in litres or money with committed/available (approve = commit, issue = used, short supply returned); recharge
  = purchase creating a supplier payment request; issue from own store (linked to the Inventory store ledger), depot or
  card; external supplier adapter with queued retries, status on the request and wallet reconciliation.
- Maintenance: preventive schedules per vehicle type / generator model (km, hours or months, whichever first) with
  service-due warnings and automatic requests; corrective requests; inspection with priced lines; the approved
  inspection creates a procurement requisition and follows the order back; post-inspection returns the unit to
  operational and adds the cost. In repair = non_operational.
- Compliance: accidents (photos, police report, repairable → corrective maintenance, not → disposal), insurance (one
  current policy), compliance calendar for road licence, insurance, inspection certificate and driving licence with
  reminders (e.g. 30/14/7 days, overdue) and renewal; dispatch blocked on expired items unless overridden with a reason.
  Insurers, workshops and inspectors live in the supplier register.
- Hire in: supplier, vehicle type, count, seats, driver included, dates, rate, currency, contract; usable in dispatch
  and tracking while active; early end with date and note.
- Every approval through the workflow engine (transport request, dispatch, fuel request, maintenance request,
  inspection, accident, hire, odometer correction); requester ≠ approver for fuel and maintenance; handovers move
  pending tasks (engine feature also needed by procurement).
- Self service: employees request transport (and fuel for the trip) and follow it; assigned-vehicle holders see
  status, service due and compliance; driver views (assigned vehicles and trips, start/end logbook, request fuel,
  report fault or accident) on the same API so a later phone app reuses it.

**GPS tracking (separate service):** a tracking gateway outside Laravel (TCP/UDP and MQTT listeners, decoders per
device model configured as profiles and parameter dictionaries, acknowledge only after storing) writes positions and
events to a time-series store and pushes live updates over WebSocket (Laravel Reverb or the gateway's own socket).
Laravel keeps devices, models, geofences, routes, commands (risk level, confirmation and reason for high-risk ones,
every command and reply logged), trips (built from positions), alerts, offline list (heartbeat) and reports (distance,
speeding, engine time, behaviour score, power and signal, time offline); logbook vs tracked distance difference above
a set percentage is flagged. Position history kept for a configurable period (e.g. 2 years), trips longer. Times
stored in UTC. Map on the frontend with street/satellite/terrain basemaps, live map coloured by state, trip replay,
geofence and route editors. Tracker models are decided with the customer (open question).

**Build order:**
1. Register and drivers: vehicles and generators (asset link), odometer/hours history, statuses, drivers and licences,
   eligibility, assignments with auto-close, insurance and compliance calendar with reminders, cost ledger.
2. Transport: requests, availability and allocation, dispatch (cargo), logbooks, agency contracts and payment batches,
   self-service transport requests.
3. Fuel: requisitions with checks, fuel stores and recharges, issue/confirm/return, budget commitment, consumption
   reports; external supplier adapter and wallet reconciliation when a customer needs it.
4. Maintenance: schedules and service-due, requests, inspections → procurement requisition → post-inspection,
   accidents, generator maintenance.
5. Hire in, fleet dashboard and reports (register, cost per vehicle and per km, fuel consumption and outliers, fuel
   store movement, transport requests, utilisation, maintenance history, accidents, compliance).
6. Tracking: gateway service, live map, trips, geofences, routes, events, commands, tracking reports, logbook fill.
7. Driver phone app on the same API (later), with push notifications.

**Open questions (to confirm with each customer):** tracker models at go-live; fuel via national supplier, cards, own
tanks or a mix; whether private trips are charged to the employee (and how: payroll deduction or receipt); who may
send an immobilise command and when.
