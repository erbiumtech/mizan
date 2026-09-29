<?php

namespace App\Providers;

use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Who may open Horizon: super admins, checked directly rather than through a gate.
     *
     * **Super admins only, and this is a data-protection decision rather than a convenience
     * one.** Horizon's dashboard shows queued job payloads — and in this application those
     * payloads are payslip notifications, expense-claim approvals, employee document alerts and
     * billing statements. A job payload is customer data with a queue in front of it.
     *
     * `is_super_admin` is the right test because it is the only authority in this application
     * that is *installation*-level. Every other permission is scoped to a company by
     * spatie/laravel-permission teams, so "Administrator" means administrator **of one
     * company** — and Horizon is not per-company: one dashboard shows the jobs of every tenant
     * at once. Handing a company-scoped role this screen would hand one customer's
     * administrator a window onto every other customer's payroll. Same reasoning as the
     * platform panel, which exists for exactly this class of screen.
     *
     * **Not a Gate on purpose.** This used to be the parent's `viewHorizon` gate, and
     * PlatformPanelAccessTest caught what that misses: AppServiceProvider's `Gate::before`
     * answers every non-`create` ability with `true` for any company Administrator, and a
     * before-callback outruns any gate definition — so the gate version admitted exactly the
     * audience it was written to refuse. An installation-level authority must not route
     * through machinery whose first answer is company-scoped.
     */
    protected function authorization(): void
    {
        Horizon::auth(fn ($request): bool => (bool) $request->user()?->isSuperAdmin());
    }
}
