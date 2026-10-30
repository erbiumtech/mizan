<?php

namespace App\Modules\Work;

use App\Modules\Work\Models\WorkAttachment;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkLabel;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Modules\Work\Policies\WorkAttachmentPolicy;
use App\Modules\Work\Policies\WorkItemLinkPolicy;
use App\Modules\Work\Policies\WorkItemPolicy;
use App\Modules\Work\Policies\WorkLabelPolicy;
use App\Modules\Work\Policies\WorkStatusPolicy;
use App\Modules\Work\Policies\WorkWorkflowPolicy;
use App\Modules\Work\Policies\WorkWorkflowRulePolicy;
use App\Support\CustomFieldSubjects;
use App\Support\ModuleMap;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies, registries and scheduled work for the work module.
 *
 * Phase 1 is the policies. The webhook event types arrive in Phase 6 and the integration providers
 * in Phase 7, both here.
 */
class WorkServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        WorkWorkflow::class => WorkWorkflowPolicy::class,
        WorkStatus::class => WorkStatusPolicy::class,
        WorkWorkflowRule::class => WorkWorkflowRulePolicy::class,
        WorkItem::class => WorkItemPolicy::class,
        WorkLabel::class => WorkLabelPolicy::class,
        WorkAttachment::class => WorkAttachmentPolicy::class,
        WorkItemLink::class => WorkItemLinkPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        /*
         * A work item takes custom fields from the day it exists — docs/project-management-plan.md §3.2.
         *
         * The bug form's "browser" and "environment" are the company's to add, and nothing here has
         * to be built for them: registering the subject is the whole of it. A module that waits for
         * somebody to ask is a module that ships a form nobody can adapt.
         */
        CustomFieldSubjects::register(ModuleMap::alias(WorkItem::class), 'Work Items');
    }
}
