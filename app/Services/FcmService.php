<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\DoctorDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected ?string $serverKey;

    public function __construct()
    {
        $this->serverKey = config('services.fcm.server_key');
    }

    public function sendToToken(string $token, array $data, array $notification = []): void
    {
        $payload = [
            'to' => $token,
            'data' => $data,
        ];

        if (!empty($notification)) {
            $payload['notification'] = $notification;
        }

        $this->sendPayload($payload);
    }

    /**
    * @param array<string> $tokens
    */
    public function sendToTokens(array $tokens, array $data, array $notification = []): void
    {
        if (empty($tokens)) {
            return;
        }

        $payload = [
            'registration_ids' => array_values($tokens),
            'data' => $data,
        ];

        if (!empty($notification)) {
            $payload['notification'] = $notification;
        }

        // Use multicast for small batches, or loop if needed.
        $this->sendPayload($payload);
    }

    /**
     * Send a payload to all active tokens for the given user.
     */
    public function sendToUserDevices(int $userId, array $data, array $notification = []): void
    {
        $tokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $this->sendToTokens($tokens, $data, $notification);
    }

    /**
     * Send a payload to all active tokens for the given doctor.
     */
    public function sendToDoctorDevices(int $doctorId, array $data, array $notification = []): void
    {
        $tokens = DoctorDeviceToken::where('doctor_id', $doctorId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $this->sendToTokens($tokens, $data, $notification);
    }

    protected function sendPayload(array $payload): void
    {
        if (!$this->serverKey) {
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key='.$this->serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', $payload);

            if ($response->failed()) {
                Log::warning('FCM push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('FCM push exception: '.$e->getMessage());
        }
    }
}

