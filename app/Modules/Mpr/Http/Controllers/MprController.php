<?php

namespace App\Modules\Mpr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mpr;
use App\Modules\Mpr\Services\MprPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MprController extends Controller
{
    /**
     * 1. Get All MPRs (Clean List with User Name)
     */
    public function index(Request $request)
    {
        $userName = $request->user()->name;

        $mprs = Mpr::where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($mpr) use ($userName) {

                $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
                $fileName = 'Mpr/'.$cleanName.'_'.time().'.pdf';

                if (! $mpr->pdf_path || ! Storage::disk('public')->exists($mpr->pdf_path)) {
                    $pdfService = new MprPdfService;
                    $result = $pdfService->generateSingleReport($mpr->toArray());

                    if (! Storage::disk('public')->exists('Mpr')) {
                        Storage::disk('public')->makeDirectory('Mpr');
                    }

                    $result['pdf']->save(storage_path('app/public/'.$fileName));
                    $mpr->update(['pdf_path' => $fileName]);
                } else {
                    $fileName = $mpr->pdf_path;
                }

                return [
                    'id' => $mpr->id,
                    'title' => $mpr->title,
                    'remarks' => $mpr->remarks,
                    'pdf_url' => url(Storage::url($fileName)),
                ];
            });

        return response()->json([
            'success' => true,
            'count' => $mprs->count(),
            'data' => $mprs,
        ], 200);
    }

    /**
     * 2. Get Comparison Report (Temporary Files)
     */
    public function comparison(Request $request)
    {
        $userName = $request->user()->name;
        $cleanName = str_replace([' ', '/', '\\'], '_', $userName);

        $pdfService = new MprPdfService;
        $result = $pdfService->generateComparisonReport($request->user()->id);

        if (! $result || $result['empty']) {
            return response()->json(['success' => false, 'message' => 'This user has no MPR Record for comparison'], 400);
        }

        $customFileName = 'Mpr/'.$cleanName.'_Comparison_'.time().'_'.uniqid().'.pdf';

        if (! Storage::disk('public')->exists('Mpr')) {
            Storage::disk('public')->makeDirectory('Mpr');
        }

        $result['pdf']->save(storage_path('app/public/'.$customFileName));

        return response()->json([
            'success' => true,
            'message' => 'Comparison report generated successfully',
            'pdf_url' => url(Storage::url($customFileName)),
        ], 200);
    }

    /**
     * 3. Get a Single MPR (Clean Content with User Name)
     */
    public function show(Request $request, $id)
    {
        $mpr = Mpr::where('id', $id)->where('user_id', $request->user()->id)->first();

        if (! $mpr) {
            return response()->json(['success' => false, 'message' => 'MPR not found or unauthorized'], 404);
        }

        $userName = $request->user()->name;

        if ($mpr->pdf_path && Storage::disk('public')->exists($mpr->pdf_path)) {
            $fileName = $mpr->pdf_path;
        } else {
            $pdfService = new MprPdfService;
            $result = $pdfService->generateSingleReport($mpr->toArray());

            $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
            $fileName = 'Mpr/'.$cleanName.'_'.time().'.pdf';

            if (! Storage::disk('public')->exists('Mpr')) {
                Storage::disk('public')->makeDirectory('Mpr');
            }

            $result['pdf']->save(storage_path('app/public/'.$fileName));
            $mpr->update(['pdf_path' => $fileName]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $mpr->id,
                'title' => $mpr->title,
                'remarks' => $mpr->remarks,
                'pdf_url' => url(Storage::url($fileName)),
            ],
        ], 200);
    }
}
