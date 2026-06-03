<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

class PackageMode extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:mode
        {mode : local, staging, or production}
        {package : Composer package name (e.g. erbiumtech/hybrid-kanban)}
        {--path= : Local path to the package for local mode (e.g. ../hybrid-kanban)}
        {--repo= : GitHub repository URL for staging/production (e.g. https://github.com/erbium4sure/hybrid-kanban)}
        {--constraint= : Package version constraint (e.g. dev-master, ^1.0.0)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Switch a package between local, staging, or production mode';

    /**
     * Prompt for missing input arguments using Laravel Prompts.
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'mode' => fn () => select(
                label: 'Which mode do you want to switch to?',
                options: ['local', 'staging', 'production'],
            ),
            'package' => fn () => suggest(
                label: 'Which composer package?',
                options: $this->getInstalledPackages(),
                placeholder: 'e.g. erbiumtech/hybrid-kanban',
                required: true,
                validate: fn (string $value) => !str_contains($value, '/')
                    ? 'Package name must be in vendor/package format.'
                    : null,
            ),
        ];
    }

    /**
     * Prompt for missing options after arguments are resolved.
     */
    protected function afterPromptingForMissingArguments(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): void
    {
        $mode = $input->getArgument('mode');
        $package = $input->getArgument('package');
        $packageShortName = explode('/', $package)[1] ?? $package;

        if ($mode === 'local' && !$input->getOption('path')) {
            $input->setOption('path', text(
                label: 'Local path to the package',
                default: "../{$packageShortName}",
                required: true,
            ));
        }

        if (in_array($mode, ['staging', 'production']) && !$input->getOption('repo')) {
            $input->setOption('repo', text(
                label: 'GitHub repository URL',
                default: "https://github.com/erbium4sure/{$packageShortName}",
                required: true,
            ));
        }

        if (!$input->getOption('constraint')) {
            $input->setOption('constraint', select(
                label: 'Package version constraint',
                options: [
                    'dev-master' => 'dev-master (latest development)',
                    '^1.0.0' => '^1.0.0 (stable 1.x)',
                    'custom' => 'Enter custom version...',
                ],
                default: 'dev-master',
            ));

            if ($input->getOption('constraint') === 'custom') {
                $input->setOption('constraint', text(
                    label: 'Enter custom version constraint',
                    placeholder: 'e.g. ^2.0, dev-feature-branch',
                    required: true,
                ));
            }
        }
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mode = $this->argument('mode');
        $package = $this->argument('package');
        $constraint = $this->option('constraint') ?? 'dev-master';
        $composerFile = base_path('composer.json');

        if (!File::exists($composerFile)) {
            $this->error('composer.json not found!');
            return 1;
        }

        if (!str_contains($package, '/')) {
            $this->error('Invalid package name. Must be in vendor/package format.');
            return 1;
        }

        $composer = json_decode(File::get($composerFile), true);

        // Derive a slug for the repository key
        $slug = str_replace('/', '-', $package);
        $repoKey = "{$slug}-local";

        switch ($mode) {
            case 'local':
                $localPath = $this->option('path') ?? '../' . explode('/', $package)[1];
                $this->switchToLocal($composer, $package, $repoKey, $localPath, $constraint);
                break;

            case 'staging':
            case 'production':
                $repoUrl = $this->option('repo') ?? 'https://github.com/erbium4sure/' . explode('/', $package)[1];
                $this->switchToGitHub($composer, $package, $repoKey, $slug, $repoUrl, $constraint, $mode);
                break;

            default:
                $this->error("Invalid mode: {$mode}. Use: local, staging, or production");
                return 1;
        }

        // Save composer.json
        File::put($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("✅ Switched {$package} to {$mode} mode!");
        $this->line('');
        $this->info("Running: composer update {$package} --prefer-source --ignore-platform-reqs");
        $this->line('');

        $process = new Process(
            ['composer', 'update', $package, '--prefer-source', '--ignore-platform-reqs'],
            base_path()
        );
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->getOutput()->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('composer update failed!');
            return 1;
        }

        $this->line('');
        $this->info('✅ composer update completed!');

        return 0;
    }

    /**
     * Switch to local development mode.
     */
    private function switchToLocal(array &$composer, string $package, string $repoKey, string $localPath, string $constraint): void
    {
        $this->info("Switching to LOCAL mode (symlink from {$localPath})...");

        if (!isset($composer['repositories'])) {
            $composer['repositories'] = [];
        }

        $composer['repositories'][$repoKey] = [
            'type' => 'path',
            'url' => $localPath,
            'options' => [
                'symlink' => true,
            ],
        ];
        $this->line("  → Added local repository [{$repoKey}] → {$localPath}");

        $composer['require'][$package] = $constraint;
        $this->line("  → Set require [{$package}] → {$constraint}");
    }

    /**
     * Switch to GitHub mode (staging/production).
     */
    private function switchToGitHub(array &$composer, string $package, string $repoKey, string $slug, string $repoUrl, string $constraint, string $mode): void
    {
        $this->info("Switching to {$mode} mode (GitHub repository)...");

        if (isset($composer['repositories'][$repoKey])) {
            unset($composer['repositories'][$repoKey]);
            $this->line("  → Removed local repository [{$repoKey}]");
        }

        $composer['repositories'][$slug] = [
            'type' => 'vcs',
            'url' => $repoUrl,
        ];
        $this->line("  → Set VCS repository [{$slug}] → {$repoUrl}");

        $composer['require'][$package] = $constraint;
        $this->line("  → Set require [{$package}] → {$constraint}");
    }

    /**
     * Get installed erbiumtech packages from composer.json for autocomplete suggestions.
     */
    private function getInstalledPackages(): array
    {
        $composerFile = base_path('composer.json');

        if (!File::exists($composerFile)) {
            return [];
        }

        $composer = json_decode(File::get($composerFile), true);
        $packages = array_keys($composer['require'] ?? []);

        return array_values(array_filter($packages, fn (string $pkg) => str_starts_with($pkg, 'erbiumtech/')));
    }
}
