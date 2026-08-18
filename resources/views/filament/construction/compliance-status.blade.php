{{--
    A subcontract's compliance, as at a date — `docs/construction-management-plan.md` §12.

    Rendered from `ComplianceService::statusFor()`, which is where the whole rule lives. Nothing here is stored:
    every status on this list is derived from the document's dates against the date being asked about, because
    §12 names a stored status as the most dangerous silent failure on the payable side.

    **Every requirement is listed, including the ones with no document at all.** A screen that showed only the
    documents it had would be a screen of good news, and the absence is the finding.
--}}
<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        As at <span class="font-medium text-gray-950 dark:text-white">{{ $asOf->format('d M Y') }}</span>.
        Statuses are worked out from the documents' dates, never stored — so this answers
        &ldquo;was the cover in place then&rdquo;, not only &ldquo;is it in place now&rdquo;.
    </p>

    @if (count($rows) === 0)
        <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
            No compliance requirements apply to this contract. Nothing will stop a certificate on compliance
            grounds until a requirement exists — set one on the contract, or a company template that covers every
            subcontract.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Required</th>
                        <th class="py-2 pr-3 font-medium">Document</th>
                        <th class="py-2 pr-3 font-medium">Status</th>
                        <th class="py-2 pr-3 font-medium">Expires</th>
                        <th class="py-2 font-medium">Stops a certificate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">
                                {{ $row['requirement']->label() }}
                                @if ($row['requirement']->minimum_cover)
                                    <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                        at least {{ number_format((float) $row['requirement']->minimum_cover, 0) }}
                                    </span>
                                @endif
                            </td>

                            <td class="py-2 pr-3 text-gray-600 dark:text-gray-400">
                                {{ $row['document']?->reference ?? $row['document']?->issuer ?? '—' }}
                            </td>

                            <td class="py-2 pr-3">
                                @php
                                    // The colours follow the meaning rather than the wording: anything that is not
                                    // a live, read, sufficient document is a problem, and only its severity differs.
                                    $tone = match ($row['status']) {
                                        'valid' => 'text-success-600 dark:text-success-400',
                                        'expiring' => 'text-warning-600 dark:text-warning-400',
                                        'waived' => 'text-gray-500 dark:text-gray-400',
                                        'expired', 'missing', 'insufficient_cover' => 'text-danger-600 dark:text-danger-400',
                                        default => 'text-warning-600 dark:text-warning-400',
                                    };
                                @endphp
                                <span class="font-medium {{ $tone }}">
                                    {{ str_replace('_', ' ', $row['status']) }}
                                </span>
                            </td>

                            <td class="py-2 pr-3 text-gray-600 dark:text-gray-400">
                                @if ($row['document']?->expires_on)
                                    {{ $row['document']->expires_on->format('d M Y') }}
                                    @if ($row['days_until_expiry'] !== null)
                                        {{-- Signed, so a lapse reads as a lapse rather than as a very short deadline. --}}
                                        <span class="block text-xs">
                                            {{ $row['days_until_expiry'] < 0
                                                ? abs($row['days_until_expiry']).' days ago'
                                                : 'in '.$row['days_until_expiry'].' days' }}
                                        </span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>

                            <td class="py-2">
                                @if ($row['blocks_certification'])
                                    <span class="font-medium text-danger-600 dark:text-danger-400">Yes</span>
                                @elseif ($row['requirement']->blocksCertification())
                                    <span class="text-gray-500 dark:text-gray-400">No — satisfied</span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">No — chased only</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($blockers !== [])
            <div class="rounded-lg bg-danger-50 p-4 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                A certificate valued to this date will be refused: {{ implode('; ', $blockers) }}. Either the
                document is produced, or somebody holding the override permission certifies anyway and says why —
                which is recorded against that certificate.
            </div>
        @endif
    @endif
</div>
