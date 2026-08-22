<?php

namespace App\Modules\ConstructionContracts\Support;

use InvalidArgumentException;

/**
 * What `contract_standard` actually drives — `docs/construction-management-plan.md` §8.3.
 *
 * **Nothing else in the module branches on the standard.** That is the claim this class exists to make true:
 * one set of tables, two vocabularies, and every screen, form and notification that needs to say "Variation"
 * or "Change Order" asks here. A `match` on the standard scattered across thirty views is how a module comes
 * to call the same row three different things on three screens.
 *
 * The arithmetic is *not* here and must not come here. Retention release branches on
 * `retention_release_rule`, remeasurement on `measurement_basis` — both separate columns precisely because
 * they do not follow from the standard (§8.1: "AIA contracts are routinely unit-price, and FIDIC Yellow is
 * lump sum"). This object is words and number formats.
 */
final class ContractVocabulary
{
    public const FIDIC = 'fidic';

    public const AIA = 'aia';

    public const CUSTOM = 'custom';

    /**
     * §8.3's table, verbatim.
     *
     * @var array<string, array<string, string>>
     */
    private const WORDS = [
        self::FIDIC => [
            'standard' => 'FIDIC',
            'item_schedule' => 'Bill of Quantities',
            'item' => 'BoQ item',
            'change' => 'Variation',
            'change_short' => 'VO',
            'contractor_document' => 'Statement',
            'certifier_document' => 'Interim Payment Certificate',
            'certifier_document_short' => 'IPC',
            'certifier' => 'Engineer',
            'completion_event' => 'Taking-Over Certificate',
            'defects_period' => 'Defects Notification Period',
            'final' => 'Performance Certificate',
            'certificate_prefix' => 'IPC',
            'change_prefix' => 'VO',
            'claim_prefix' => 'STMT',
        ],
        self::AIA => [
            'standard' => 'AIA',
            'item_schedule' => 'Schedule of Values',
            'item' => 'SOV line',
            'change' => 'Change Order',
            'change_short' => 'CO',
            'contractor_document' => 'Application for Payment',
            'certifier_document' => 'Certificate for Payment',
            'certifier_document_short' => 'Certificate',
            'certifier' => 'Architect',
            'completion_event' => 'Substantial Completion',
            'defects_period' => 'Correction Period',
            'final' => 'Final Completion',
            'certificate_prefix' => 'APP',
            'change_prefix' => 'CO',
            'claim_prefix' => 'APP',
        ],
        self::CUSTOM => [
            'standard' => 'Contract',
            'item_schedule' => 'Contract Schedule',
            'item' => 'Schedule line',
            'change' => 'Change',
            'change_short' => 'CH',
            'contractor_document' => 'Payment Application',
            'certifier_document' => 'Payment Certificate',
            'certifier_document_short' => 'Certificate',
            'certifier' => 'Certifier',
            'completion_event' => 'Practical Completion',
            'defects_period' => 'Defects Period',
            'final' => 'Final Completion',
            'certificate_prefix' => 'PC',
            'change_prefix' => 'CH',
            'claim_prefix' => 'PA',
        ],
    ];

    /**
     * The retention release rule each standard defaults to — a **default**, not a consequence.
     *
     * §8.3's last row. It seeds the column when a contract is created and is never read afterwards: a FIDIC
     * contract with a negotiated single-stage release is ordinary, and reading the standard at release time
     * would quietly overrule what the parties agreed.
     *
     * @var array<string, string>
     */
    private const DEFAULT_RELEASE_RULE = [
        self::FIDIC => 'fidic_two_stage',
        self::AIA => 'aia_substantial',
        self::CUSTOM => 'single_stage',
    ];

    private function __construct(private string $standard) {}

    public static function for(string $standard): self
    {
        if (! array_key_exists($standard, self::WORDS)) {
            throw new InvalidArgumentException("{$standard} is not a contract standard this module knows.");
        }

        return new self($standard);
    }

    public function standard(): string
    {
        return $this->standard;
    }

    /** One word from the table above. Unknown keys throw rather than returning an empty string on a form. */
    public function word(string $key): string
    {
        return self::WORDS[$this->standard][$key]
            ?? throw new InvalidArgumentException("{$key} is not a vocabulary key.");
    }

    public function itemSchedule(): string
    {
        return $this->word('item_schedule');
    }

    public function change(): string
    {
        return $this->word('change');
    }

    public function certifierDocument(): string
    {
        return $this->word('certifier_document');
    }

    public function certifier(): string
    {
        return $this->word('certifier');
    }

    public function completionEvent(): string
    {
        return $this->word('completion_event');
    }

    public function defectsPeriod(): string
    {
        return $this->word('defects_period');
    }

    public function defaultReleaseRule(): string
    {
        return self::DEFAULT_RELEASE_RULE[$this->standard];
    }

    /**
     * A document number — `IPC-7`, `CO-12`.
     *
     * Not zero-padded, deliberately: a certificate number is quoted in correspondence and at adjudication as
     * the number it is, and `IPC-007` and `IPC-7` being the same certificate is a question nobody should have
     * to answer.
     */
    public function number(string $series, int $n): string
    {
        return $this->word($series.'_prefix').'-'.$n;
    }

    /** @return array<string, string> for a Filament select */
    public static function options(): array
    {
        return [
            self::FIDIC => 'FIDIC',
            self::AIA => 'AIA',
            self::CUSTOM => 'Bespoke',
        ];
    }
}
