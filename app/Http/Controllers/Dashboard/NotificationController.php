<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Doctor;
use App\Models\DoctorDeviceToken;
use App\Models\Notification;
use App\Models\NotificationBroadcast;
use App\Models\User;
use App\Services\FcmService;
use App\Support\PublicStorageUrl;
use Illuminate\Http\Request;

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

        if (in_array($validated['target_type'], ['user', 'doctor'], true) && empty($validated['target_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'A target user or doctor must be selected.',
            ], 422);
        }

        return $this->dispatchBroadcast($validated, $request->user()?->id);
    }

    /**
     * List sent broadcast history with server-side filters.
     */
    public function history(Request $request)
    {
        $query = NotificationBroadcast::with('adminUser:id,name');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('target_name', 'like', "%{$search}%");
            });
        }

        if ($targetType = $request->get('target_type')) {
            $query->where('target_type', $targetType);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($adminUserId = $request->get('admin_user_id')) {
            $query->where('admin_user_id', $adminUserId);
        }

        if ($startDate = $request->get('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate = $request->get('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $page = (int) $request->get('page', 1);

        $broadcasts = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'broadcasts' => $broadcasts->items(),
            'pagination' => [
                'current_page' => $broadcasts->currentPage(),
                'per_page' => $broadcasts->perPage(),
                'total' => $broadcasts->total(),
                'last_page' => $broadcasts->lastPage(),
            ],
        ]);
    }

    /**
     * Resend a previously sent broadcast with the same payload.
     */
    public function resend(Request $request, $id)
    {
        $broadcast = NotificationBroadcast::findOrFail($id);

        return $this->dispatchBroadcast([
            'target_type' => $broadcast->target_type,
            'target_id' => $broadcast->target_id,
            'title' => $broadcast->title,
            'body' => $broadcast->body,
            'image_url' => $broadcast->image_url,
        ], $request->user()?->id, $broadcast->id);
    }

    protected function dispatchBroadcast(array $payload, ?int $adminUserId, ?int $resentFromId = null)
    {
        $targetType = $payload['target_type'];
        $targetId = $payload['target_id'] ?? null;
        $title = $payload['title'];
        $body = $payload['body'];
        $imageUrl = $payload['image_url'] ?? null;

        $record = [
            'admin_user_id' => $adminUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_name' => null,
            'title' => $title,
            'body' => $body,
            'image_url' => $imageUrl,
            'resent_from_id' => $resentFromId,
            'sent_at' => now(),
        ];

        if (!$this->fcmService->isConfigured()) {
            $message = 'Push notifications are not configured. Upload the Firebase service account JSON and set FCM_CREDENTIALS on the server.';
            $broadcast = NotificationBroadcast::create($record + [
                'status' => 'failed',
                'failure_reason' => $message,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
                'tokens_sent' => 0,
                'broadcast' => $broadcast,
            ], 503);
        }

        $tokens = [];
        $userIds = [];

        if ($targetType === 'user') {
            $user = User::find($targetId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'The target user no longer exists.',
                ], 422);
            }
            $record['target_name'] = $user->name;
            $userIds[] = $user->id;
            $tokens = DeviceToken::where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('token')
                ->all();
        } elseif ($targetType === 'doctor') {
            $doctor = Doctor::find($targetId);
            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'The target doctor no longer exists.',
                ], 422);
            }
            $record['target_name'] = $doctor->name;
            $tokens = DoctorDeviceToken::where('doctor_id', $doctor->id)
                ->where('is_active', true)
                ->pluck('token')
                ->all();
        } else {
            $record['target_name'] = 'All users & doctors';
            $patientTokens = DeviceToken::where('is_active', true)
                ->pluck('token')
                ->all();
            $doctorTokens = DoctorDeviceToken::where('is_active', true)
                ->pluck('token')
                ->all();
            $tokens = array_values(array_unique(array_merge($patientTokens, $doctorTokens)));
        }

        if (empty($tokens)) {
            $message = 'No active devices found for this target. The user or doctor may not have opened the app recently.';
            $broadcast = NotificationBroadcast::create($record + [
                'status' => 'failed',
                'failure_reason' => $message,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
                'tokens_sent' => 0,
                'broadcast' => $broadcast,
            ], 422);
        }

        $dataPayload = [
            'type' => 'admin_broadcast',
            'title' => $title,
            'body' => $body,
        ];

        $notificationPayload = [
            'title' => $title,
            'body' => $body,
        ];

        if ($imageUrl) {
            $dataPayload['image'] = $imageUrl;
            $notificationPayload['image'] = $imageUrl;
        }

        $fcmResult = $this->fcmService->sendToTokens($tokens, $dataPayload, $notificationPayload);

        foreach ($userIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => 'admin_broadcast',
                'title' => $title,
                'message' => $body,
                'sent_at' => now(),
            ]);
        }

        $delivered = (int) ($fcmResult['success_count'] ?? 0);
        $failed = (int) ($fcmResult['failure_count'] ?? 0);
        $succeeded = (bool) ($fcmResult['success'] ?? false);

        $status = 'failed';
        if ($succeeded) {
            $status = $failed > 0 ? 'partial' : 'sent';
        }

        $broadcast = NotificationBroadcast::create($record + [
            'tokens_targeted' => count($tokens),
            'devices_delivered' => $delivered,
            'devices_failed' => $succeeded ? $failed : max($failed, count($tokens) - $delivered),
            'status' => $status,
            'failure_reason' => $succeeded ? null : ($fcmResult['message'] ?? 'Failed to deliver notification.'),
        ]);

        if (!$succeeded) {
            return response()->json([
                'success' => false,
                'message' => $fcmResult['message'] ?? 'Failed to deliver notification.',
                'tokens_sent' => count($tokens),
                'fcm' => $fcmResult,
                'broadcast' => $broadcast,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => $fcmResult['message'] ?? 'Notification sent successfully.',
            'tokens_sent' => count($tokens),
            'devices_delivered' => $delivered,
            'fcm' => $fcmResult,
            'broadcast' => $broadcast,
        ]);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:2048', // max 2MB
        ]);

        $file = $request->file('image');
        $path = $file->store('notifications', 'public');

        $url = PublicStorageUrl::resolve($path);

        return response()->json([
            'success' => true,
            'url' => $url,
        ]);
    }
}
