<?php

namespace App\Http\Controllers;

use App\Models\DoctorUser;
use App\Models\User;
use App\Rules\SudanPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'unique:users,phone', new SudanPhoneNumber()],
            'date_of_birth' => 'required|date|before:today',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'phone' => SudanPhoneNumber::normalize($request->phone),
            'date_of_birth' => $request->date_of_birth,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

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
        $user = User::where('phone', $normalizedPhone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Unified login: backend determines if credentials belong to a patient or doctor.
     * Returns type ('patient'|'doctor'), token, and user/doctor data.
     */
    public function loginUnified(Request $request)
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

        // Check doctor first
        $doctorUser = DoctorUser::where('phone', $normalizedPhone)
            ->with('doctor.complexes', 'doctor.majors')
            ->first();

        if ($doctorUser && Hash::check($request->password, $doctorUser->password)) {
            $token = $doctorUser->createToken('doctor_portal_token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'type' => 'doctor',
                'token' => $token,
                'user' => $doctorUser->load('doctor'),
                'doctor' => $doctorUser->doctor->load(['complexes', 'majors']),
            ]);
        }

        // Check patient
        $user = User::where('phone', $normalizedPhone)->first();

        if ($user && Hash::check($request->password, $user->password)) {
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'type' => 'patient',
                'token' => $token,
                'user' => $user,
            ]);
        }

        return response()->json([
            'message' => 'Invalid credentials',
        ], 401);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', new SudanPhoneNumber()],
            'date_of_birth' => 'required|date|before:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $normalizedPhone = SudanPhoneNumber::normalize($request->phone);
        $user = User::where('phone', $normalizedPhone)->first();

        if (!$user) {
            // Don't reveal if user exists for security
            return response()->json([
                'message' => 'Invalid phone number or date of birth',
            ], 422);
        }

        // Verify date of birth (compare dates only, ignore time)
        $providedDob = \Carbon\Carbon::parse($request->date_of_birth)->format('Y-m-d');
        $userDob = $user->date_of_birth ? $user->date_of_birth->format('Y-m-d') : null;

        if (!$userDob || $providedDob !== $userDob) {
            return response()->json([
                'message' => 'Invalid phone number or date of birth',
            ], 422);
        }

        // Generate a temporary token for password reset
        $resetToken = Str::random(64);
        Cache::put("password_reset_token_{$resetToken}", $normalizedPhone, now()->addMinutes(15));

        return response()->json([
            'message' => 'Verification successful',
            'reset_token' => $resetToken,
        ], 200);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = Cache::get("password_reset_token_{$request->reset_token}");

        if (!$phone) {
            return response()->json([
                'message' => 'Invalid or expired reset token',
            ], 422);
        }

        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete reset token
        Cache::forget("password_reset_token_{$request->reset_token}");

        // Revoke all existing tokens for security
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully',
        ], 200);
    }
}

