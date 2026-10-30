<?php

/**
 * What this module is, and what it owns.
 *
 * Work items on a board — docs/project-management-plan.md. Requires `projects` for the container a
 * work item belongs to and `employees` for the person it is assigned to; required by nothing, so the
 * graph stays acyclic and the three places the arrow would otherwise point backwards (the project
 * page's tabs, the timesheet's item picker, a ticket spawning an item) all go through
 * `App\Support\ResourceContributions` instead. See §13.3.
 *
 * **Not folded into `projects`.** That module is operations — uptime, certificates, the public
 * status page — and has a different buyer in the same company: the person who runs servers is
 * rarely the person who runs sprints. A company that monitors its environments and plans its work
 * elsewhere licenses one and not the other, and the module boundary is what makes that a flag
 * rather than a fork. §2.1.
 *
 * **Phase 0 declares the module and nothing else.** No models, no resources, no navigation claim —
 * the rail's Work domain is fed by `projects` and `mpr` claiming the *Projects* group, and this
 * module's own screens arrive in Phase 1 with the work item. The permissions are here now because
 * role grants are seeded from the manifest and a permission that appears a phase late is a role
 * somebody has to re-save.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'work',
    'label' => 'Work',
    'description' => 'Work items on a Kanban board: types, workflows with status categories, backlog, sprints, epics and releases, with the repositories and time trackers that talk to them.',
    'requires' => [
        'employees',
        'projects',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Work\WorkPlugin::class,

    'permission_groups' => [
        'Work',
    ],

    /**
     * Ten, and the two splits are deliberate.
     *
     * `WorkItemMove` is separate from `WorkItemUpdate` so a client contact may read a board and not
     * drag on it — the common request from a team that shares a board outward. `WorkflowManage` and
     * `IntegrationManage` are separate from `BoardManage` because they are the two that reach outside
     * the project: a workflow governs every board in the company, and an integration holds a
     * credential to somebody else's system.
     */
    'permissions' => [
        ['name' => 'WorkItemView', 'group' => 'Work'],
        ['name' => 'WorkItemCreate', 'group' => 'Work'],
        ['name' => 'WorkItemUpdate', 'group' => 'Work'],
        ['name' => 'WorkItemMove', 'group' => 'Work'],
        ['name' => 'WorkItemDelete', 'group' => 'Work'],
        ['name' => 'BoardManage', 'group' => 'Work'],
        ['name' => 'SprintManage', 'group' => 'Work'],
        ['name' => 'ReleaseManage', 'group' => 'Work'],
        ['name' => 'WorkflowManage', 'group' => 'Work'],
        ['name' => 'IntegrationManage', 'group' => 'Work'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the role
     * below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission already granted
     * to Accountant is not repeated here.
     *
     * Every employee works the board: a tool where filing a bug needs a permission is a tool where
     * bugs are filed in chat. What an ordinary employee does not get is the shape of the board
     * itself, the sprint boundaries, or anything that reaches outside the project.
     */
    'role_grants' => [
        'Employee' => ['WorkItemView', 'WorkItemCreate', 'WorkItemUpdate', 'WorkItemMove'],
        'Manager' => ['BoardManage', 'SprintManage', 'ReleaseManage'],
        'CEO' => ['WorkItemDelete'],
    ],
];
