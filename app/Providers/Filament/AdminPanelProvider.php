<?php

namespace App\Providers\Filament;

use App\Filament\Navigation\DomainNavigationManager;
use App\Filament\Navigation\NavigationSnapshot;
use App\Modules\Core\Filament\Pages\Auth\EditProfile;
use App\Modules\Core\Filament\Pages\Dashboard;
use App\Modules\Core\Models\Company;
use App\Support\Modules;
use App\Support\NavigationTree;
use App\Support\TenantStorage;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationManager;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Brand colours sampled from the ErbiumTech logo (public/images/logo.png):
     * the three diagonal slashes, dark green through to lime.
     */
    public const string BRAND_GREEN = '#3E894A';

    public const string BRAND_GREEN_MID = '#91BD55';

    public const string BRAND_LIME = '#D3DA54';

    /**
     * Primary ramp built by hand around BRAND_GREEN rather than via
     * Color::hex(), which normalises lightness per shade and would leave the
     * actual logo green absent from the palette. Shade 600 is what Filament
     * paints buttons and active nav with, so the brand colour sits there.
     *
     * @var array<int, string>
     */
    protected const array BRAND_GREEN_SHADES = [
        50 => '#F7FAF8',
        100 => '#E8F1E9',
        200 => '#CDE0D0',
        300 => '#A8CAAE',
        400 => '#83B38B',
        500 => '#619E6B',
        600 => self::BRAND_GREEN,
        700 => '#367741',
        800 => '#2E6537',
        900 => '#26522E',
        950 => '#1C3E23',
    ];

    /**
     * The two-level navigation's two bindings.
     *
     * In register() rather than in panel(), because both are container concerns and panel() is
     * called while the panel is being built — Filament resolves the navigation manager later, per
     * request, which is exactly what lets the substitution below work without a published view.
     */
    public function register(): void
    {
        parent::register();

        // Narrows the sidebar to one domain. Guarded to this panel inside the class.
        //
        // `scoped`, matching how Filament registers the class this replaces, and it has to be:
        // Panel::getNavigation() resolves the manager, assigns it to the panel, and then resolves
        // it *again* to call get() — while every page and resource registers its navigation items
        // into whichever instance the panel is holding. A transient binding hands out two objects,
        // so every registration lands in the first and get() returns the second, empty. The sidebar
        // then renders blank with nothing failing anywhere.
        $this->app->scoped(
            NavigationManager::class,
            fn (): NavigationManager => new DomainNavigationManager,
        );

        // Assembles the tree once per request for the rail and the column to share. `scoped`, not
        // `singleton` — see the class.
        $this->app->scoped(NavigationSnapshot::class);

    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->tenant(Company::class, slugAttribute: 'slug')
            // Company switcher for users who belong to more than one company.
            ->tenantMenu()
            // No tenant registration. Creating a company provisions a database,
            // migrates it and seeds its roles — installation-level work, which now
            // happens on the platform panel where there is no company in scope to
            // confuse it with. Two routes to the same act meant two places to keep the
            // super-admin check.
            ->viteTheme('resources/css/filament/admin/theme.css')
            /*
             * Pages use the whole window, rather than being capped at 1280px and centred.
             *
             * Filament's default is Width::SevenExtraLarge, which on a large display leaves the content in
             * a column down the middle with the rest empty — and the screens here are the ones that most
             * want the room: a statement with a comparison column, a register of transactions, a table of
             * twenty payroll figures. Width::Full lifts the cap (`:is(.fi-main).fi-width-full` is a
             * max-width of 100%), so the width is decided by the content and the rail beside it.
             *
             * A page that wants a narrower measure can still say so — this is the panel's default, not a
             * rule — and Filament's own sections and forms keep their internal widths.
             */
            ->maxContentWidth(Width::Full)

            // Soft navigation. Every link Filament renders gains `wire:navigate`
            // (Filament\Support\generate_href_html), so moving between pages swaps the body over
            // fetch instead of throwing the document away: the 649KB theme stays parsed, Alpine and
            // Livewire stay booted, and the Reverb socket stays open. The domain rail was already
            // navigating this way on its own — this is the rest of the shell catching up.
            //
            // Prefetching is deliberately off. `wire:navigate.hover` fetches on hover, and a full
            // page render here costs 25 database statements before the page does any work of its
            // own (see PanelPerformanceTest); a person sweeping down the sidebar would issue that
            // several times over for pages they never open. Turn it on once the sidebar badge
            // counts are cached — docs/page-load-performance-plan.md, Phase 2.
            ->spa(hasPrefetching: false)
            // What must stay a real browser navigation.
            //
            // A soft navigation replaces the body with whatever came back, so a URL that answers
            // with a PDF or a spreadsheet leaves the user on a blank page holding a file the
            // browser never offered to save. Every download in this application is listed here.
            //
            // Matched with str()->is() against the whole URL, so each pattern needs a leading * for
            // the scheme and host (ViewManager::hasSpaMode).
            ->spaUrlExceptions([
                // Filament's own export/import downloads.
                '*/filament/exports/*',
                '*/filament/imports/*',
                // The DomPDF statements and the invoice/payslip PDFs — see routes/web.php and each
                // module's report controller.
                '*/reports/*',
                '*/api/*',
                // Company uploads, streamed through TenantFileController rather than off disk.
                '*/'.TenantStorage::URL_PREFIX.'/*',
                // A different panel: different assets, different navigation. Swapping this panel's
                // body for that one's would run new markup against JS that was booted for neither.
                '*/platform*',
            ])
            // 248px, the width of 3a's contextual column. The column is narrower than Filament's
            // default because it now shows one domain at a time rather than every group at once —
            // see NavigationDomains, and the rail registered at LAYOUT_START below.
            ->sidebarWidth('248px')
            // The column folds away entirely, leaving the rail. "Fully" rather than Filament's other
            // collapsible mode on purpose: that one shrinks the column to a strip of icons, and beside
            // an 84px rail of icons that is two icon columns saying different things. Remembered per
            // person by Filament's own sidebar store.
            ->sidebarFullyCollapsibleOnDesktop()
            ->login()
            // Self-service password change (user menu → Change Password).
            // Simple layout: the profile route sits outside the tenant prefix.
            ->profile(EditProfile::class)
            ->brandName('ErbiumTech')
            ->brandLogo(asset('images/logo.png'))
            ->darkModeBrandLogo(asset('images/logo-dark.png'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/favicon.png'))
            // Sampled from the ErbiumTech logo: the dark green slash drives the
            // primary ramp, the mid green and lime are used as accents.
            ->colors([
                'primary' => self::BRAND_GREEN_SHADES,
                'success' => Color::hex(self::BRAND_GREEN_MID),
                'warning' => Color::hex(self::BRAND_LIME),
                'gray' => Color::Slate,
            ])
            // Disabled in favour of the ⌘K command palette, which is a superset:
            // it searches records through the same resource global-search hooks,
            // and also finds resources, pages and commands. Resources keep their
            // getGloballySearchableAttributes() — canGloballySearch() does not
            // consult this setting, so the palette still finds records.
            ->globalSearch(false)
            // Bell icon in the topbar. Echo (config/filament.php) pushes new ones
            // instantly over Reverb; this is only the fallback for a dropped socket,
            // so it is five minutes rather than one. At 60s every open tab in the
            // company made a Livewire round trip a minute for something that had
            // already arrived — and with SPA navigation on, tabs now stay open for
            // much longer than they used to.
            ->databaseNotifications()
            ->databaseNotificationsPolling('300s')
            // Every resource, page and widget now belongs to a module and is
            // registered by that module's plugin — there is no app-level discovery
            // left. Registration is unconditional regardless of licence state; see
            // any Plugin class for why that cannot be otherwise.
            ->plugins(Modules::plugins())
            // The order the branches appear in each column. Filament sorts groups by their position
            // in this list and by nothing else — left out, the branches would come out ordered by
            // whichever of their items happened to have the lowest sort, which was chosen back when
            // each group was one flat list. See NavigationTree.
            ->navigationGroups(NavigationTree::order())
            ->pages([
                // Ours, not Filament's — reports-expansion-plan.md Phase 5.1. It carries the period filter
                // every widget reads, so no widget keeps its own idea of "now".
                Dashboard::class,
            ])
            ->widgets([])
            // The domain rail — the first level of the two-level shell. LAYOUT_START puts it as
            // the first child of `.fi-layout`, which is a flex row containing the sidebar and the
            // content, so the rail becomes a peer of both and no Filament view needed publishing.
            // What it contains is decided by App\Support\NavigationDomains; the sidebar beside it
            // is narrowed to the same domain by DomainNavigationManager, bound in register().
            /*
             * The right-click menu's off switch — `docs/table-context-menu-plan.md` §4.
             *
             * In the user menu because it has to be somewhere the menu itself is not: the context menu carries
             * the same toggle, which is where somebody annoyed by it will look, but a menu that has just
             * switched itself off cannot switch itself back on.
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_AFTER,
                fn (): string => view('filament.partials.table-context-menu-toggle')->render(),
            )
            ->renderHook(
                PanelsRenderHook::LAYOUT_START,
                fn (): string => view('filament.partials.domain-rail')->render(),
            )
            // Says which domain the column is showing. Above the groups, below the company
            // switcher — the rail alone leaves "what am I looking at" unanswered in words.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => view('filament.partials.domain-heading')->render(),
            )
            // Groups are seeded closed (see DomainNavigationManager::collapsed); this opens the one
            // holding the current page, which Filament does not do on its own. Must stay at
            // SIDEBAR_NAV_END — see the partial for why the position is load bearing.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => view('filament.partials.sidebar-open-active-group')->render(),
            )
            // Impersonation banner, above everything else on the page. PAGE_START
            // would put it inside the content area; this sits at the top of the
            // body so it is present on every panel page including the ones that
            // scroll.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => view('filament.partials.impersonation-banner')->render(),
            )
            // ⌘K command palette (rendered on every panel page).
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('@livewire(\App\Filament\Livewire\CommandPalette::class)'),
            )
            // One search box, not two. Filament's global search field is turned
            // off above and this trigger takes its place in the topbar, opening
            // the ⌘K palette instead — which searches the same records (via each
            // resource's getGlobalSearchResults) plus resources, pages and
            // commands. GLOBAL_SEARCH_BEFORE renders even when global search is
            // disabled, so this lands exactly where the old field sat: inside
            // fi-topbar-end, ahead of the notifications and user menu.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament.partials.command-palette-trigger')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
