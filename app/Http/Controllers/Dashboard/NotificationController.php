<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Doctor;
use App\Models\User;
use App\Services\FcmService;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NotificationController extends Controller
{
    public function __construct(protected FcmService $fcmService)
    {
    }

    public function send(Request $request)
    {
        $validated = $request->validate([
            'target_type' => 'required|in:user,doctor,all',
            'target_id' => 'nullable|integer',
            'title' => 'required|string|max:191',
            'body' => 'required|string',
            'image_url' => 'nullable|string|max:2048',
        ]);

        $targetType = $validated['target_type'];
        $title = $validated['title'];
        $body = $validated['body'];
        $imageUrl = $validated['image_url'] ?? null;

        $tokens = [];
        $userIds = [];

        if ($targetType === 'user') {
            $user = User::findOrFail($validated['target_id']);
            $userIds[] = $user->id;
            $tokens = DeviceToken::where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('token')
                ->all();
        } elseif ($targetType === 'doctor') {
            $doctor = Doctor::findOrFail($validated['target_id']);
            // Option A: notify this doctor only if they have a user account
            if ($doctor->user_id) {
                $userIds[] = $doctor->user_id;
                $tokens = DeviceToken::where('user_id', $doctor->user_id)
                    ->where('is_active', true)
                    ->pluck('token')
                    ->all();
            }
        } else {
            // all users
            $tokens = DeviceToken::where('is_active', true)
                ->pluck('token')
                ->all();
        }

        $dataPayload = [
            'type' => 'admin_broadcast',
            'title' => $title,
            'body' => $body,
        ];

        if ($imageUrl) {
            $dataPayload['image'] = $imageUrl;
        }

        $notificationPayload = [
            'title' => $title,
            'body' => $body,
        ];

        if ($imageUrl) {
            $notificationPayload['image'] = $imageUrl;
        }

        if (!empty($tokens)) {
            $this->fcmService->sendToTokens($tokens, $dataPayload, $notificationPayload);
        }

        // Log notification records for targeted users (if any)
        foreach ($userIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => 'admin_broadcast',
                'title' => $title,
                'message' => $body,
                'sent_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully',
        ]);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:2048', // max 2MB
        ]);

        $file = $request->file('image');
        $path = $file->store('notifications', 'public');

        $url = asset('storage/'.$path);

        return response()->json([
            'success' => true,
            'url' => $url,
        ]);
    }
}

