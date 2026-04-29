# Changelog

All notable changes to this project will be documented in this file.

## [1.0.9] - 2026-04-29

### Added
- **Backlog visibility on Change and Project Task tabs**: changes and project tasks that live on the backlog (sprint id 0) now show a dedicated "On backlog" section on the Sprints tab — same layout as the existing Ticket implementation: backlog item name, fastlane / blocked flags, and a shortcut to open the backlog page
- **"Unassigned only" filter option**: meeting items review (kick-off, standup, review, retrospective) and the shared sprint-item filter bar gain an "Unassigned only" choice in the Owner dropdown, so during a kick-off the team can focus on items that still need an owner
- **Team activity always visible**: the team-activity chart on the sprint dashboard now always renders its section header. When the chart cannot plot anything, a clear in-place message explains why (no team members, sprint outside the 14-day retention window, sprint not started yet, no audit-log activity yet) — previously the section silently disappeared
- **AJAX quick-assign from backlog**: the per-row "Toewijzen" flow on the backlog no longer triggers a full page reload. Picking a sprint and clicking the assign button posts to a new `ajax/assigntosprint.php` endpoint; on success the row fades out in place and a toast confirms the assignment. CSP-safe (delegated submit handler, no inline JS). The form-based POST stays as a no-JS fallback
- **End-of-sprint export report**: a new **Export report** tab in the sprint rail (registered directly under Audit log) renders a printer-friendly view with the full sprint summary (status mix, story points), workload per member (regular vs fastlane capacity, used vs free), a dedicated **Fastlane** section listing every fastlane item with status, story points, per-user allocations and a per-member fastlane-capacity summary so interrupt work can be reviewed separately, the team activity chart and the full item breakdown. The header tries to embed the right logo by checking, in order, the new **Report logo URL** plugin setting, GLPI's configured central logo, and a logo URL extracted from the active entity's custom CSS (so instances that brand the sidebar via custom CSS instead of `central_logo` still get their own logo on the report). When nothing matches the `<img>` is omitted instead of falling back to GLPI's bundled default. The tab toolbar offers a "Print / Save as PDF" button that hands off to the browser's native print pipeline — no PDF library dependency, output stays consistent with the on-screen view, and `@media print` rules strip GLPI's chrome (header, sidebar, breadcrumbs, the tab navigation strip itself) so the printed page is clean. A standalone `front/sprint.export.php?id=<id>` page is still available for direct links / bookmarks
- **Fastlane allocations visible in meeting review**: fastlane rows in the meeting items review (kick-off, standup, review, retrospective) now list every member that's allocated on the item with their capacity %, plus a per-item total — matching what the dashboard fastlane block shows. The quick-edit modal on a fastlane row hides the single-owner dropdown and shows the same allocation list with a "Manage fastlane members" shortcut to the item's fastlane tab; a row-level fastlane-members button is also rendered alongside the existing actions. Previously the meeting view used the (non-authoritative) `users_id` field and showed "Unassigned" for any item with multiple allocations

### Fixed
- **Duplicate sprint items prevented**: the duplicate-link guard now also runs when only the sprint id is being changed (e.g. assigning a backlog item to a sprint), and a SprintItem move into a real sprint deletes any leftover backlog row for the same Ticket / Change / Project Task. A one-time install/upgrade cleanup deduplicates existing data: same-sprint duplicate rows are collapsed (lowest id wins), and backlog rows for items already in any sprint are removed. Fixes cases where an item could appear twice in the same sprint and stay on the backlog after being linked
- **"Add to backlog" hidden when item is already in a sprint**: the button on a Ticket / Change / Project Task is suppressed (replaced by a hint to use "Carry over to sprint") when the item already lives in any sprint, and the server endpoint refuses the action as a fallback. Backlog and sprint membership are mutually exclusive — looping items between sprints should go through "Carry over to sprint"

## [1.0.8] - 2026-04-28

### Added
- **Backlog — "Is Blocked" flag**: backlog items can now be flagged as blocked via a new "Is Blocked" toggle column in the backlog (mirroring the existing "Is Fastlane" column). Database column `is_blocked` (TINYINT, indexed) added to `glpi_plugin_sprint_sprintitems` via migration. Toggleable inline from the backlog list and from the SprintItem full form. Surfaced in `rawSearchOptions()` so changes appear in the audit log
- **Backlog — dedicated Blocked section**: a separate collapsible section is rendered above the main backlog list showing every blocked item, so the Scrum Master can review and unblock them periodically. Default state is **expanded** for visibility; collapsed/expanded state is persisted per user in `localStorage`. Section is hidden from the main list to avoid duplication
- **Backlog — Blocked filter**: the filter bar gains a "Blocked" dropdown (All / Only blocked / Hide blocked) so the main list can be scoped at-a-glance for review
- **Ticket / sprint reverse tab — backlog visibility**: tickets that live on the backlog (sprint id 0) now show a dedicated "On backlog" section on the Sprints tab with the backlog item name, fastlane / blocked flags, and a shortcut to open the backlog page. The tab counter now also includes backlog rows, so backlog presence is visible without opening the tab
- **Sprint review — carry-over dropdown**: each item row in the sprint review (meeting form, both fastlane and regular sections) gets a green "carry over" dropdown that lists every other planned/active sprint (sorted by start date, with date range and status). Picking one creates a fresh copy of the item in the target sprint while leaving the source row in the current sprint, so items that didn't finish remain visible in the review and continue in the next sprint as a new planning entry. Status of the new copy resets to "todo", capacity to 0, blocked flag and note are cleared; name, description, linked GLPI item, owner, priority, story points and fastlane flag are mirrored. Existing duplicate rows in the target sprint are detected and reused instead of cloned. Wired through a new `carry_over_to_sprint` POST action in `backlog.form.php` and a new `SprintItem::carryOverTo()` helper
- **Quick-edit modal — carry-over dropdown everywhere**: the same carry-over picker is rendered inside every quick-edit modal (Dashboard, Sprint Items tab, Meeting fastlane + regular) so reviewers don't have to hunt for the per-row dropdown. Default is "Do not carry over", so a normal save only updates the editable fields. When a target sprint is picked, the AJAX endpoint (`ajax/updateitemquick.php`) updates the source's editable fields and creates the carry-over copy in one round-trip; a toast confirms the carry-over without a page reload

## [1.0.7] - 2026-04-22

### Added
- **"Not done" status filter**: shared filter bar on Dashboard (global + personal), Sprint Items tab and Meeting tab (fastlane + regular) gains a "Not done" option in the Status dropdown — shows everything *except* items with status `done`, so reviewers can focus on outstanding work in one click
- **Collapsible dashboard sections**: the Fastlane block and the Sprint Items block on the sprint dashboard can now be collapsed or expanded individually via a clickable header (chevron + icon + title + item count). Default state is expanded; collapsed/expanded state is persisted per sprint + view (global vs personal) in `localStorage`, so reopening the dashboard respects your preference
- **Quick-edit linked item**: a small ✎ button now appears next to the linked Ticket / Change / Project Task name on every sprint view (Dashboard global + personal, Sprint Items tab, Meeting fastlane + regular). Opens a modal to update the source item without leaving SprintManager:
  - **Ticket**: status
  - **Change**: status
  - **Project Task**: status (ProjectState) + percent done
  Rights are delegated to GLPI's own ACL via `$item->canUpdateItem()` — the button is hidden when the current user isn't allowed to update the source item. Writes go through `$item->update()` so GLPI's history, notifications, and business rules fire normally. New AJAX endpoint: `ajax/updatelinkedquick.php` (CSRF-protected, itemtype whitelist)
- **Team activity chart**: new section on the global dashboard (placed directly under the stats tiles) showing an inline SVG line chart with one line per sprint member. X-axis = days (sprint window, clamped to the 14-day audit-log retention), Y-axis = count of audit-log events per member per day. Data comes exclusively from real field / relation mutations in `glpi_logs` — view events aren't logged and therefore don't count. Members with zero activity are omitted; most-active legend-first; hover dots show `name — date: count`. Collapsible, state persisted in `localStorage`

### Changed
- **Dashboard "Linked item" column now renders via `SprintItem::getLinkedItemDisplay()`** for the regular items table as well (previously built inline). This ensures the ✎ quick-edit button and the ProjectTask parent-project suffix appear consistently on the dashboard, sprint items tab, meeting review and fastlane blocks

## [1.0.6] - 2026-04-17

### Added
- **Plugin settings page**: Setup > General > SprintManager, the wrench icon in the Plugins list, or the SprintManager menu → **Settings** — toggle "Only Scrum Master can edit capacity on sprint items". When enabled, only the sprint's Scrum Master may change the capacity % on regular sprint items; **fastlane capacity stays editable for every sprint member** (allocated via the Fastlane Members junction). Enforced server-side in `SprintItem::prepareInputForUpdate`, `ajax/updateitemquick.php`, and surfaced in the quick-edit modal (capacity select disabled for non-SMs)
- **Scrum Master reassignment lock**: once a sprint has an assigned Scrum Master, only that user can reassign the role. Attempted changes by other members are reverted with an error message, and the sprint form renders the field read-only for non-SMs
- **Quick-edit button everywhere**: Dashboard (global + personal), Sprint Items tab, and Meeting tab (fastlane + regular) all expose the same pencil button. Opens a modal to edit name, status, priority, owner, story points, capacity (%) and note in one place; saves via AJAX; row cells refresh in place without a page reload (every changed cell updates: name, status badge, priority, owner, story points, capacity)
- **Shared filter + sort bar** on Dashboard (global + personal), Sprint Items tab, Meeting tab (fastlane + regular) and Audit log: text search + Status dropdown + Owner dropdown + Reset. Selecting a value auto-applies the filter — no separate Apply click needed. Clickable column headers toggle asc/desc sort
- **Sprint Members tab — team dashboard**: per-member cards on top showing sprint progress % (done / total items, including fastlane items the member is allocated on), stacked capacity usage bar (regular + fastlane segments), fastlane-item count badge, and status distribution pills. A simplified table below handles role / capacity / actions edits
- **Audit log tab** on each sprint: chronological view aggregating every change to the sprint, its items, members, meetings, and fastlane allocations. Shows timestamp, color-coded area badge, affected item with link, action verb ("Modified: Capacity (%)", "Created", "Purged", …), old → new diff, and the acting GLPI user. Uses GLPI's native `glpi_logs` table — no new tables written. SprintItem gains a full `rawSearchOptions()` map so every tracked field (status, priority, story_points, capacity, users_id, note, is_fastlane, itemtype) resolves to a proper field label in the log
- **14-day audit retention**: entries older than 14 days are hidden from the audit view and purged by a nightly GLPI cron (`SprintAudit::AuditCleanup`, registered at install), plus an opportunistic prune on every audit-tab open. Prevents unbounded growth of `glpi_logs`
- **"Configure" wrench icon** in the Plugins list via `$PLUGIN_HOOKS['config_page']` — clicks jump straight to the settings page

### Changed
- **Meeting tab — Status / Owner / Note are now read-only** in the review tables. All edits go through Quick Edit. Note renders as a wider, scrollable text block; the quick-edit modal's note textarea is now 8 rows / 180 px min-height
- **Meeting tab — Treated column removed**: the "treated" checkbox and row-greying behavior are gone. Back-to-backlog stays available at all times
- **Sprint Items list — full-edit link removed**: the pen-to-square button has been retired. Use Quick Edit for small changes; click the item name to open the full form
- **Fastlane tab — Quick edit removed**: fastlane items edit via the full form (the Fastlane Members tab controls allocation)
- **Fastlane items — no story points field**: story points on fastlane items don't count toward sprint velocity, so the input is hidden on the full form and in the quick-edit modal. `Sprint::getSprintStats()` no longer counts fastlane points toward total / done
- **Plugin menu** exposes a "Settings" option so the config page is reachable without hunting through Setup > General
- **Filter rendering** toggles a dedicated `.sprint-row-hidden` class (CSS `display: none !important`) and also sets inline `display: none`, overriding GLPI row-helper classes like `tab_bg_1` that otherwise force the row visible. Target-table lookup always walks the DOM from the filter bar's own subtree — immune to GLPI leaving stale duplicate tab HTML in the DOM after tab switches
- **Filter event wiring** uses three redundant layers (capture-phase document listeners + jQuery bubbling delegation + per-bar `addEventListener` via a `MutationObserver`), so the filter responds regardless of how a given tab is rendered or when content is injected via AJAX
- **Live refresh after Quick Edit** now updates every changed cell in place (name, status badge + color, priority, owner, story points, capacity). No browser reload needed; data attributes stay in sync for subsequent filter / sort operations
- **Audit DB access** uses GLPI's DB criteria array (`SELECT`/`FROM`/`WHERE`/`ORDER`/`LIMIT`) instead of a raw SQL string, as required by GLPI 11. Acting user is parsed from `glpi_logs.user_name` (format `"name (id)"`) and resolved through `getUserName()` so renames reflect automatically

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
