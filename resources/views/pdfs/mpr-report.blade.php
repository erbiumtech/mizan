<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Progress Report Export</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @page {
            size: a4 {{ $mode === 'comparison' ? 'landscape' : 'portrait' }};
            margin-top: 0;
            margin-bottom: 0;
            margin-left: 15mm;
            margin-right: 15mm;
        }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            background-color: #ffffff;
            margin: 0 !important;
            padding: 0 !important;
        }
        .print-avoid-break {
            break-inside: avoid-page !important;
            page-break-inside: avoid !important;
        }

        /* NATIVE PRINT ENGINE MANAGEMENT */
        .page-wrapper-table {
            width: 100%;
            border-collapse: collapse !important;
            border: none !important;
        }
        .page-wrapper-table td {
            border: none !important;
            padding: 0 !important;
        }

        /* Har page k top pr barabar space banane k liye native spacer */
        .header-space {
            height: 10mm;
        }

        /* Footer zone size reserved perfectly inside table layout */
        .footer-space {
            height: 32mm;
        }

        /* STANDARD FIXED FOOTER LOCK AT THE VERY EDGE */
        .fixed-print-footer {
            position: fixed;
            bottom: 0 !important;
            left: -15mm !important;
            width: calc(100% + 30mm) !important;
            height: 25mm;
            background-color: #ffffff;
            padding-left: 15mm !important;
            padding-bottom: 5mm !important;
            box-sizing: border-box;
        }

        /* SAMPLE MATCHING EXACT SPECIFICATIONS */
        .sample-table {
            border: 1px solid #475569 !important;
            border-collapse: collapse !important;
            width: 100% !important;
        }
        .sample-table td {
            border: 1px solid #475569 !important;
            padding: 10px 14px !important;
            font-size: 14px !important;
        }

        /* 💡 FIX: Internal padding tight kar di taake box ke andar upar/neeche ki faltu space khatam ho jaye */
        .content-box {
            border: 1px solid #475569 !important;
            padding: 8px 14px !important; /* Top/Bottom 8px aur Left/Right 14px kiya */
            background-color: #ffffff;
            width: 100% !important;
            font-size: 14px !important;
        }
    </style>
</head>
<body class="bg-white text-slate-900 antialiased">

    <table class="page-wrapper-table">
        <thead>
            <tr>
                <td>
                    <div class="header-space"></div>
                </td>
            </tr>
        </thead>

        <tbody>
            <tr>
                <td>
                    <div class="mb-6 w-full print-avoid-break">
                            <div class="flex justify-end w-full mb-2">
                                <img src="https://4sure.ch/wp-content/uploads/2025/06/cropped-211129_4sure_def-scaled-1-150x50.png" alt="4Sure AG" class="h-11 w-auto object-contain">
                            </div>
                        <div class="w-full">
                            <h2 class="text-xl font-bold text-slate-900 tracking-tight">
                                MPR ErbiumTech / 4sure AG
                            </h2>
                        </div>
                    </div>

                    @if($mode === 'single')
                    <div class="space-y-4">
                        <div class="w-full print-avoid-break">
                            <table class="sample-table">
                                <tbody>
                                    <tr>
                                        <td class="font-bold text-slate-800 w-1/4 bg-slate-50/50">Name</td>
                                        <td class="text-slate-900 font-medium">{{ $reportFields['User Name'] ?? '---' }}</td>
                                    </tr>
                                    <tr>
                                        <td class="font-bold text-slate-800 w-1/4 bg-slate-50/50">Date</td>
                                        <td class="text-slate-900 font-medium">{{ $reportFields['MPR Date'] ?? '---' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        @foreach($contentLabels as $id => $label)
                        <div class="print-avoid-break">
                            <h3 class="text-base font-bold text-slate-900 mb-1.5 break-after-avoid">
                                {{ $id }}. {{ $label }}
                            </h3>
                            <div class="content-box text-slate-800 leading-relaxed whitespace-pre-line">
                                {{ $reportFields[$label] ?? '---' }}
                            </div>
                        </div>
                        @endforeach

                        <div class="pt-2 print-avoid-break">
                            <p class="text-base font-bold text-fuchsia-600 flex items-center gap-1">
                                Thank you 😊
                            </p>
                        </div>
                    </div>
                    @endif

                    @if($mode === 'comparison')
                    <div class="space-y-6">

                        <div class="grid grid-cols-2 gap-6 w-full print-avoid-break">
                            <div class="px-1">
                                <span class="text-xs font-black bg-slate-700 text-white px-3 py-1.5 rounded shadow-sm border border-slate-800 uppercase tracking-wider">
                                    ← Previous Record ({{ $previous['MPR Date'] ?? '---' }})
                                </span>
                            </div>
                            <div class="px-1">
                                <span class="text-xs font-black bg-slate-700 text-white px-3 py-1.5 rounded shadow-sm border border-slate-800 uppercase tracking-wider">
                                    Latest Record ({{ $latest['MPR Date'] ?? '---' }}) →
                                </span>
                            </div>
                        </div>

                        <div class="space-y-6 w-full">
                            <div class="grid grid-cols-2 gap-8 w-full items-stretch print-avoid-break">
                                <div class="bg-white h-full flex">
                                    <table class="sample-table">
                                        <tbody>
                                            <tr>
                                                <td class="font-bold text-slate-800 w-1/3 bg-slate-50/50">Name</td>
                                                <td class="text-slate-900 font-medium">{{ $previous['User Name'] ?? '---' }}</td>
                                            </tr>
                                            <tr>
                                                <td class="font-bold text-slate-800 w-1/3 bg-slate-50/50">Date</td>
                                                <td class="text-slate-900 font-medium">{{ $previous['MPR Date'] ?? '---' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="bg-white h-full flex">
                                    <table class="sample-table">
                                        <tbody>
                                            <tr>
                                                <td class="font-bold text-slate-800 w-1/3 bg-slate-50/50">Name</td>
                                                <td class="text-slate-900 font-semibold">{{ $latest['User Name'] ?? '---' }}</td>
                                            </tr>
                                            <tr>
                                                <td class="font-bold text-slate-800 w-1/3 bg-slate-50/50">Date</td>
                                                <td class="text-slate-900 font-semibold">{{ $latest['MPR Date'] ?? '---' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            @foreach($contentLabels as $id => $label)
                            <div class="space-y-1.5 print-avoid-break">
                                <h3 class="text-sm font-bold text-slate-900 break-after-avoid">{{ $id }}. {{ $label }}</h3>

                                <div class="grid grid-cols-2 gap-8 w-full items-stretch">
                                    <div class="content-box text-slate-800 leading-relaxed whitespace-pre-line">
                                        {{ $previous[$label] ?? '---' }}
                                    </div>

                                    <div class="content-box text-slate-900 font-medium leading-relaxed whitespace-pre-line">
                                        {{ $latest[$label] ?? '---' }}
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <div class="pt-4 print-avoid-break">
                            <p class="text-sm font-bold text-fuchsia-600 flex items-center gap-1">
                                Thank you 😊
                            </p>
                        </div>
                    </div>
                    @endif
                </td>
            </tr>
        </tbody>

        <tfoot>
            <tr>
                <td>
                    <div class="footer-space"></div>
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="fixed-print-footer text-[10px] text-slate-400 font-medium">
        <p class="text-fuchsia-500 font-bold text-[11px] mb-0.5">4sure AG</p>
        <p>Steinengraben 81, 4051 Basel, Switzerland</p>
        <p class="text-[9px] mt-0.5 text-slate-500 font-semibold tracking-wide">CHE-193.035.529</p>
    </div>

</body>
</html>
