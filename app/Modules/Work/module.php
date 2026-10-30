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
 * Phase 1 adds the work item, the workflow behind it and the two screens; the board, the backlog and
 * the sprints follow in Phases 2 and 3; Phase 4 adds epics, releases, the project page's tabs and the
 * four reports. The work item joins the *Projects* group that `projects` and `mpr` already claim for
 * the Work domain, because a work item and the project it belongs to are read on the same visit.
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

    'models' => [
        'App\\Models\\WorkWorkflow' => \App\Modules\Work\Models\WorkWorkflow::class,
        'App\\Models\\WorkStatus' => \App\Modules\Work\Models\WorkStatus::class,
        'App\\Models\\WorkWorkflowRule' => \App\Modules\Work\Models\WorkWorkflowRule::class,
        'App\\Models\\WorkItem' => \App\Modules\Work\Models\WorkItem::class,
        'App\\Models\\WorkLabel' => \App\Modules\Work\Models\WorkLabel::class,
        'App\\Models\\WorkAttachment' => \App\Modules\Work\Models\WorkAttachment::class,
        'App\\Models\\WorkItemLink' => \App\Modules\Work\Models\WorkItemLink::class,
        'App\\Models\\WorkBoard' => \App\Modules\Work\Models\WorkBoard::class,
        'App\\Models\\WorkSprint' => \App\Modules\Work\Models\WorkSprint::class,
        'App\\Models\\WorkRelease' => \App\Modules\Work\Models\WorkRelease::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\Board' => \App\Modules\Work\Filament\Pages\Board::class,
        'App\\Filament\\Pages\\MyWork' => \App\Modules\Work\Filament\Pages\MyWork::class,
        'App\\Filament\\Pages\\Backlog' => \App\Modules\Work\Filament\Pages\Backlog::class,
        'App\\Filament\\Pages\\Epics' => \App\Modules\Work\Filament\Pages\Epics::class,
        // The four reports of §7, reached from the Reports hub rather than the sidebar.
        'App\\Filament\\Pages\\WorkVelocity' => \App\Modules\Work\Filament\Pages\WorkVelocity::class,
        'App\\Filament\\Pages\\WorkCycleTime' => \App\Modules\Work\Filament\Pages\WorkCycleTime::class,
        'App\\Filament\\Pages\\WorkCreatedVsResolved' => \App\Modules\Work\Filament\Pages\WorkCreatedVsResolved::class,
        'App\\Filament\\Pages\\WorkCumulativeFlow' => \App\Modules\Work\Filament\Pages\WorkCumulativeFlow::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\WorkItems\\WorkItemResource' => \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::class,
        // Workflows are a Settings screen rather than a board one: a workflow governs every board
        // the company has, which is why §13.1 makes it an administrator's.
        'App\\Filament\\Resources\\WorkWorkflows\\WorkWorkflowResource' => \App\Modules\Work\Filament\Resources\WorkWorkflows\WorkWorkflowResource::class,
        'App\\Filament\\Resources\\WorkSprints\\WorkSprintResource' => \App\Modules\Work\Filament\Resources\WorkSprints\WorkSprintResource::class,
        'App\\Filament\\Resources\\WorkReleases\\WorkReleaseResource' => \App\Modules\Work\Filament\Resources\WorkReleases\WorkReleaseResource::class,
    ],

    /**
     * The three lists a company edits for itself — docs/project-management-plan.md §3.2.
     *
     * Types, priorities and resolutions are option lists rather than constants because "add a Spike"
     * and "we call it P0 here" are the two requests every team makes in its first week, and neither
     * should be a deploy. The hierarchy meanings are the exception: `epic` and `subtask` are read by
     * the model, so renaming their labels is fine and deleting the rows is not.
     */
    'option_lists' => [
        'work.type' => [
            'label' => 'Work item types',
            'help' => 'What kinds of work you track. Epic and Subtask carry meaning — an epic sits above stories and a subtask below one — so rename them freely but do not remove them.',
            'values' => ['Epic', 'Story', 'Task', 'Bug', 'Subtask'],
        ],

        'work.priority' => [
            'label' => 'Work item priorities',
            'help' => 'How urgent a work item is. Nothing computes from these; they sort and they filter.',
            'values' => ['Highest', 'High', 'Medium', 'Low', 'Lowest'],
        ],

        'work.resolution' => [
            'label' => 'Work item resolutions',
            'help' => 'How a work item ended. Asked for when a status requires it — see the workflow rules.',
            'values' => ['Done', 'Won\'t do', 'Duplicate', 'Cannot reproduce'],
        ],
    ],

    'navigation_items' => [
        // Beside the other screens about the person looking at them, not about the company —
        // the same reason NavigationDomains gives for putting Personal in Home.
        \App\Modules\Work\Filament\Pages\MyWork::class => 'home',
    ],

    'navigation' => [
        'Projects' => 'work',
        // The workflow editor sits with the other things an administrator sets up once.
        'Settings' => 'admin',
    ],

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
