@php
    use App\Models\EmployeeChangeRequest;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;

    $labels = [
        'user_name' => 'Name',
        'user_email' => 'Company Email',
        'personal_email' => 'Personal Email',
        'nic_front' => 'NIC (Front)',
        'nic_back' => 'NIC (Back)',
    ];
    $requested = (array) ($record->requested_changes ?? []);
    $original = (array) ($record->original_values ?? []);
    $format = fn ($v) => (is_null($v) || $v === '') ? '—' : (is_array($v) ? json_encode($v) : (string) $v);

    // Uploads are stored as paths, and a filename tells the reviewer nothing —
    // they need to see the scan to judge it. The URL comes off the `public`
    // disk, so it resolves through the access-checked file route.
    $isImage = fn ($key) => in_array($key, EmployeeChangeRequest::IMAGE_FIELDS, true);
    $imageUrl = fn ($path) => (is_string($path) && $path !== '' && Storage::disk('public')->exists($path))
        ? Storage::disk('public')->url($path)
        : null;
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
                        @php
                            $oldValue = $original[$key] ?? null;
                        @endphp
                        <tr>
                            <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;font-weight:600;white-space:nowrap;vertical-align:top">
                                {{ $labels[$key] ?? Str::headline($key) }}
                            </td>

                            @if ($isImage($key))
                                @php
                                    $oldUrl = $imageUrl($oldValue);
                                    $newUrl = $imageUrl($newValue);
                                @endphp
                                <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;vertical-align:top">
                                    @if ($oldUrl)
                                        <a href="{{ $oldUrl }}" target="_blank" rel="noopener">
                                            <img src="{{ $oldUrl }}" alt="Current {{ $labels[$key] ?? $key }}"
                                                 style="max-height:130px;max-width:220px;border:1px solid #e5e7eb;border-radius:6px;opacity:.55">
                                        </a>
                                    @else
                                        <span style="color:#9ca3af">—</span>
                                    @endif
                                </td>
                                <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;vertical-align:top">
                                    @if ($newUrl)
                                        <a href="{{ $newUrl }}" target="_blank" rel="noopener">
                                            <img src="{{ $newUrl }}" alt="Requested {{ $labels[$key] ?? $key }}"
                                                 style="max-height:130px;max-width:220px;border:2px solid rgb(var(--primary-500,217 119 6));border-radius:6px">
                                        </a>
                                    @else
                                        {{-- Referenced but no longer on disk; say so rather than showing a broken image. --}}
                                        <span style="color:#b45309">file missing ({{ $format($newValue) }})</span>
                                    @endif
                                </td>
                            @else
                                <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;color:#9ca3af;text-decoration:line-through;vertical-align:top">
                                    {{ $format($oldValue) }}
                                </td>
                                <td style="padding:.55rem .5rem;border-bottom:1px solid #f3f4f6;color:rgb(var(--primary-600,217 119 6));font-weight:600;vertical-align:top">
                                    {{ $format($newValue) }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
