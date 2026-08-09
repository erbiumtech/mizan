<?php

namespace App\Console\Commands;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\ModuleMap;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Process\Process;

/**
 * Re-capture the screenshot at the top of every help topic.
 *
 *   php artisan help:screenshots
 *
 * The screenshots go stale the moment the UI they show changes, and nothing
 * fails when they do — so this exists to make regenerating them one command
 * rather than an afternoon of clicking.
 *
 * It starts its own `artisan serve` rather than using whatever is already
 * running, because the first pass of these screenshots was taken against a
 * normal dev server and every one of them shipped with the Laravel debug bar
 * across the bottom of customer-facing documentation.
 *
 * Note that asking for the bar to be off here is best-effort only:
 * ServeCommand rebuilds the child server's environment from $_ENV and filters it
 * through its own allowlist, so DEBUGBAR_ENABLED does not survive the trip. What
 * actually guarantees a clean screenshot is scripts/screenshot-help.cjs removing
 * the bar from the page and refusing to write the file if it is still there.
 *
 * Puppeteer does the driving (see scripts/screenshot-help.cjs) because Chrome is
 * already a dependency for PDF rendering.
 */
class ScreenshotHelpTopics extends Command
{
    protected $signature = 'help:screenshots
        {--company= : Company slug to capture against (default: the first company)}
        {--email=admin@example.test : User to sign in as; needs to reach every page}
        {--password= : That user\'s password (prompted for when omitted)}
        {--port=8129 : Port for the throwaway server}
        {--only=* : Capture only these slugs}';

    protected $description = 'Re-capture the screenshots embedded in the in-app help topics';

    public function handle(): int
    {
        if (! File::isDirectory(base_path('node_modules/puppeteer'))) {
            $this->error('node_modules/puppeteer is missing — run npm install first.');

            return self::FAILURE;
        }

        $company = $this->option('company')
            ? Company::where('slug', $this->option('company'))->first()
            : Company::orderBy('id')->first();

        if ($company === null) {
            $this->error('No company to capture against.'.($this->option('company') ? ' Check --company.' : ''));

            return self::FAILURE;
        }

        $user = User::where('email', $this->option('email'))->first();

        if ($user === null) {
            $this->error('No user with email '.$this->option('email').'.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password for '.$user->email);

        if (blank($password)) {
            $this->error('A password is required to sign in.');

            return self::FAILURE;
        }

        $targets = $this->targets($user, $company);

        if ($only = $this->option('only')) {
            $targets = array_intersect_key($targets, array_flip($only));
        }

        if ($targets === []) {
            $this->error('No help topics matched.');

            return self::FAILURE;
        }

        $this->line('Capturing '.count($targets).' topic(s) against '.$company->name.'.');

        $server = $this->startServer();

        if ($server === null) {
            return self::FAILURE;
        }

        try {
            return $this->capture($targets, $password);
        } finally {
            $server->stop();
        }
    }

    /**
     * Every help slug that has a page to photograph, mapped to its URL.
     *
     * Read off the same HelpAction::make(...) calls the panel uses, so a topic
     * cannot be captured under a slug the app does not actually serve. Resources
     * whose list page is only reachable as a relation manager have no HelpAction
     * and drop out here on their own.
     *
     * @return array<string, array{url: string, title: string}>
     */
    private function targets(User $user, Company $company): array
    {
        auth()->login($user);

        $targets = [];

        foreach (['admin' => $company, 'platform' => null] as $panel => $tenant) {
            Filament::setCurrentPanel(Filament::getPanel($panel));

            if ($tenant !== null) {
                Filament::setTenant($tenant);
            }

            foreach ($this->classesFor($panel) as $class) {
                $page = (new ReflectionClass($class))->isSubclassOf(Page::class)
                    ? $class
                    : ($class::getPages()['index'] ?? null)?->getPage();

                if ($page === null || ($help = $this->helpCallIn($page)) === null) {
                    continue;
                }

                try {
                    $targets[$help['slug']] = ['url' => $page::getUrl(), 'title' => $help['title']];
                } catch (\Throwable $e) {
                    $this->warn('Skipping '.$help['slug'].': '.$e->getMessage());
                }
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * @return array<int, class-string>
     */
    private function classesFor(string $panel): array
    {
        $all = [...ModuleMap::resources(), ...ModuleMap::pages()];

        // The platform panel holds the cross-company resources; everything else
        // belongs to a company's own panel. Asking the wrong panel for a URL
        // throws, which is why they are captured in two passes.
        return array_values(array_filter(
            $all,
            fn (string $class) => str_contains($class, '\\Filament\\Platform\\') === ($panel === 'platform'),
        ));
    }

    /**
     * @return array{slug: string, title: string}|null
     */
    private function helpCallIn(string $class): ?array
    {
        $source = File::get((new ReflectionClass($class))->getFileName());

        if (! preg_match("/HelpAction::make\(\s*'([a-z0-9-]+)'\s*,\s*'([^']+)'/", $source, $matches)) {
            return null;
        }

        return ['slug' => $matches[1], 'title' => $matches[2]];
    }

    private function startServer(): ?Process
    {
        $port = (int) $this->option('port');

        $server = new Process(
            ['php', 'artisan', 'serve', '--port='.$port],
            base_path(),
            // Best-effort only — see the class comment for why this does not
            // survive into the child server, and what does the real work.
            ['DEBUGBAR_ENABLED' => 'false', 'APP_DEBUG' => 'false'],
        );

        $server->start();

        foreach (range(1, 20) as $attempt) {
            usleep(500_000);

            if (! $server->isRunning()) {
                $this->error('Server exited: '.trim($server->getErrorOutput() ?: $server->getOutput()));

                return null;
            }

            if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 1)) {
                return $server;
            }
        }

        $server->stop();
        $this->error('Server did not come up on port '.$port.'.');

        return null;
    }

    /**
     * @param  array<string, array{url: string, title: string}>  $targets
     */
    private function capture(array $targets, string $password): int
    {
        $base = 'http://127.0.0.1:'.(int) $this->option('port');
        $outDir = public_path('images/help');
        File::ensureDirectoryExists($outDir);

        // getUrl() builds absolute URLs from APP_URL, which is not the throwaway
        // server.
        $manifest = [];
        foreach ($targets as $slug => $info) {
            $manifest[$slug] = [
                'url' => preg_replace('#^https?://[^/]+#', $base, $info['url']),
                'title' => $info['title'],
            ];
        }

        $manifestPath = storage_path('app/help-screenshots-manifest.json');
        $resultsPath = storage_path('app/help-screenshots-results.json');
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $node = new Process(
            ['node', base_path('scripts/screenshot-help.cjs'), $manifestPath, $outDir, $resultsPath],
            base_path(),
            [
                'HELP_SHOT_BASE' => $base,
                'HELP_SHOT_EMAIL' => (string) $this->option('email'),
                'HELP_SHOT_PASSWORD' => $password,
            ],
            null,
            600,
        );

        $node->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $node->isSuccessful()) {
            $this->error('Capture failed.');

            return self::FAILURE;
        }

        $results = json_decode(File::get($resultsPath), true) ?: [];
        $captured = $results['captured'] ?? [];
        $skipped = $results['skipped'] ?? [];

        $this->info(count($captured).' captured → public/images/help/');

        foreach ($skipped as $skip) {
            $this->warn('skipped '.$skip['slug'].' — '.$skip['reason']);
        }

        if ($skipped !== []) {
            $this->line('<fg=gray>A skipped topic keeps whatever screenshot it already had. '
                .'HelpCoverageTest fails on a doc referencing an image that was never captured.</>');
        }

        File::delete([$manifestPath, $resultsPath]);

        return self::SUCCESS;
    }
}
