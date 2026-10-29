<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A company's wording for one notification.
 *
 * Placeholders are written {like_this} and filled from whatever the notification
 * knows: a template naming something the notification does not provide is left as it
 * is rather than blanked, because a visible {employee_name} in a test email is a
 * better failure than a sentence with a hole in it reaching an employee.
 */
class EmailTemplate extends Model
{
    use Auditable;

    /** The notifications that can be overridden, and what each can fill in. */
    public const PLACEHOLDERS = [
        'payslip_issued' => ['employee_name', 'period', 'net_salary', 'company'],
        'payslip_rejected' => ['employee_name', 'period', 'reason', 'company'],
        'expense_claim_submitted' => ['employee_name', 'approver_name', 'amount', 'description', 'claimed_on', 'company'],
        'expense_claim_decided' => ['employee_name', 'amount', 'description', 'decision', 'reason', 'company'],
        'employee_change_request' => ['employee_name', 'requester', 'company'],
        // A scheduled report, with the file attached — reports-expansion-plan.md Phase 8, item 7. No
        // recipient name among the placeholders on purpose: a recipient may be an address with no account
        // here, so there is no name to fill in and a greeting that said "Hello {employee_name}" would arrive
        // with the braces showing.
        'report_delivered' => ['report', 'period', 'company'],
        /*
         * An overdue reminder to a customer — `docs/erpnext-gap-plan.md` Phase 5.
         *
         * The one notification on this list that goes to somebody outside the company, which is why the
         * wording being a company's own matters more here than anywhere else: the shipped text is polite and
         * neutral, and a company that chases differently — or in Urdu — should not be sending ours.
         *
         * `contact_name` rather than `employee_name`: the reader is a customer. There is no amount for the
         * *account*, only for the invoice, because a reminder about one invoice is a reminder somebody can
         * act on and a statement of account is a different document (§4, item 1).
         */
        'invoice_overdue' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'days_overdue', 'reminder_level', 'company'],
        /*
         * The second and third reminders — docs/bexio-gap-plan.md Phase 1, item 3. The level is how many
         * times the invoice has already been chased, read off its own history; a company that writes
         * neither of these sends `invoice_overdue` every time, as it always did, and one that writes only
         * the third gets the shipped second letter in between.
         */
        'invoice_overdue_2' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'days_overdue', 'reminder_level', 'company'],
        'invoice_overdue_3' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'days_overdue', 'reminder_level', 'company'],
        // The monthly statement of account, PDF attached — docs/erpnext-gap-plan.md §4 item 1.
        'customer_statement' => ['contact_name', 'period', 'balance', 'company'],
        /*
         * The documents themselves, PDF attached — docs/bexio-gap-plan.md Phase 1, item 2. One key for
         * invoices and credit notes both: the placeholders are the same, and a company rewording one will
         * want the other to match. `due_date` is empty on a credit note.
         */
        'invoice_issued' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'company'],
        'quotation_issued' => ['contact_name', 'quotation_number', 'total', 'valid_until', 'company'],
        // The morning digest of next actions, to a member — docs/bexio-gap-plan.md Phase 2, item 6. The
        // list of actions is the notification's own and is not a placeholder: a company rewords the
        // framing, not the facts.
        'next_action_due' => ['employee_name', 'count', 'company'],
        // Access to a company for somebody outside it — Phase 2, item 10.
        'company_invitation' => ['name', 'company', 'roles', 'inviter'],
        // A certificate emailed to the person it is about, attached — Phase 5, item 18.
        'document_issued' => ['employee_name', 'document', 'company'],
        // A contact's contract or document about to lapse, to whoever manages contacts — §3.7. Once per
        // threshold crossed, never once per day.
        'contact_document_expiring' => ['employee_name', 'contact_name', 'document', 'expires_on', 'company'],
        /*
         * The rest of them — docs/saas-launch-plan.md §14.7, "email templates cover every mail the app
         * sends". Three were already overridable and simply never listed here (`announcement_published`,
         * `leave_request_decided`, `offer_issued`); the others were wired at the same time.
         *
         * Most of these are internal: an alert to whoever runs payroll or watches the sites. A company does
         * not usually want to reword those, and the point of listing them is not that it will — it is that
         * the one it does want to reword is already here rather than being a deployment away.
         * `NotificationCoverageTest` keeps the two lists in step in both directions.
         *
         * `employee_name` is the reader throughout, as it is above. Where a mail is *about* somebody else —
         * a claim to approve, an expiring document — that person has their own placeholder.
         */
        'announcement_published' => ['employee_name', 'title', 'company'],
        'leave_request_decided' => ['employee_name', 'leave_type', 'period', 'decision', 'reason', 'company'],
        'offer_issued' => ['candidate_name', 'position', 'company'],
        'payslip_objection_answered' => ['employee_name', 'period', 'reason', 'reply', 'company'],
        'payslip_returned_for_review' => ['employee_name', 'period', 'note', 'net_salary', 'company'],
        'leave_request_submitted' => ['employee_name', 'approver_name', 'leave_type', 'period', 'days', 'company'],
        'employee_document_expiring' => ['employee_name', 'recipient_name', 'document', 'expires_on', 'days', 'company'],
        'compliance_document_expiring' => ['employee_name', 'document', 'party', 'expires_on', 'days', 'contract', 'company'],
        'competency_expiring' => ['employee_name', 'person', 'competency', 'expires_on', 'days', 'company'],
        'certificate_expiring' => ['employee_name', 'project', 'environment', 'expires_on', 'days', 'company'],
        'environment_down' => ['employee_name', 'project', 'environment', 'since', 'duration', 'company'],
        'environment_recovered' => ['employee_name', 'project', 'environment', 'downtime', 'company'],
        'device_silent' => ['employee_name', 'device', 'serial', 'last_seen', 'company'],
        'reconciliation_unbalanced' => ['period', 'difference', 'job_cost', 'gl_cost', 'company'],
        'delay_notice_due' => ['employee_name', 'reference', 'event', 'job', 'notice_by', 'days', 'company'],
        'scheduled_report_failed' => ['report', 'timetable', 'error', 'company'],
        // The one that leaves the building: a customer being asked to confirm a rep's visit.
        'visit_confirmation_request' => ['visitor', 'visited_on', 'company'],
    ];

    protected $fillable = ['key', 'subject', 'greeting', 'body', 'action_label', 'closing', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The company's override for this notification, if it has written one. */
    public static function for(string $key): ?self
    {
        return static::active()->where('key', $key)->first();
    }

    /**
     * One field with its placeholders filled.
     *
     * Null when the template does not set this field, which the caller reads as "use
     * your own wording" — a company that only wants to change the subject line should
     * not have to retype the body.
     *
     * @param  array<string, string|null>  $values
     */
    public function render(string $field, array $values): ?string
    {
        $text = trim((string) ($this->{$field} ?? ''));

        if ($text === '') {
            return null;
        }

        foreach ($values as $placeholder => $value) {
            if ($value === null) {
                continue;
            }

            $text = str_replace('{'.$placeholder.'}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Stand-in values for a preview, by placeholder name.
     *
     * Made up on purpose. A preview filled from a real employee's real payslip is a
     * real payslip on screen for whoever is editing the wording, and the thing being
     * checked here is the sentence, not the figure.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        $samples = [
            'employee_name' => 'Ayesha Khan',
            'recipient_name' => 'Ayesha Khan',
            'approver_name' => 'Bilal Ahmed',
            'contact_name' => 'Northern Traders',
            'candidate_name' => 'Hassan Raza',
            'person' => 'Imran Shah',
            'visitor' => 'Bilal Ahmed',
            'requester' => 'Ayesha Khan',
            'company' => Company::current()?->name ?? 'Your company',
            'period' => 'March 2026',
            'month' => 'March 2026',
            'amount' => '45,000.00',
            'net_salary' => '182,500.00',
            'total' => '96,000.00',
            'balance' => '310,400.00',
            'difference' => '12,750.00',
            'job_cost' => '2,410,000.00',
            'gl_cost' => '2,422,750.00',
            'description' => 'Client dinner, Lahore',
            'decision' => 'approved',
            'reason' => 'Outside the policy limit',
            'reply' => 'The overtime was paid in the following month.',
            'note' => 'Medical allowance corrected.',
            'document' => 'Passport',
            'competency' => 'Confined space entry',
            'event' => 'Late possession of the site',
            'job' => 'J-104',
            'project' => 'Billing platform',
            'environment' => 'Production',
            'device' => 'Main gate reader',
            'serial' => 'ZK-8827341',
            'report' => 'Trial balance',
            'timetable' => 'Monthly, on the 1st',
            'error' => 'The mail server refused the connection.',
            'invoice_number' => 'INV-2026-0188',
            'quotation_number' => 'QT-2026-0042',
            'position' => 'Site engineer',
            'title' => 'Office closed on Friday',
            'leave_type' => 'Annual leave',
            'days' => '3',
            'days_overdue' => '12',
            'reminder_level' => '2',
            'count' => '4',
            'roles' => 'Accountant',
            'inviter' => 'Bilal Ahmed',
            'name' => 'Ayesha Khan',
            'party' => 'Northern Traders',
            'contract' => 'SC-2026-011',
            'reference' => 'DE-4',
            'size' => '18.4 MB',
        ];

        $dates = ['expires_on', 'due_date', 'valid_until', 'claimed_on', 'notice_by', 'visited_on', 'last_seen', 'since'];

        return collect(self::PLACEHOLDERS[$this->key] ?? [])
            ->mapWithKeys(fn (string $placeholder): array => [
                $placeholder => $samples[$placeholder]
                    ?? (in_array($placeholder, $dates, true) ? now()->format('d M Y') : ucfirst(str_replace('_', ' ', $placeholder))),
            ])
            ->all();
    }

    /**
     * The body as paragraphs.
     *
     * @param  array<string, string|null>  $values
     * @return array<int, string>
     */
    public function paragraphs(array $values): array
    {
        $body = $this->render('body', $values);

        if ($body === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R+/', $body))));
    }
}
