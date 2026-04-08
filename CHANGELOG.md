# Changelog

All notable changes to this project will be documented in this file.

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
