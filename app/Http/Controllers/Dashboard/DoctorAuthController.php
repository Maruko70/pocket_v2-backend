<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorUser;
use App\Rules\SudanPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class DoctorAuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', new SudanPhoneNumber()],
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $normalizedPhone = SudanPhoneNumber::normalize($request->phone);

        $doctorUser = DoctorUser::where('phone', $normalizedPhone)->with('doctor.complexes', 'doctor.majors')->first();

        if (!$doctorUser || !Hash::check($request->password, $doctorUser->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $doctorUser->createToken('doctor_portal_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $doctorUser->load('doctor'),
            'doctor' => $doctorUser->doctor->load(['complexes', 'majors']),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $accessToken->delete();
            }
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'message' => 'Invalid token',
            ], 401);
        }

        $user = $accessToken->tokenable;

        if (!$user || !($user instanceof DoctorUser)) {
            return response()->json([
                'message' => 'Unauthorized. Doctor access required.',
            ], 403);
        }

        $user->load(['doctor.complexes', 'doctor.majors', 'doctor.weeklySchedule']);

        return response()->json([
            'user' => $user,
            'doctor' => $user->doctor,
        ]);
    }
}
