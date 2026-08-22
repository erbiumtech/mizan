<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
     * Register the Horizon gate.
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
     * at once. Gating on a company-scoped role would hand one customer's administrator a window
     * onto every other customer's payroll. Same reasoning as the platform panel, which exists
     * for exactly this class of screen.
     *
     * Replaces the generated stub, which was an empty `in_array($user->email, [])` — safe, in
     * that it let nobody in, but it would have been "fixed" by the first person who needed the
     * dashboard, most likely by loosening it further than this.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => (bool) ($user?->is_super_admin ?? false));
    }
}
