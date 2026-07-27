@php
    use Illuminate\Support\Str;

    $labels = ['user_name' => 'Name', 'user_email' => 'Email'];
    $requested = (array) ($record->requested_changes ?? []);
    $original = (array) ($record->original_values ?? []);
    $format = fn ($v) => (is_null($v) || $v === '') ? '—' : (is_array($v) ? json_encode($v) : (string) $v);
@endphp

<div class="fi-modal-content" style="font-size:.875rem">
    @if (empty($requested))
        <p style="color:#6b7280">No field changes recorded.</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;color:#6b7280;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em">
                        <th style="padding:.5rem .5rem;border-bottom:1px solid #e5e7eb">Field</th>
                        <th style="padding:.5rem .5rem;border-bottom:1px solid #e5e7eb">Current</th>
                        <th style="padding:.5rem .5rem;border-bottom:1px solid #e5e7eb">Requested</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requested as $key => $newValue)
                        <tr>
                            <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;font-weight:600;white-space:nowrap">
                                {{ $labels[$key] ?? Str::headline($key) }}
                            </td>
                            <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;color:#9ca3af;text-decoration:line-through">
                                {{ $format($original[$key] ?? null) }}
                            </td>
                            <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;color:rgb(var(--primary-600,217 119 6));font-weight:600">
                                {{ $format($newValue) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
