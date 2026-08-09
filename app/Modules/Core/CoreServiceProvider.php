<?php

namespace App\Modules\Core;

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\TableView;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\ActivityLogPolicy;
use App\Modules\Core\Policies\CommentPolicy;
use App\Modules\Core\Policies\CompanyPolicy;
use App\Modules\Core\Policies\CustomFieldPolicy;
use App\Modules\Core\Policies\EmailTemplatePolicy;
use App\Modules\Core\Policies\FiscalYearPolicy;
use App\Modules\Core\Policies\PermissionPolicy;
use App\Modules\Core\Policies\RolePolicy;
use App\Modules\Core\Policies\TableViewPolicy;
use App\Modules\Core\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Everything the Core module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class CoreServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ActivityLog::class => ActivityLogPolicy::class,
        Comment::class => CommentPolicy::class,
        Company::class => CompanyPolicy::class,
        CustomField::class => CustomFieldPolicy::class,
        FiscalYear::class => FiscalYearPolicy::class,
        TableView::class => TableViewPolicy::class,
        EmailTemplate::class => EmailTemplatePolicy::class,
        User::class => UserPolicy::class,

        // Vendor models Core owns the authorization for. The activity log is
        // spatie's Activity, not our ActivityLog wrapper, so it needs naming
        // separately or audit rows authorize as unpoliced.
        Activity::class => ActivityLogPolicy::class,
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
