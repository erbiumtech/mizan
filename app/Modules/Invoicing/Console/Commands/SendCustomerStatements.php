<?php

namespace App\Modules\Invoicing\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Services\CustomerStatement;
use App\Notifications\CustomerStatementIssued;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Every customer with activity gets last month's statement — `docs/erpnext-gap-plan.md` §4 item 1.
 *
 * Shaped like `SendOverdueReminders`, for the same reason that one is shaped as it is: a per-customer email
 * is a command and a notification, not a report schedule. Off until a company turns it on, with a dry run
 * that lists who would get one — it is the second thing in the application that emails somebody outside
 * the company.
 */
class SendCustomerStatements extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'invoicing:send-statements
                            {--from= : First day of the statement period. Defaults to the first of last month}
                            {--to= : Last day of the statement period. Defaults to the last of last month}
                            {--dry-run : List who would receive a statement without sending anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Email each customer with activity their statement of account for the period';

    public function handle(CustomerStatement $statements): int
    {
        if ($this->skipsDisabledModule('invoicing')) {
            return self::SUCCESS;
        }

        if (! setting('invoicing.statements_enabled', false)) {
            $this->line('Customer statements are switched off for this company (Settings → Customer statements).');

            return self::SUCCESS;
        }

        $from = $this->option('from') ?: now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $to = $this->option('to') ?: Carbon::parse($from)->endOfMonth()->toDateString();

        $due = $statements->due($from, $to);

        if ($due->isEmpty()) {
            $this->line("No customer had a movement or a balance between {$from} and {$to}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Customer', 'Email', 'Opening', 'Movements', 'Closing'],
            $due->map(fn (array $statement): array => [
                $statement['contact']->name,
                $statement['contact']->correspondenceEmail() ?? '— no address —',
                number_format($statement['opening'], 2),
                count($statement['lines']),
                number_format($statement['closing'], 2),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was sent.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $statement) {
            /** @var Contact $contact */
            $contact = $statement['contact'];
            $email = $contact->correspondenceEmail();

            // Listed above as having no address, and skipped here rather than failed: one customer without
            // an email must not stop the other forty from getting theirs.
            if (blank($email)) {
                continue;
            }

            Notification::route('mail', $email)->notify(new CustomerStatementIssued($contact, $from, $to));
            $sent++;
        }

        $this->info("Sent {$sent} statement(s) for {$from} to {$to}.");

        return self::SUCCESS;
    }
}
