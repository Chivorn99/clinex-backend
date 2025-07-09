<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Simulate a more realistic AI output based on the provided PDFs
        $simulatedAiData = [
            'entities' => [
                'ឈ្មោះ/Name' => 'HORN BUN HACH / ENG SOK IM',
                'Patient ID' => 'PT001868 / PT001865',
                'Collected Date' => '17/03/2024 10:53',
                'អាយុ/Age' => '19 Y, 6 M, 21 D',
                'Lab ID' => 'LT001209 / LT001213',
                'ភេទ/Gender' => 'Male',
            ],
            'tables' => [
                [
                    'index' => 0,
                    'name' => 'Unmapped Table 1 (BIOCHIMISTRY)',
                    'headers' => ['Results', 'Unit', 'Reference Range', 'Flag'],
                    'rows' => [
                        ['Creatinine, serum', ': 0.9', 'mg/dL', '(0.9 - 1.1)'],
                        ['Urea/BUN', ': 27', 'mg/dL', '(6.0 40.0 )'],
                    ]
                ],
                [
                    'index' => 1,
                    'name' => 'Unmapped Table 2 (HEMATOLOGY)',
                    'headers' => ['Results', 'Unit', 'Reference Range', 'Flag'],
                    'rows' => [
                        ['WBC', ': 6.3', '10⁹/L', '(3.5-10.0)'],
                        ['LYM%', ': 33.9', '응', '(15.0-50.0)'],
                    ]
                ],
                [
                    'index' => 2,
                    'name' => 'Unmapped Table 3 (SERO / IMMUNOLOGY)',
                    'headers' => ['Column 1', 'Column 2', 'Column 3', 'Column 4', 'Column 5'],
                    'rows' => [
                        ['Helicobacter Pylori', '1gM', ',', 'Serum', 'NEGATIVE'],
                        ['Widal (TH)', '', '', '', ': 1/160'],
                    ]
                ]
            ]
        ];

        $clinexFields = [
            'patient_info' => ['patient_id', 'name', 'age', 'gender', 'lab_id'],
            'table_columns' => ['test_name', 'value', 'unit', 'reference_range', 'flag'],
        ];

        return view('templates.create', [
            'aiData' => $simulatedAiData,
            'clinexFields' => $clinexFields
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Template $template)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Template $template)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Template $template)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Template $template)
    {
        //
    }
}
