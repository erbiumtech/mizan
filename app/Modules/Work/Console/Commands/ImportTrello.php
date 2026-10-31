<?php

namespace App\Modules\Work\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Imports\TrelloImport;
use Illuminate\Console\Command;
use RuntimeException;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * `work:import-trello {file} --project=CODE --tenant=ID` — docs/project-management-plan.md §8.9.
 *
 * Trello's export is JSON rather than a spreadsheet, so it does not go through the CSV page; it
 * is a file an administrator runs once, which is what a command is for. Lists become statuses
 * by name, cards become items, labels become labels, and the card id is kept in `imported_from`
 * so running it twice corrects rather than duplicates. Attachments keep their URLs in the
 * description (§16.6).
 */
class ImportTrello extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'work:import-trello {file : Path to the board\'s JSON export} {--project= : The project code or key to import into} {--tenant=*}';

    protected $description = 'Import a Trello board export into a project\'s backlog';

    public function handle(TrelloImport $import): int
    {
        if ($this->skipsDisabledModule('work')) {
            return self::SUCCESS;
        }

        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $project = Project::query()
            ->where('code', $this->option('project'))
            ->orWhere('key', strtoupper((string) $this->option('project')))
            ->first();

        if ($project === null) {
            $this->error('No project has the code or key "'.$this->option('project').'". Pass --project=.');

            return self::FAILURE;
        }

        try {
            $result = $import->run($project, (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            $this->error('That is not a Trello JSON export: '.$e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['imported']} card(s) imported into {$project->name}; {$result['skipped']} skipped.");

        foreach ($result['notes'] as $note) {
            $this->line('  '.$note);
        }

        return self::SUCCESS;
    }
}
