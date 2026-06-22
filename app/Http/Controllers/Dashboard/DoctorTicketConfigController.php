<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\DoctorTicketConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DoctorTicketConfigController extends Controller
{
    /**
     * List ticket configs for a complex (only when complex uses ticket booking).
     */
    public function index(Request $request, $complexId)
    {
        $complex = Complex::findOrFail($complexId);
        if ($complex->booking_method !== 'tickets') {
            return response()->json(['ticket_configs' => []]);
        }

        $configs = DoctorTicketConfig::where('complex_id', $complexId)
            ->with('doctor:id,name')
            ->orderBy('doctor_id')
            ->get();

        return response()->json([
            'ticket_configs' => $configs,
        ]);
    }

    /**
     * Create or update ticket config for a doctor in this complex.
     */
    public function store(Request $request, $complexId)
    {
        $complex = Complex::findOrFail($complexId);
        if ($complex->booking_method !== 'tickets') {
            return response()->json([
                'message' => 'This complex does not use ticket booking.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'from_ticket' => 'required|integer|min:0',
            'to_ticket' => 'required|integer|min:0|gte:from_ticket',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $doctorId = $request->input('doctor_id');
        $exists = $complex->doctors()->where('doctors.id', $doctorId)->exists();
        if (!$exists) {
            return response()->json([
                'message' => 'Doctor is not assigned to this complex.',
            ], 422);
        }

        $config = DoctorTicketConfig::updateOrCreate(
            [
                'doctor_id' => $doctorId,
                'complex_id' => $complexId,
            ],
            [
                'from_ticket' => $request->input('from_ticket'),
                'to_ticket' => $request->input('to_ticket'),
                'is_active' => true,
            ]
        );

        $config->load('doctor:id,name');

        return response()->json([
            'message' => 'Ticket configuration saved.',
            'ticket_config' => $config,
        ], 201);
    }

    /**
     * Update a ticket config.
     */
    public function update(Request $request, $id)
    {
        $config = DoctorTicketConfig::with('doctor')->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'from_ticket' => 'sometimes|integer|min:0',
            'to_ticket' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('from_ticket')) {
            $config->from_ticket = $request->input('from_ticket');
        }
        if ($request->has('to_ticket')) {
            $config->to_ticket = $request->input('to_ticket');
            if ($config->from_ticket > $config->to_ticket) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['to_ticket' => ['To ticket must be greater than or equal to from ticket.']],
                ], 422);
            }
        }
        if ($request->has('is_active')) {
            $config->is_active = $request->boolean('is_active');
        }

        $config->save();

        return response()->json([
            'message' => 'Ticket configuration updated.',
            'ticket_config' => $config,
        ]);
    }
}
