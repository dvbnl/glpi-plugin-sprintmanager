# Changelog

All notable changes to this project will be documented in this file.

## [1.1.4] - 2026-07-09

### Added
- **Personal view counts dependencies assigned *to* you**: the dashboard's Dependency stat card in Personal view now also counts sprint items where you are the helper on an **open dependency** (assigned by someone else) — previously it only counted items you own that sit in *Dependency* status, so obligations put on you by a colleague were invisible in the stat bar
- **Burndown blocked-share overlay**: blocked story points never burn, so the actual line could never reach the ideal line. The burndown now draws a dashed teal **"Remaining excl. blocked"** line plus a red-shaded band for the blocked share per day, so you can see whether the team is on track for the work it can actually influence. Day tooltips include the blocked point count
- **Per-sprint fastlane capacity cap with overflow**: a new **Fastlane capacity cap (%)** field on the sprint form (0 = no cap, new `fastlane_capacity` column + migration). The dashboard's Fastlane section and the export report show the allocated total against the cap (`X% / Y%`) and flag any excess with a red **"+N% overflow"** badge — display-only, allocations are not blocked
- **Backlog capacity → story points setting**: new plugin setting (Setup > General > SprintManager, default **off**). When enabled, assigning a backlog item with an estimated capacity % to a sprint automatically seeds its story points from that capacity (1% = 1 story point). Applies on every assignment path (single assign, bulk assign, form edit); items that already carry an explicit story-point estimate are never overwritten
- **Dependencies in the sprint export**: the end-of-sprint report gains a dedicated **Dependencies** section (owner, status, helpers with resolved ones struck through, open capacity total) and the CSV export appends a per-helper dependency block (item, owner, helper, capacity %, resolved, comment, creation date) — both were missing entirely

### Changed
- **Compact, modernised dashboard stat cards**: the oversized pastel stat blocks are replaced by compact horizontal chips — tinted icon square, value + label stack, per-status accent border — styled via CSS classes (theme-aware through Tabler tokens, dark-mode friendly) instead of inline styles. All seven cards now fit a single row on desktop

### Security
- **XSS**: removed the unescaped `$_SERVER['PHP_SELF']` echo (the affected legacy `front/sprint.fromtemplate.php` page was dead code and has been deleted)
- **Entity restriction on carry-over**: `ajax/carryover.php` and the carry-over branch of `ajax/updateitemquick.php` now verify `Session::haveAccessToEntity()` on the target sprint, matching every other sprint-loading handler, so items can no longer be injected into sprints in inaccessible entities
- **Own-items right scoped in reorder**: `ajax/reorder.php` no longer lets users holding only the *own items* right reorder backlog rows they don't own
- **Open redirect closed**: `front/backlog.form.php` only follows same-site relative `_redirect` targets; absolute/protocol-relative URLs fall back to the referer
- **Report logo containment**: the export's logo embedding refuses files outside the GLPI install, so a config-supplied absolute path can no longer leak arbitrary server files into the report

### Removed
- **Dead code cleanup**: deleted the orphaned `front/sprint.fromtemplate.php` page and `ajax/sprintstats.php` endpoint, the unused `SprintStandup::showLogForSprint()` and `SprintDashboard::parseActivityRangeFromRequest()` methods, five never-called JS globals (`sprintUpdateItemStatus`, `sprintToggleItemType`, `sprintLoadDashboard`, `sprintFilterApply`, `sprintFilterReset`) plus the legacy JS dashboard renderer, and the entire unused legacy CSS block (old stat-card component, JS progress bar, capacity/member/schedule helpers). `Profile::uninstallRights()` is now actually called on uninstall so profile rights no longer linger after removing the plugin

### Internationalisation
- Added translations for all 1.1.4 strings to the `en_GB`, `nl_NL`, `fr_FR` and `es_ES` catalogs (and the `sprint.pot` template), and recompiled the `.mo` files

## [1.1.3] - 2026-06-15

### Added
- **Kanban board tab on every sprint**: a new **Board** tab groups the sprint's items into columns (To-do, In Progress, In Review, Dependency, Blocked, Done) with compact cards (owner, story points, capacity, tags, dependency badge, fastlane bolt, linked item). Cards are **drag-and-drop** between columns to change status — native HTML5 DnD (no extra libraries), persisted through `ajax/updatestatus.php`, which keeps the same per-item permission (you can only move your own items unless you have full update rights; an unauthorized move reverts with a toast). Dark-mode-friendly via Tabler theme tokens. New `GlpiPlugin\Sprint\SprintBoard` virtual tab
- **Burndown chart on the dashboard**: an inline-SVG burndown (no Chart.js dependency, matching the activity chart) plots **remaining story points** per day against the **ideal line** across the sprint window. Actual remaining is reconstructed from the audit log (`SprintAudit::getItemStatusAtTimestamp()` — an item counts as remaining until its status is Done at end-of-day); future days have no data point. Shows helpful empty states when dates or story points are missing
- **Sprint header bar on every tab**: a shared context bar at the top of the Dashboard, Board, Items and Fastlane tabs shows the sprint's status, name, date range, **days remaining**, **goal**, an item-progress bar, and a **sprint switcher** (jump straight to another planned/active sprint, preserving the current tab). `Sprint::renderHeaderBar()`
- **"At risk" dashboard widget**: one collapsible panel listing everything that threatens the sprint — blocked items, items in Review/Done whose linked ticket/change is still open, and items with open cross-member dependencies — each with reason chips (or an "all clear" when empty)
- **Velocity chart on the dashboard**: inline-SVG bar chart of completed story points across recent completed sprints, with an average line, to help forecast capacity
- **Kanban card quick-edit + soft WIP warning**: board cards get a pencil that opens the shared quick-edit modal (status/owner/capacity/etc. without leaving the board), and the "In Progress" column flags a soft WIP warning when it holds more items than the team has members
- **Kick-off bulk assign**: an "Assign all ready (N)" button on the backlog assigns every item that has a pre-selected sprint in one click (only those the user is Scrum Master of), and each row shows a **Ready** badge once owner + capacity + sprint are all set. New `ajax/bulkassign.php`
- **Drag-to-reorder the backlog**: a grip handle per row lets you drag backlog items into a manual order, persisted to `sort_order` (new `ajax/reorder.php`); un-ordered items keep falling back to priority/date so existing backlogs look unchanged
- **Unfinished-items warning on sprint completion**: marking a sprint **Completed** now posts a warning with the count of unfinished items, reminding the team to carry them over or send them back to the backlog (non-destructive — nothing is moved automatically)
- **Pre-select a sprint on the backlog (Scrum Master assigns)**: backlog rows now persist a *proposed* sprint (new `proposed_sprints_id` column) next to owner and estimated capacity, so during kick-off members can fully prepare an item — owner, capacity, **and** target sprint — themselves. The final move into the sprint is gated: only the **Scrum Master of the selected sprint** (matched on `Sprint.users_id`) or a user with the full `plugin_sprint_item` UPDATE right may press **Assign**. `ajax/assigntosprint.php` (and the no-JS `front/backlog.form.php` fallback) enforce this server-side with a `Config::isCurrentUserScrumMaster()` lookup against the target sprint and clear `proposed_sprints_id` on assignment. The proposed sprint persists through `ajax/updateitemquick.php` like the other inline fields
- **Add dependencies straight from the backlog**: each backlog row gains a "link" button opening a dependency modal whose member list is limited to people **already in the pre-selected sprint** — fetched live for whatever sprint is chosen via the new `ajax/getsprintmembers.php` endpoint. `ajax/dependencyadd.php` now resolves a backlog item's sprint from `proposed_sprints_id`, validates the helper is a member of that sprint, and runs the same capacity-overflow confirmation as the in-sprint flow
- **Highlight in-review/done items whose linked work is still open**: when a sprint item is **In Review** *or* **Done** while its underlying ticket/change/problem isn't closed/solved (or its project task isn't 100% done), the meeting review row gets an amber wash + left accent and a "Linked item open" badge, so reviewers immediately see the underlying work hasn't actually finished — and it stays highlighted until the linked item is really closed. Backed by a new `SprintItem::isLinkedItemClosed()` helper
- **Backlog filters like the in-sprint item list**: the backlog filter bar gains **owner** and **status** filters (alongside the existing name/type/tag), driven by the same shared filter engine, so you can quickly narrow the backlog by who an item is assigned to
- **Manage dependencies inline from Quick Edit**: the Sprint Items quick-edit modal now always lists the item's existing dependencies and lets you change each one's capacity % or remove it on the spot (new `ajax/dependencies.php` list/update/remove endpoint), instead of only being able to add new ones
- **Backlog dependency button hidden for fastlane items**: fastlane items distribute capacity across members via the Fastlane junction rather than single-owner dependencies, so the backlog "add dependency" button no longer appears on them

### Internationalisation
- Added translations for all 1.1.3 strings to the `en_GB`, `nl_NL`, `fr_FR` and `es_ES` catalogs (and the `sprint.pot` template), and recompiled the `.mo` files

### Removed
- **Dead code cleanup**: removed the orphaned `carry_over_to_sprint` branch in `front/backlog.form.php` (the meeting carry-over is now the AJAX `ajax/carryover.php` flow, so nothing posted it anymore)

### Changed
- **Back to backlog now preserves sprint capacity, with a keep/remove choice**: the action opens a modal where you enter a **required reason** (stamped `[date — user] Back to backlog: …` into the item `note`) and choose what happens to the sprint item: **keep it in the sprint** (default) as a **manual placeholder** — its booked capacity, owner and status stay intact so the sprint's capacity totals remain correct, and only the underlying ticket/change/problem/project-task is **decoupled** and re-created as a fresh backlog row (reused if one already exists) — or **also remove it from the current sprint** (the legacy behaviour: the whole item moves to the backlog). Available from both the **meeting review** and the **Sprint Items** tab, updating the row in place. New shared `SprintItem::backToBacklog()` powers the AJAX endpoint and the form fallback
- **A coupled item now exists exactly once** (no duplicates): a linked ticket/change/problem/project-task lives in **either** the backlog **or** a single sprint, never both. Back-to-backlog skips creating a backlog row when the coupling is already active in another sprint, assigning a backlog item to a sprint purges any leftover backlog copies, and **manual placeholder items can no longer be sent back to the backlog** (the "Back to backlog" button is hidden for them) — they stay in their sprint as pure capacity holders
- **Backlog → sprint assignment is strictly Scrum-Master-only**: only the Scrum Master of the *target* sprint (its `Sprint.users_id` or a sprint member with the `scrum_master` role) may press Assign; the general plugin UPDATE right is no longer sufficient, so members can pre-plan but cannot self-assign
- **Carry over to another sprint is now dynamic (no page reload)**: carrying an item over from the meeting view goes through a new `ajax/carryover.php` endpoint and confirms in place with a toast + a brief green row flash — matching the in-place "Back to backlog" UX — instead of submitting a form and reloading the whole page
- **Natural, finished-aware sprint ordering everywhere**: a single `Sprint::dropdown()` override now sorts sprints by start date → sprint number → natural-case name, so "Sprint 10" correctly follows "Sprint 9" instead of sorting as a string, and hides completed/cancelled sprints from every picker by default (the currently-selected sprint stays visible even if it has since closed). This fixes ordering and finished-sprint visibility in the backlog, the link-to-sprint forms (Ticket/Change/Problem/Project Task), the sprint item/member/meeting forms, and the backlog assign dropdown at once

### Fixed
- **GLPI 11 CSRF 403 on the settings and profile-rights forms**: GLPI 11's HTTP kernel already validates (and consumes the single-use) CSRF token for legacy `front/` POST endpoints, so the plugin's own explicit `Session::checkCSRF()` in `front/config.form.php` and `front/profile.form.php` then failed on the already-spent token (HTTP 403, "CSRF check failed"). The explicit check is now skipped on GLPI ≥ 11 (kernel handles it) and kept on GLPI 10, so saving SprintManager settings / profile rights works again

## [1.1.2] - 2026-06-01

### Changed
- **Capacity is now a soft limit everywhere, with a confirmation prompt instead of a hard block**: regular sprint items used to hard-fail (`Update failed`) when a member's combined load (regular + fastlane + dependency) was already at/over 100% — and because fastlane and dependency allocations were deliberately allowed to overflow, that combined total could lock *every* regular item the member owned from being edited at all. Regular items now follow the same soft-overflow model as fastlane/dependency (`SprintItem::validateCapacity()` passes `allowOverflow=true`), so a save never hard-blocks. Instead, when assigning an owner/capacity (quick-edit modal) or coupling a helper (dependency add) would push that member past their capacity, the AJAX flow shows a native confirm dialog — *"X is already at Y% of Z%. This brings the total to W% (+N% over). Assign anyway?"* — and only commits on confirmation. The prompt fires only when the change actually **increases** that member's load, so editing the name/notes/status of an item owned by an already-overflowed member no longer nags. New non-mutating `SprintMember::overflowInfo()` / `overflowConfirmMessage()` helpers back the gate; endpoints accept a `confirm_overflow` flag. The no-JS form path and any other caller simply save with the existing `WARNING` message

### Fixed
- **Dependency status badge colour**: items with the `dependency` workflow state now render their status badge in the teal `#20c997` brand colour everywhere, matching the "Dependency" dashboard stat card. Two separate gaps were closed: the main dashboard "Sprint items" table built its badge from an inline `$statusBgColors` map that was missing the `STATUS_DEPENDENCY` entry (so it fell back to grey `#6c757d`), and the active `public/sprint.css` was missing the `--sprint-dependency` variable and the `.sprint-status-dependency` rule altogether — leaving the class-based badges on the Sprint Items tab and the Meeting review tab with no background

### Security
- **Stored-XSS hardening on member names**: every place that echoed `getUserName()` straight into HTML now wraps it in `htmlescape()` (linked-item tabs for Ticket/Change/Problem/Project Task, the Sprint Items / Members / Template Members / Fastlane Members / Standup / Meeting tables, the dashboard capacity tables and `getMemberName()`). A user whose GLPI display name contained markup could previously inject it into any sprint view that listed them as owner/member. Output paths that were already safe (PDF export, audit log, Twig templates, dropdowns and `data-*` attributes all escape at their own render point) were left unchanged
- **CSRF check on the settings form**: `front/config.form.php` now calls `Session::checkCSRF()` before `Config::saveConfig()`. Because the save runs through GLPI's config writer rather than `CommonDBTM`, the implicit token validation never fired; the form already emits the token via `Html::closeForm()`, so legitimate saves are unaffected

### Removed
- **Dead code cleanup**: dropped an unreachable `if ($verbose)` branch in `plugin_sprint_check_config()`, an unused `use Log;` import in `Sprint.php`, and unused CSS (the never-rendered Kanban board styles, the unused capacity-bar / empty-state rules, and the `planned`/`active`/`completed`/`cancelled` status-badge classes that were never applied — Sprint status renders as a plain text label). No behavioural change

## [1.1.1] - 2026-05-28

### Added
- **Highlight newly-blocked items in meeting review**: each meeting compares every sprint item's blocked state against what the *previous* meeting saw, so items that became blocked since last time get a soft red row wash, a left accent bar and a pulsing "Newly blocked" badge — while items already blocked at the previous meeting stay un-highlighted. The baseline is a per-meeting **snapshot**: when a meeting is viewed, the set of currently-blocked items is recorded in a new `glpi_plugin_sprint_meetingblockedsnapshots` table (sentinel row distinguishes "viewed, nothing blocked" from "never viewed"), and the next meeting compares against the previous meeting's recorded set. This carries forward reliably regardless of the meetings' scheduled dates — an item blocked "live" during a standup won't keep re-flagging in later standups once they've seen it. Meetings with no snapshot yet (created before this feature) fall back to reconstructing status from `glpi_logs` at the previous meeting's date via `SprintAudit::getItemStatusAtTimestamp()`. New `SprintMeeting::getPreviousMeeting()` / `recordBlockedSnapshot()` / `getBlockedSnapshot()` helpers
- **Sprint-window audit retention**: audit log retention is now scoped per sprint instead of a global 14-day cutoff, so completed sprints stay fully viewable. `SprintAudit::getRetentionWindowForSprint()` reads each sprint's `date_start` / `date_end`; `collectEntries()` and the team-activity range builder both clamp to that window. The nightly `pruneOldLogs()` cron now only deletes log rows older than the **earliest existing sprint's start date**, so every active/completed/planned sprint keeps its own history. Activity range picker minimum and dashboard messaging follow the sprint window instead of the fixed retention window; the "ended more than X days ago" empty-state branch is gone
- **Dependencies + Fastlane may exceed capacity (with warning)**: `SprintMember::checkCapacityForUser()` gained an `allowOverflow` parameter. Fastlane allocations (`SprintFastlaneMember`) and dependency allocations (`SprintItemDependency`) now pass `allowOverflow=true`, so capacity can go past 100% — but the save still posts a `WARNING`-level message ("X is now over capacity: Y% used of Z% (+N% overflow)"). Regular sprint-item capacity still enforces the limit. The `ajax/dependencyadd.php` endpoint surfaces the warning as a separate `warning` field; the meeting quick-edit modal renders it in a yellow alert next to the success message
- **Capacity bar with overflow visualisation**: new `SprintMember::renderCapacityBar()` helper used by the team-dashboard card, the global team-capacity table and the personal "Your Capacity" panel. When `used > total` the bar scales by `max(total, used)` so all segments fit, draws a striped red overflow band past the 100% mark, and adds a vertical 100% threshold line. The card label switches to a red "X% overflow" with a warning icon
- **Backlog enrichment: owner + estimated capacity per row**: the backlog table gains two new columns ("Owner", "Est. capacity %") with inline edit controls. Owner uses `User::dropdown`; estimated capacity uses `SprintMember::getCapacityChoices()`. On-change posts via the existing `ajax/updateitemquick.php` (capacity check no-ops for backlog rows since they have no sprint members yet), so teams can pre-plan ownership and effort before items move into a sprint. Values carry over when the item is assigned to a sprint
- **Filter completed/cancelled sprints out of the "Link to a sprint" dropdown**: from a Problem, Project Task, Ticket or Change, the sprint selector now only lists planned/active sprints (uses `'condition' => ['status' => [STATUS_PLANNED, STATUS_ACTIVE]]`), so you can't accidentally pin work to a closed sprint
- **Story points default to 1**: `SprintItem::prepareInputForAdd()` now defaults `story_points` to 1 when not provided. Visible form defaults (sprint item form, quick-edit modal, meeting quick-edit) updated to `1`, the backlog auto-add path updated, and the DB schema `DEFAULT 0` changed to `DEFAULT 1` for fresh installs. Avoids needing to manually bump every new item from 0
- **AJAX back-to-backlog from meeting view**: clicking "Back to backlog" on a meeting item row no longer reloads the page. New `ajax/backtobacklog.php` endpoint mirrors the `back_to_backlog` branch of `front/backlog.form.php` and returns JSON; the meeting JS fades the row out in place. Dependencies are still purged server-side as part of the move
- **AJAX dependency add stays in-place**: `ajax/dependencyadd.php` now returns the full open-deps list on success, and the meeting quick-edit modal re-renders the row's "Waiting on" cell from that payload. The previous `location.reload()` on modal close is gone, so adding multiple dependencies in one ceremony no longer triggers a full page refresh
- **Cross-member dependencies per sprint item**: a new "Dependencies" tab on every SprintItem lets the owner couple a colleague (helper) to the item with their own capacity %, so review-blocked or hand-off work is explicit instead of silently parked. Helpers see open obligations as a separate dashboard category alongside Sprint items and Fastlane; capacity bars (team + personal) gain a teal `Dependencies` segment summed against each member's sprint budget. Resolved rows still count toward the helper's used capacity — the time has actually been spent, so the bar reflects real spend rather than remaining obligation. The badge counter and "Open capacity" header keep the open-only count for at-a-glance "what's still pending". Soft-resolve via `is_resolved = 1` keeps the row for audit; separate `Reopen` and `Remove` actions are also available. Resolving (or removing) the **last open dependency** of an item that's currently in `STATUS_DEPENDENCY` auto-flips the item back to `STATUS_IN_PROGRESS`, so the owner doesn't have to remember to change the workflow status manually. Backed by a new `glpi_plugin_sprint_sprintitemdependencies` junction table (UNIQUE on item+user), wired into `SprintMember::checkCapacityForUser` so allocations honour each helper's total sprint capacity (`Used = Regular + Fastlane + Dependencies`). New `STATUS_DEPENDENCY` workflow state for items "waiting on someone", with matching status badge, JS color, CSS variable, and stat card. Add/resolve/reopen events flow into the existing audit log via `$dohistory = true`; SprintAudit picks them up as a new `dependency` area. Dependencies are sprint-scoped and wiped when an item leaves the sprint via back-to-backlog or is purged
- **Inline dependency badge across every item list**: items with one or more open dependencies now render a teal `<i class="fa-link"></i> N` pill next to the name in the Sprint Items tab, dashboard regular + fastlane sections, the Sprint > Fastlane tab, and the PDF export. Hover shows a tooltip with each helper + their %, so reviewers spot blocked items at a glance without opening the item
- **Quick-edit modal: inline dependency add + manage shortcut**: every quick-edit modal (dashboard, Sprint Items tab, meeting kick-off / standup / review) gains a "Dependencies" block with an inline `Sprint member + Capacity + Add helper` form (AJAX, server-side capacity validation via `SprintMember::checkCapacityForUser`) and a "Manage dependencies →" shortcut to the dedicated tab. Add multiple helpers in succession without leaving the modal; on close the page reloads only when something was actually added so the new badges/segments appear. Backed by a new `ajax/dependencyadd.php` endpoint with CSRF + per-user UPDATE-right enforcement
- **"Helping on" list on each member card**: the team-dashboard member card now shows a compact `Helpt op:` list (item name + capacity % + owner) for items the member is a helper on, capped at three with a `+ N more` overflow line. Surfaces cross-member obligations on the same card that already shows that member's regular workload
- **"Waiting on" column in meeting review**: the kick-off / standup / review tables (both fastlane and regular sections) gain a dedicated `Waiting on` column listing each open helper + capacity %, so the daily question "is your dependency done?" has the answer right next to the item
- **Blocked items keep their flag when sent back to the backlog**: the `back_to_backlog` action now inspects the item's status and `is_blocked` flag; if either indicates blocked, the row lands in the backlog with `is_blocked = 1` so the dedicated "Blocked" section catches it for Scrum Master review (previously the item silently dropped into the regular backlog list)

## [1.1.0] - 2026-05-06

### Added
- **Admin-defined tags per sprint item**: a new "Sprint item tags" pool is defined centrally in plugin settings (one per line, comma-separated also accepted, deduplicated). Members assign tags to items from that pool via a checkbox group on the SprintItem form; only admins extend the list. Tags render as pills next to the item name everywhere items appear (Sprint Items tab, Dashboard tabs, Backlog, Meeting Fastlane + Regular review). A new `.sf-tag` filter dropdown in every filter bar narrows the list to a specific tag. Backed by a new junction table `glpi_plugin_sprint_sprintitemtags` (UNIQUE on item+tag); single bulk fetch per page keeps it N+1-free
- **Permanent delete for Scrum Master**: a danger-zone card below the General form lets the sprint's Scrum Master purge the sprint and all its items, members, meetings and audit history in one step. Gated server-side on `Config::isCurrentUserScrumMaster()` + `PURGE` right; a CSP-safe delegated `data-sprint-confirm` handler shows a native confirm dialog before submit. Non-Scrum-Master purges return an error message
- **Custom date range on Team activity chart**: From / To date pickers above the chart let users zoom in on any sub-range or pan within the 14-day audit retention window without a full page reload. Backed by a new `ajax/activitychart.php` endpoint that re-renders just the chart fragment in place; range is passed as URL params and clamped to retention server-side
- **Problem itemtype as first-class linked item**: Problems join Tickets, Changes and Project Tasks as supported sprint item types. New `glpi_plugin_sprint_sprintproblems` link table, `SprintProblem` class with reverse "Sprints" tab on Problems, ITEM_PURGE cleanup hook, type icon (`fa-exclamation-circle`, red), Backlog "Type" filter option, status branch in the linked-item quick-edit modal + AJAX endpoint, and per-type branches in the dashboard / meeting / export type-icon and allowed-types maps

### Changed
- **Carry-over clears owner**: `SprintItem::carryOverTo()` now sets `users_id = 0` on the copy created in the target sprint, so the new sprint planning starts with an unassigned item — preserving the source row's owner only on the source sprint
- **Audit retention scoped to the sprint window**: `SprintAudit::RETENTION_DAYS` is kept only as a fallback bound for sprints with no dates; it's no longer the global cutoff (see the sprint-window retention entry above)

### Fixed
- **Sprint overview status filter**: the `status` rawSearchOption on Sprint was missing `'searchtype' => ['equals', 'notequals']`, so GLPI's Search UI couldn't construct a WHERE clause for it. Filtering the sprint list on status (notably "is not Completed") now works
- **Audit-log tab filter**: the filter bar on the Audit log tab used a custom `.sprint-audit-kind` dropdown + `tr.sprint-audit-row` selector that ran through a separate JS code path which set `display: none` without `!important` — losing to GLPI's `display: table-row` utility classes. Re-aligned the audit markup to the same `.sf-status` / `tr.sprint-filterable-row` / `data-item-name` / `data-item-status` contract every other plugin filter uses, so a single shared `applyFilter()` (with `!important` row hiding) drives all five filter bars
- **PDF export uniform light theme + notifier bell removed**: the export's `@media print` block now overrides Tabler's CSS variables at the root so dark-mode body / text / border colours don't bleed through (CSS can't unload an already-loaded stylesheet), and adds GLPI 10/11 notification-bell selectors to the chrome-hide list. PDFs are visually identical regardless of the user's active theme
- **Fastlane quick-edit corrupting action cell**: in the meeting review tab, after quick-editing a fastlane item the post-save JS replaced any `<a>` in the row whose `href` contained `sprintitem.form.php` with the item name — corrupting the fastlane "Manage members" button (which has the same href). Skip button-styled anchors so only the name link gets retitled

## [1.0.10] - 2026-04-29

### Added
- **Audit-source attribution**: a new side-table `glpi_plugin_sprint_audit_sources` records that a `glpi_logs` row was produced by a meeting context — both the bulk meeting-form save (which fans out into many SprintItem updates via `_sprintitems`) and quick-edits done from the meeting view (which post to `ajax/updateitemquick.php` with the active `meeting_id`). The audit tab shows a "via Meeting <name>" badge next to those rows so ceremony-driven edits are visually distinct from individual ones. Added via idempotent migration — existing sprint data is preserved; only forward-going meeting changes are tagged

### Fixed
- **Team activity chart no longer skewed by meeting saves**: the chart now deterministically excludes SprintItem log rows attributed to a meeting save (using the new audit-source side-table). Previously a meeting save with N items × multiple fields could inflate one member's activity by hundreds of events, and quick-edits on items during a ceremony were also counted as "individual work". The earlier 1.0.9 hotfix that excluded the SprintMeeting itemtype itself was insufficient because the heavy log fan-out lives on the items, not on the meeting record

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
