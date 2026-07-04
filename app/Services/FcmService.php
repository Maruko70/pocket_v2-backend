<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\DoctorDeviceToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected ?string $projectId;

    protected ?string $credentialsPath;

    protected ?string $credentialsJson;

    public function __construct()
    {
        $this->projectId = config('services.fcm.project_id');
        $this->credentialsPath = $this->resolveCredentialsPath(config('services.fcm.credentials'));
        $this->credentialsJson = config('services.fcm.credentials_json');
    }

    public function isConfigured(): bool
    {
        if (empty($this->projectId)) {
            return false;
        }

        return $this->credentialsPath !== null || !empty($this->credentialsJson);
    }

    public function sendToToken(string $token, array $data, array $notification = []): array
    {
        return $this->sendToTokens([$token], $data, $notification);
    }

    /**
     * @param  array<string>  $tokens
     */
    public function sendToTokens(array $tokens, array $data, array $notification = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if (empty($tokens)) {
            return [
                'success' => false,
                'tokens_count' => 0,
                'message' => 'No device tokens to send to.',
            ];
        }

        if (!$this->isConfigured()) {
            Log::warning('FCM push skipped: Firebase service account is not configured');

            return [
                'success' => false,
                'tokens_count' => count($tokens),
                'message' => 'Firebase service account credentials are not configured on the server.',
            ];
        }

        try {
            $accessToken = $this->getAccessToken();
        } catch (\Throwable $e) {
            Log::error('FCM auth failed: '.$e->getMessage());

            return [
                'success' => false,
                'tokens_count' => count($tokens),
                'message' => 'Failed to authenticate with Firebase: '.$e->getMessage(),
            ];
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $successCount = 0;
        $failureCount = 0;

        foreach (array_chunk($tokens, 25) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $accessToken, $url, $data, $notification) {
                foreach ($chunk as $index => $deviceToken) {
                    $pool->as((string) $index)
                        ->withToken($accessToken)
                        ->acceptJson()
                        ->post($url, $this->buildV1Message($deviceToken, $data, $notification));
                }
            });

            foreach ($responses as $response) {
                if ($response->successful()) {
                    $successCount++;
                    continue;
                }

                $failureCount++;
                Log::warning('FCM v1 push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        }

        return [
            'success' => $successCount > 0,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'tokens_count' => count($tokens),
            'message' => $successCount > 0
                ? "Delivered to {$successCount} device(s)."
                : 'FCM accepted the requests but no devices received the notification.',
        ];
    }

    /**
     * Send a payload to all active tokens for the given user.
     */
    public function sendToUserDevices(int $userId, array $data, array $notification = []): array
    {
        $tokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        return $this->sendToTokens($tokens, $data, $notification);
    }

    /**
     * Send a payload to all active tokens for the given doctor.
     */
    public function sendToDoctorDevices(int $doctorId, array $data, array $notification = []): array
    {
        $tokens = DoctorDeviceToken::where('doctor_id', $doctorId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        return $this->sendToTokens($tokens, $data, $notification);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildV1Message(string $deviceToken, array $data, array $notification): array
    {
        $message = [
            'token' => $deviceToken,
        ];

        $normalizedData = $this->normalizeData($data);

        if (!empty($notification)) {
            $message['notification'] = array_filter([
                'title' => $notification['title'] ?? null,
                'body' => $notification['body'] ?? null,
                'image' => $notification['image'] ?? null,
            ]);
        }

        if (!empty($normalizedData)) {
            $message['data'] = $normalizedData;
        }

        $channelId = $this->resolveAndroidChannelId($data);
        $androidNotification = ['channel_id' => $channelId];

        if (!empty($notification['image'])) {
            $androidNotification['image'] = $notification['image'];
        }

        $message['android'] = [
            'priority' => 'HIGH',
            'notification' => $androidNotification,
        ];

        return ['message' => $message];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveAndroidChannelId(array $data): string
    {
        $type = (string) ($data['type'] ?? 'general');

        return match ($type) {
            'reservation_reminder' => 'reservation_reminders',
            'reservation_status', 'doctor_unavailable', 'unavailability_request_status' => 'reservation_status',
            'admin_broadcast' => 'admin_broadcasts',
            default => 'admin_broadcasts',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $normalized[$key] = (string) $value;
            } else {
                $normalized[$key] = json_encode($value);
            }
        }

        return $normalized;
    }

    protected function getAccessToken(): string
    {
        return Cache::remember('fcm_v1_access_token', 3300, function () {
            $credentials = $this->createCredentials();
            $token = $credentials->fetchAuthToken();

            if (empty($token['access_token'])) {
                throw new \RuntimeException('Firebase did not return an access token.');
            }

            return $token['access_token'];
        });
    }

    protected function createCredentials(): ServiceAccountCredentials
    {
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';

        if (!empty($this->credentialsJson)) {
            $json = json_decode($this->credentialsJson, true);
            if (!is_array($json)) {
                throw new \RuntimeException('FCM_CREDENTIALS_JSON is not valid JSON.');
            }

            if (empty($this->projectId) && !empty($json['project_id'])) {
                $this->projectId = $json['project_id'];
            }

            return new ServiceAccountCredentials($scope, $json);
        }

        if ($this->credentialsPath === null) {
            throw new \RuntimeException('Firebase credentials file path is not configured.');
        }

        if (!is_readable($this->credentialsPath)) {
            throw new \RuntimeException("Firebase credentials file is not readable: {$this->credentialsPath}");
        }

        $json = json_decode((string) file_get_contents($this->credentialsPath), true);
        if (!is_array($json)) {
            throw new \RuntimeException('Firebase credentials file does not contain valid JSON.');
        }

        if (empty($this->projectId) && !empty($json['project_id'])) {
            $this->projectId = $json['project_id'];
        }

        return new ServiceAccountCredentials($scope, $this->credentialsPath);
    }

    protected function resolveCredentialsPath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }

        return base_path($path);
    }
}
