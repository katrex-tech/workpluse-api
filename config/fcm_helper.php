<?php

/**
 * WorkPulse FCM (Firebase Cloud Messaging) Push Notification Helper
 * Project: dm-tech-studio (197983242982)
 */

if (!function_exists('sendFcmNotification')) {
    function sendFcmNotification(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        if (empty($fcmToken)) {
            return false;
        }

        $projectId = 'dm-tech-studio';
        $credentialsFile = __DIR__ . '/firebase_credentials.json';

        // Check if service account credentials or server key file exists
        if (file_exists($credentialsFile)) {
            $credData = json_decode(file_get_contents($credentialsFile), true) ?: [];
            
            // 1. If OAuth2 Service Account JSON (FCM v1 API)
            if (!empty($credData['private_key']) && !empty($credData['client_email'])) {
                $accessToken = getGoogleOAuthToken($credData);
                if ($accessToken) {
                    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
                    $payload = [
                        'message' => [
                            'token' => $fcmToken,
                            'notification' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'android' => [
                                'priority' => 'HIGH',
                                'notification' => [
                                    'channel_id' => 'workpulse_high_importance_channel',
                                    'sound' => 'default',
                                    'default_vibrate_timings' => true,
                                ],
                            ],
                            'data' => array_map('strval', array_merge([
                                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                                'title' => $title,
                                'body' => $body,
                            ], $data)),
                        ],
                    ];

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $accessToken,
                            'Content-Type: application/json',
                        ],
                        CURLOPT_POSTFIELDS => json_encode($payload),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    return $httpCode >= 200 && $httpCode < 300;
                }
            }

            // 2. Legacy server key fallback
            if (!empty($credData['server_key'])) {
                $serverKey = $credData['server_key'];
                $url = 'https://fcm.googleapis.com/fcm/send';
                $payload = [
                    'to' => $fcmToken,
                    'priority' => 'high',
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                        'android_channel_id' => 'workpulse_high_importance_channel',
                    ],
                    'data' => $data,
                ];

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: key=' . $serverKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                return $httpCode === 200;
            }
        }

        // Credentials file not yet placed, log for developer
        error_log("[FCM Push] Notification queued for token: " . substr($fcmToken, 0, 15) . "... Title: $title");
        return false;
    }
}

if (!function_exists('sendFcmToUsers')) {
    function sendFcmToUsers(PDO $pdo, array $userIds, string $title, string $body, array $data = []): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $inClause = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id, fcm_token FROM users WHERE id IN ($inClause) AND fcm_token IS NOT NULL AND fcm_token != ''");
        $stmt->execute($userIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $successCount = 0;
        foreach ($rows as $row) {
            $token = trim((string) $row['fcm_token']);
            if (!empty($token)) {
                if (sendFcmNotification($token, $title, $body, $data)) {
                    $successCount++;
                }
            }
        }
        return $successCount;
    }
}

/**
 * Generate Google OAuth2 Access Token from Service Account JSON
 */
function getGoogleOAuthToken(array $serviceAccount): ?string
{
    $now = time();
    $jwtHeader = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtClaim = base64UrlEncode(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ]));

    $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
    if (!$privateKey) {
        return null;
    }

    $signature = '';
    openssl_sign("$jwtHeader.$jwtClaim", $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwt = "$jwtHeader.$jwtClaim." . base64UrlEncode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode((string) $response, true);
    return $json['access_token'] ?? null;
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
