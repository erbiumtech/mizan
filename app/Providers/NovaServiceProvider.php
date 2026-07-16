<?php

namespace App\Providers;

use App\Models\User;
use App\Nova\Dashboards\Main;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Laravel\Nova\Tool;
use App\Services\NovaAuthService;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        parent::boot();

        (new NovaAuthService())->handleStatusBasedLogin();

        Nova::mainMenu(function ($request, $menu) {
            return $menu->append(
                \Laravel\Nova\Menu\MenuSection::make('Reports', [
                    \Laravel\Nova\Menu\MenuItem::externalLink('Trial Balance', url('/reports/trial-balance')),
                    \Laravel\Nova\Menu\MenuItem::externalLink('Profit & Loss', url('/reports/profit-and-loss')),
                    \Laravel\Nova\Menu\MenuItem::externalLink('Bank Payment File', url('/reports/bank-payment-file')),
                ])->icon('document-chart-bar')->collapsable()
                  ->canSee(fn ($req) => (bool) $req->user()?->can('ReportView'))
            );
        });
    }

    /**
     * Register the configurations for Laravel Fortify.
     */
    protected function fortify(): void
    {
        Nova::fortify()
            ->features([
                Features::updatePasswords(),
                // Features::emailVerification(),
                // Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            ])
            ->register();
    }

    /**
     * Register the Nova routes.
     */
    protected function routes(): void
    {
        Nova::routes()
            ->withAuthenticationRoutes(default: true)
            ->withPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Register the Nova gate.
     *
     * This gate determines who can access Nova in non-local environments.
     */
    protected function gate()
    {
        Gate::define('viewNova', function ($user) {
            // Sirf status 1 wala user hi Nova dashboard dekh sake ga
            return true;
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array<int, Dashboard>
     */
    protected function dashboards(): array
    {
        return [
            new Main,
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [
        \Sereny\NovaPermissions\NovaPermissions::make(),
    ];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        //
    }
}
