# Changelog

All notable changes to this project will be documented in this file.

## [1.0.5] - 2026-04-13

### Added
- **Meeting view — full-item Quick edit modal**: each sprint item row now has a pencil button that opens a Bootstrap modal to edit name, status, priority, owner, story points, capacity (%) and note in one place, save via AJAX, and stay on the meeting page. Capacity and right checks run exactly like a normal edit, and any validation errors (e.g. overallocation) are surfaced inline in the modal
- **Meeting view — sortable columns**: clicking the "Treated", "Status" or "Owner" column headers in the meeting review table sorts the rows asc/desc, independently for the Fastlane and Regular sections
- **Sprint dashboard — "In Review" stats card**: a new purple card between *In Progress* and *Blocked* shows the number of items currently in review, and the progress bar legend now includes the In Review percentage computed from the actual count
- **Sprint Members tab — per-member status distribution**: the team members listing replaces the old *Linked Items* and *Comment* columns with a compact version of the dashboard stats bar — count pills per status (Done / In Progress / In Review / Blocked / To Do) plus a stacked mini progress bar — so you can see everyone's current sprint load at a glance

### Changed
- **Reverse *Sprints* tab on Tickets / Changes / Project Tasks** is now backed by `SprintItem` directly instead of the legacy `SprintTicket` / `SprintChange` / `SprintProjectTask` relation tables. Items added via the Sprint → Sprint Items form are now visible (previously only items added via the reverse tab showed up). Unlinking from the reverse tab purges the `SprintItem` row and cascades to any legacy relation row

### Fixed
- **Duplicate linked items**: the same Ticket / Change / Project Task can no longer be linked to a sprint twice. A real error message is shown (instead of silently creating a second row) whether the duplicate is attempted via the Sprint side, the reverse tab on the linked item, direct SprintItem edits, or the SprintItem update path. Manual items are unaffected
- **Meeting save — note loss when ticking Treated**: adding a note and ticking the "Treated" checkbox in the same save previously dropped the note because `prepareInputForUpdate()` skipped any item row flagged as treated. The backend now always persists submitted values; the treated checkbox is purely a UX lock
- **Quick edit modal — CSRF 403 on save**: GLPI 11 CSRF tokens are single-use, so reusing the meeting form's hidden token produced a 403 once it had already been consumed elsewhere on the page. The modal now fetches a fresh token from a new `ajax/csrftoken.php` helper endpoint before every save

## [1.0.4] - 2026-04-10

### Added
- **Meeting view — Type column**: sprint items in the meeting review table now show a type icon (Ticket, Change, Project task, Manual) matching the dashboard display
- **Meeting view — Linked item project name**: linked Project Tasks in the meeting review now include the parent project name in parentheses (via `getLinkedItemDisplay()`), consistent with the dashboard and sprint items tab
- **Meeting view — Fastlane / Regular split**: the meeting sprint items review is now split into two sections — a dedicated Fastlane block (orange header with bolt icon) above the regular Sprint Items Review — matching the dashboard layout
- **Back to backlog button**: sprint items in the meeting view and sprint items tab now have a "Back to backlog" button (undo icon) that moves the item back to the backlog with a confirmation dialog, useful during sprint kick-offs when items are reconsidered. Moving back also clears the fastlane flag
- **Dashboard — Fastlane above regular items**: the Fastlane section on the sprint dashboard is now rendered between the stats/progress bar and the regular items table (previously it was below the regular items)
- New translations for "Back to backlog", "Move this item back to the backlog?", and "Item moved back to backlog" in all supported languages (en, nl, fr, es)

### Fixed
- **Meeting save redirect**: clicking "Save" on a meeting no longer redirects to an empty form (`id=0`); it now explicitly redirects back to the meeting detail page
- **Meeting back-to-backlog nested form**: the backlog button inside the meeting form was rendered as a nested `<form>` (invalid HTML), causing the browser to submit the parent meeting form instead. Replaced with a JavaScript-driven approach that dynamically creates and submits a standalone form outside the parent

## [1.0.3] - 2026-04-09

### Added
- **Fastlane**: backlog items can now be flagged as fastlane via a new "Is Fastlane" toggle column in the backlog. When assigned to a sprint, fastlane items appear in a dedicated **Fastlane** tab on the sprint (mirroring the Sprint Items tab) instead of being mixed in with regular sprint items
- **Multiple member assignment per fastlane item**: opening a fastlane entry shows a "Fastlane Members" tab where multiple sprint members can be linked, each with their own assigned capacity %. Capacity is validated against the member's remaining sprint capacity (regular + fastlane combined)
- **Dashboard – Fastlane section**: between the regular sprint items table and the team capacity table, the dashboard now lists all fastlane items with status, members and total capacity, so the team can immediately see how much sprint effort is going to fastlane work
- **Dashboard – Fastlane category**: Team Capacity and Your Capacity tables now break used capacity down into Regular and Fastlane columns and show the sprint-level fastlane total in the section header. The capacity bar is stacked (regular = red, fastlane = orange) for an at-a-glance view
- **Granular capacity dropdown**: capacity selectors throughout the plugin (sprint members, sprint items, fastlane allocations, sprint template members) now expose values 1, 2, 3, 4, 5 then 10, 15, 20, …, 100 % so very small allocations can be expressed
- **Mobile responsive layout**: every plugin table is automatically wrapped in a horizontally scrollable container, the Backlog filter bar collapses cleanly on small screens, and stat cards / Kanban columns / inline forms reflow for phone-sized viewports. A targeted CSS override neutralises the GLPI 11 Tabler theme rule that was unstacking table cells vertically on narrow widths, so plugin pages keep their semantic table layout instead of cropping
- New translations for the Fastlane feature in all supported languages (en, nl, fr, es)

### Changed
- **Template meeting scheduling — end-of-sprint snap direction**: with `skip_weekends` enabled, ceremonies scheduled as `last_day` or `day_before_end` now snap *backwards* to Friday when the calculated date lands on a weekend. Previously they snapped forward to Monday — which for a Mon→Sun sprint meant the review/retrospective ended up on the kickoff day of the *next* sprint
- **Template meeting scheduling — standup vs ceremony collision**: recurring (`interval`) meetings are now silently dropped when they would land on the same calendar day as a fixed ceremony (kickoff / review / retrospective / day_before_end). No more redundant standup on the day you're already running the retrospective
- **Template meeting scheduling — sprint-window guarantee**: `SprintTemplateMeeting::calculateMeetingDates()` now post-filters every produced date against `[date_start, date_end]`. Any schedule strategy (existing or future) that drifts outside the sprint window is dropped centrally, so no generated meeting can ever fall before the sprint starts or after it ends

### Fixed
- **Backlog menu highlight**: clicking *Backlog* in the helpdesk menu group no longer leaves *SprintManager* visually selected. `front/backlog.php` was passing `GlpiPlugin\Sprint\Sprint` as the active menu key to `Html::header()` instead of `GlpiPlugin\Sprint\Backlog`

### Database
- Added `is_fastlane` column to `glpi_plugin_sprint_sprintitems`
- New table `glpi_plugin_sprint_sprintfastlanemembers` (junction linking fastlane sprint items to sprint members with assigned capacity)

## [1.0.2] - 2026-04-08

### Added
- **Sprint Backlog**: a dedicated page accessible from the Assistance menu, listing all Sprint Items that are not yet assigned to a sprint (`plugin_sprint_sprints_id = 0`)
- **1-click "Add to backlog"** button on the Sprints tab of every Ticket, Change, and Project Task — instantly creates a backlog item with a deduplication check so the same linked item cannot end up in the backlog twice
- **Inline "Assign to sprint" dropdown** per backlog row: pick a Planned/Active sprint and click Assign to move the item out of the backlog and into that sprint in one action
- **Backlog filter bar**: free-text search on the item name, type filter (All / Ticket / Change / Project task / Manual), and sort options (Priority, Name, Newest first, Oldest first). Filter state is captured in the URL so the page is shareable and bookmarkable
- **Parent project name** is now appended in parentheses next to linked Project Tasks throughout the plugin (sprint item lists, dashboard, backlog) so tasks with identical names across projects can be told apart
- New translations for the Backlog feature and filter bar in all supported languages (en, nl, fr, es)

### Changed
- Sprint template form: removed the manually-declared `is_active` and `comment` fields from the Twig template — they were duplicates of the fields auto-rendered by GLPI's `generic_show_form.html.twig` parent template, and the duplicate POST input was overwriting the user's value with an empty string on save
- Sprint template -> sprint creation: the template's `comment` (description) is now copied to the new sprint, both via the JavaScript pre-fill in the sprint form and as a fallback inside `SprintTemplate::applyToSprint()` when the sprint's own field is still empty

### Fixed
- Status badges in the Sprint Dashboard items table were rendered with white text but no inline background — when GLPI's theme stylesheets reset the plugin's CSS custom properties, the badges became invisible (white-on-white). Both the inline styles and the `sprint.css` rules now use explicit hex fallbacks alongside `var(...)` and `!important` so badges stay readable in any theme context

## [1.0.1] - 2026-04-07

### Added
- Personal View on the sprint dashboard: toggle between a global team overview and a personal view showing only items assigned to you, with filtered stats, progress bar, and personal capacity display
- Linking a Ticket, Change, or Project Task to a sprint via the reverse tab now automatically creates a corresponding Sprint Item so it appears on the dashboard and in sprint statistics
- Unlinking removes the corresponding Sprint Item automatically
- New translations for Global View, Personal View, Your Capacity, and No items assigned to you in all supported languages (en, nl, fr, es)

### Fixed
- Fixed CSRF token failure on Project Task AJAX dropdown by replacing single-use CSRF check with session login check on the read-only `getprojecttasks.php` endpoint
- Fixed relative include paths to use `dirname(__DIR__, 3)` for Symfony routing compatibility in GLPI 11
- Fixed `Session::checkCSRFToken()` calls replaced with correct `Session::checkCSRF($_POST)`
- Fixed profile rights form posting to dedicated plugin handler instead of GLPI's built-in `profile.form.php` which silently ignored custom rights fields
- Fixed element name for form submit to GLPI

## [1.0.0] - 2026-04-03

### Initial Release

#### Sprint Management
- Create sprints with configurable duration, goals, status, and sprint numbers
- Scrum Master field required, searches all GLPI users
- Sprint backlog with story points, priority, status, and capacity per item
- Link existing GLPI Tickets, Changes, and Project Tasks via searchable AJAX dropdowns
- Project Task cascading dropdown (select project first, then task)
- Dashboard with stats cards, progress bar, items overview, and team capacity visualization
- After creating a sprint, user is redirected to the new sprint

#### Team Members
- Assign members with roles (Scrum Master, Product Owner, Developer, Tester, Designer, DevOps, Analyst)
- Capacity percentages per member with visual overload detection and validation

#### Meetings
- Schedule kickoffs, standups, reviews, and retrospectives with required facilitator
- Interactive standup review: update item status, owner, and notes during meetings
- Treated checkbox to mark discussed items (greyed out and locked)
- Persistent notes per sprint item across meetings

#### Sprint Templates
- Pre-define team members, backlog items, and meeting schedules
- Meeting schedule types: first day, last day, day before end, recurring interval
- Skip weekends option: meetings on Saturday/Sunday automatically move to Monday
- Optional flag per ceremony
- Save as Template: convert any existing sprint into a reusable template with smart meeting pattern detection
- Create from template: select a template when creating a sprint; members, items, and meetings are auto-generated

#### Role-Based Access Control (RBAC)
- Sprint management right: create/edit/delete sprints, templates, members, and meetings
- Sprint items right: manage backlog items with standard CRUD permissions
- Manage own items only right: users can create items and edit/delete only items assigned to them
- Granular per-row permission checks in sprint item lists
- Profile tab with grouped layout and clear section dividers per right category

#### GLPI Integration
- Full rights management with profile-based permissions
- Entity support and recursive rights
- History tracking on all entities
- Reverse tabs on Tickets, Changes, and Project Tasks
- Static assets in `public/` for GLPI 11, fallback to `css/`/`js/` for GLPI 10
- Twig templates for GLPI 11 with PHP fallback for GLPI 10

#### Multi-language Support
- English (en_GB)
- Nederlands (nl_NL)
- Fran&ccedil;ais (fr_FR)
- Espa&ntilde;ol (es_ES)
