<?php

/**
 * Production Deep Face Recognition Microservice Client
 * Integrates WorkPulse PHP API with the Python FastAPI + ArcFace 512-D Backend.
 */
class CompreFaceService
{
    private string $baseUrl;
    private string $apiKey;
    private float $similarityThreshold;
    private int $timeout;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            global $config;
        }
        $cf = $config['compreface'] ?? [];
        $this->baseUrl = rtrim($cf['url'] ?? 'http://127.0.0.1:8000', '/');
        $this->apiKey = trim($cf['api_key'] ?? 'wp_master_secret_key_2026');
        $this->similarityThreshold = (float) ($cf['similarity_threshold'] ?? 0.65);
        $this->timeout = (int) ($cf['timeout'] ?? 15);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    public function getThreshold(): float
    {
        return $this->similarityThreshold;
    }

    /**
     * Enrolls a face reference image for an opaque subject identifier.
     * Endpoint: POST /v1/users/{subject}/face
     */
    public function addFace(string $filePath, string $subject): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'Reference face image file not found on server.',
                'code' => 'FILE_NOT_FOUND',
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Face recognition microservice is not configured.',
                'code' => 'SERVICE_NOT_CONFIGURED',
            ];
        }

        // 1. If subject already enrolled, delete prior enrollment to allow re-enrollment
        $this->deleteFace($subject);

        // 2. Perform enrollment
        $url = $this->baseUrl . '/v1/users/' . urlencode($subject) . '/face';
        $postData = [
            'file' => new CURLFile($filePath, 'image/jpeg', basename($filePath)),
            'consent' => 'true',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("Face Service addFace failed: " . $curlError);
            return [
                'success' => false,
                'error' => 'Unable to connect to Face Recognition backend at ' . $this->baseUrl . '. Ensure face-service is running.',
                'code' => 'SERVICE_UNAVAILABLE',
            ];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['status']) && $data['status'] === 'enrolled') {
            return [
                'success' => true,
                'image_id' => $subject,
                'subject' => $subject,
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'model_checksum' => $data['model_checksum'] ?? null,
            ];
        }

        $errorMessage = $data['error']['message'] ?? ($data['message'] ?? 'Face biometric enrollment failed in neural engine.');
        $errorCode = $data['error']['code'] ?? 'ENROLLMENT_FAILED';

        return [
            'success' => false,
            'error' => $errorMessage,
            'code' => $errorCode,
            'actionable_remedy' => $data['error']['actionable_remedy'] ?? null,
            'raw_response' => $data,
        ];
    }

    /**
     * Verifies an uploaded live face against an enrolled reference image ID / subject.
     * Endpoint: POST /v1/faces/identify
     */
    public function verifyFace(string $filePath, string $imageId, ?float $threshold = null): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'matched' => false,
                'error' => 'Live face image file not found on server.',
                'code' => 'FILE_NOT_FOUND',
            ];
        }

        if (empty($imageId)) {
            return [
                'success' => false,
                'matched' => false,
                'error' => 'No enrolled biometric reference ID available for this employee.',
                'code' => 'MISSING_REFERENCE_ID',
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'matched' => false,
                'error' => 'Face recognition microservice is not configured.',
                'code' => 'SERVICE_NOT_CONFIGURED',
            ];
        }

        $url = $this->baseUrl . '/v1/faces/identify';
        $postData = [
            'file' => new CURLFile($filePath, 'image/jpeg', basename($filePath)),
            'target_user_id' => $imageId,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("Face Service verifyFace connection failed: " . $curlError);
            return [
                'success' => false,
                'matched' => false,
                'error' => 'Biometric verification service timed out or unavailable. Attendance cannot be confirmed without identity verification.',
                'code' => 'SERVICE_UNAVAILABLE',
            ];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['decision'])) {
            $decision = $data['decision'];
            $isMatched = ($decision === 'matched');
            $similarity = isset($data['closest_candidate']['score']) ? (float) $data['closest_candidate']['score'] : 0.0;
            $effectiveThreshold = isset($data['threshold_applied']) ? (float) $data['threshold_applied'] : $this->similarityThreshold;

            if ($isMatched) {
                return [
                    'success' => true,
                    'matched' => true,
                    'decision' => 'matched',
                    'similarity' => $similarity,
                    'threshold' => $effectiveThreshold,
                    'enrolled_thumbnail_url' => $data['enrolled_thumbnail_url'] ?? null,
                    'liveness' => $data['liveness'] ?? 'not_evaluated',
                ];
            } else {
                $reason = ($decision === 'ambiguous')
                    ? 'Biometric ambiguity: face identity overlaps with another account. Verification rejected.'
                    : 'Wrong face detected for employee. Proxy punching is prohibited (similarity: ' . round($similarity * 100, 1) . '%, required: ' . round($effectiveThreshold * 100, 1) . '%).';
                return [
                    'success' => true,
                    'matched' => false,
                    'decision' => $decision,
                    'similarity' => $similarity,
                    'threshold' => $effectiveThreshold,
                    'error' => $reason,
                    'code' => ($decision === 'ambiguous' ? 'BIOMETRIC_AMBIGUITY' : 'FACE_MISMATCH'),
                ];
            }
        }

        $errorMessage = $data['error']['message'] ?? ($data['message'] ?? 'Identity verification rejected by neural engine.');
        return [
            'success' => false,
            'matched' => false,
            'error' => $errorMessage,
            'code' => $data['error']['code'] ?? 'RECOGNITION_ERROR',
            'actionable_remedy' => $data['error']['actionable_remedy'] ?? null,
            'raw_response' => $data,
        ];
    }

    /**
     * Deletes an enrolled face from the recognition gallery.
     * Endpoint: DELETE /v1/users/{subject}/face
     */
    public function deleteFace(string $imageId): array
    {
        if (empty($imageId)) {
            return ['success' => true];
        }

        $url = $this->baseUrl . '/v1/users/' . urlencode($imageId) . '/face';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300) || $httpCode === 404,
        ];
    }
}
