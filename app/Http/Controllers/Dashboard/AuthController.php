<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Rules\SudanPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
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

        // Normalize phone number
        $normalizedPhone = SudanPhoneNumber::normalize($request->phone);

        $admin = AdminUser::where('phone', $normalizedPhone)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $admin->createToken('dashboard_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $admin,
        ]);
    }

    public function logout(Request $request)
    {
        // Get the bearer token
        $token = $request->bearerToken();
        
        if ($token) {
            // Find and delete the token
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
        // Get the bearer token
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Find the token
        $accessToken = PersonalAccessToken::findToken($token);
        
        if (!$accessToken) {
            return response()->json([
                'message' => 'Invalid token',
            ], 401);
        }

        // Get the tokenable model (should be AdminUser)
        $user = $accessToken->tokenable;
        
        // Verify it's an AdminUser
        if (!$user || !($user instanceof AdminUser)) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        return response()->json([
            'user' => $user,
        ]);
    }
}
