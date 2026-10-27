<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class LivenessVerificationService
{
    private PDO $pdo;
    private array $config;

    public function __construct(?PDO $pdo = null, ?array $config = null)
    {
        if ($pdo === null) {
            global $pdo;
        }
        if ($config === null) {
            global $config;
        }
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * Issues a new single-use liveness challenge for an authenticated user.
     *
     * @param int $userId Authenticated user ID
     * @param string $purpose 'enrollment' or 'attendance'
     * @param int|null $expirySeconds Challenge validity duration
     * @return array Challenge payload with nonce, actions, expiresAt
     */
    public function issueChallenge(int $userId, string $purpose, ?int $expirySeconds = null): array
    {
        $duration = $expirySeconds ?? ($this->config['liveness']['challenge_expiry_seconds'] ?? 180);
        
        // 1. Generate cryptographically secure random nonce (128-bit / 32 hex chars)
        $nonce = bin2hex(random_bytes(16));

        // 2. Select action sequence: 4 steps (blink, smile, lookLeft, lookRight) for both enrollment and attendance
        $actions = ['blink', 'smile', 'lookLeft', 'lookRight'];

        $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        $createdAt = $now->format('Y-m-d H:i:s');
        $expiresAt = (clone $now)->modify("+{$duration} seconds")->format('Y-m-d H:i:s');
        $expiresAtIso = (clone $now)->modify("+{$duration} seconds")->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $stmt = $this->pdo->prepare('
            INSERT INTO liveness_challenges (nonce, user_id, purpose, actions, created_at, expires_at)
            VALUES (:nonce, :user_id, :purpose, :actions, :created_at, :expires_at)
        ');
        $stmt->execute([
            'nonce' => $nonce,
            'user_id' => $userId,
            'purpose' => $purpose,
            'actions' => json_encode($actions),
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'nonce' => $nonce,
            'actions' => $actions,
            'expiresAt' => $expiresAtIso,
            'expiresAtLocal' => $expiresAt,
        ];
    }

    /**
     * Verifies the submitted liveness session data and multipart uploaded evidence.
     *
     * @param int $userId Authenticated user ID
     * @param string $expectedPurpose 'enrollment' or 'attendance'
     * @param string|null $sessionHeader Value of X-Liveness-Session header
     * @param string|null $metadataRaw Raw string of 'metadata' field
     * @param array $uploadedFiles Array from $_FILES['images']
     * @return array Verification result with 'success', 'reference_file_path', 'error'
     */
    public function verifySession(
        int $userId,
        string $expectedPurpose,
        ?string $sessionHeader,
        ?string $metadataRaw,
        array $uploadedFiles
    ): array {
        if (empty($metadataRaw)) {
            return [
                'success' => false,
                'error' => 'Missing liveness metadata field in upload.',
                'code' => 'MISSING_METADATA',
            ];
        }

        $metadata = json_decode($metadataRaw, true);
        if (!is_array($metadata)) {
            return [
                'success' => false,
                'error' => 'Invalid JSON in liveness metadata.',
                'code' => 'INVALID_METADATA_JSON',
            ];
        }

        // 1. Session ID Check
        $sessionId = $metadata['sessionId'] ?? '';
        if (empty($sessionId)) {
            return [
                'success' => false,
                'error' => 'Session ID is missing from metadata.',
                'code' => 'MISSING_SESSION_ID',
            ];
        }

        if (!empty($sessionHeader) && $sessionHeader !== $sessionId) {
            return [
                'success' => false,
                'error' => 'Session ID header mismatch. Session may have been tampered with.',
                'code' => 'SESSION_HEADER_MISMATCH',
            ];
        }

        // 2. Client-Reported Success Check
        $isSuccess = $metadata['success'] ?? false;
        if (!$isSuccess) {
            $reason = $metadata['failureReason'] ?? 'Liveness check was not marked successful by detector';
            return [
                'success' => false,
                'error' => 'Liveness detection failed: ' . $reason,
                'code' => 'LIVENESS_FAILED',
            ];
        }

        // 3. Challenge Nonce & Ownership Check
        $nonce = $metadata['nonce'] ?? null;
        if (empty($nonce)) {
            return [
                'success' => false,
                'error' => 'Server challenge nonce is missing in session result.',
                'code' => 'MISSING_NONCE',
            ];
        }

        $stmt = $this->pdo->prepare('
            SELECT id, user_id, purpose, actions, expires_at, consumed_at 
            FROM liveness_challenges 
            WHERE nonce = :nonce 
            LIMIT 1
        ');
        $stmt->execute(['nonce' => $nonce]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            return [
                'success' => false,
                'error' => 'Invalid or unrecognized challenge nonce.',
                'code' => 'INVALID_NONCE',
            ];
        }

        // Check ownership
        if ((int) $challenge['user_id'] !== $userId) {
            return [
                'success' => false,
                'error' => 'Liveness challenge does not belong to the authenticated user.',
                'code' => 'CHALLENGE_OWNERSHIP_MISMATCH',
            ];
        }

        // Check purpose
        if ($challenge['purpose'] !== $expectedPurpose) {
            return [
                'success' => false,
                'error' => "Challenge was issued for {$challenge['purpose']} but presented for {$expectedPurpose}.",
                'code' => 'CHALLENGE_PURPOSE_MISMATCH',
            ];
        }

        // Check expiry against server clock
        $now = date('Y-m-d H:i:s');
        if ($now > $challenge['expires_at']) {
            return [
                'success' => false,
                'error' => 'Liveness challenge has expired. Please try again.',
                'code' => 'CHALLENGE_EXPIRED',
            ];
        }

        // Check already consumed (Replay prevention)
        if (!empty($challenge['consumed_at'])) {
            return [
                'success' => false,
                'error' => 'This liveness challenge has already been used and cannot be replayed.',
                'code' => 'CHALLENGE_ALREADY_CONSUMED',
            ];
        }

        // Atomic consume to prevent race condition / parallel replay
        $consumeStmt = $this->pdo->prepare('
            UPDATE liveness_challenges 
            SET consumed_at = NOW() 
            WHERE id = :id AND consumed_at IS NULL
        ');
        $consumeStmt->execute(['id' => $challenge['id']]);
        if ($consumeStmt->rowCount() === 0) {
            return [
                'success' => false,
                'error' => 'Replay attack prevented: challenge already consumed in concurrent request.',
                'code' => 'CHALLENGE_CONCURRENTLY_CONSUMED',
            ];
        }

        // 4. Completed Actions Sequence Check
        $expectedActions = json_decode($challenge['actions'], true);
        $completedActions = $metadata['completedActions'] ?? [];

        if ($completedActions !== $expectedActions) {
            return [
                'success' => false,
                'error' => 'Executed action sequence does not match the server-issued challenge.',
                'code' => 'ACTION_SEQUENCE_MISMATCH',
                'expected' => $expectedActions,
                'completed' => $completedActions,
            ];
        }

        // 5. Confidence Score Plausibility
        $confidenceScore = isset($metadata['confidenceScore']) ? (float) $metadata['confidenceScore'] : 1.0;
        $minConfidence = (float) ($this->config['liveness']['min_confidence_score'] ?? 0.70);
        if ($confidenceScore < $minConfidence) {
            return [
                'success' => false,
                'error' => "Low liveness confidence score ({$confidenceScore}). Please perform actions naturally under good lighting.",
                'code' => 'LOW_CONFIDENCE_SCORE',
            ];
        }

        // 6. Media Integrity & SHA-256 Hash Verification
        $normalizedFiles = $this->normalizeUploadedFiles($uploadedFiles);
        $metaImages = $metadata['images'] ?? [];

        if (empty($metaImages)) {
            return [
                'success' => false,
                'error' => 'No action images recorded in session metadata.',
                'code' => 'NO_IMAGES_IN_METADATA',
            ];
        }

        if (count($normalizedFiles) !== count($metaImages)) {
            return [
                'success' => false,
                'error' => sprintf('Uploaded image file count (%d) does not match metadata count (%d).', count($normalizedFiles), count($metaImages)),
                'code' => 'IMAGE_COUNT_MISMATCH',
            ];
        }

        // Map uploaded files by their SHA-256 hash
        $uploadedHashMap = [];
        foreach ($normalizedFiles as $file) {
            $tmpPath = $file['tmp_name'];
            if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
                return [
                    'success' => false,
                    'error' => 'Uploaded evidence file missing or unreadable on server.',
                    'code' => 'UNREADABLE_UPLOAD',
                ];
            }
            $hash = hash_file('sha256', $tmpPath);
            $uploadedHashMap[$hash] = $file;
        }

        // Validate each metadata image against uploaded file hashes
        $referenceFile = null;
        foreach ($metaImages as $metaImg) {
            $expectedHash = strtolower(trim($metaImg['sha256'] ?? ''));
            if (!isset($uploadedHashMap[$expectedHash])) {
                return [
                    'success' => false,
                    'error' => 'Evidence integrity check failed. Uploaded photo hash does not match session metadata hash.',
                    'code' => 'HASH_MISMATCH',
                ];
            }

            // Identify neutral reference image
            $kind = $metaImg['kind'] ?? '';
            $action = $metaImg['action'] ?? null;
            if ($kind === 'reference' || $action === null) {
                $referenceFile = $uploadedHashMap[$expectedHash];
            }
        }

        // Fallback: If no explicit 'reference' kind found, use first uploaded photo
        if ($referenceFile === null && !empty($normalizedFiles)) {
            $referenceFile = $normalizedFiles[0];
        }

        // Save reference photo to permanent or staging directory
        $targetDir = dirname(__DIR__) . '/uploads/face_photos';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        $savedReferencePath = null;
        $savedRelativePath = null;
        if ($referenceFile && file_exists($referenceFile['tmp_name'])) {
            $ext = pathinfo($referenceFile['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = 'face_' . $userId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
            $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

            if (copy($referenceFile['tmp_name'], $destination)) {
                $savedReferencePath = $destination;
                $savedRelativePath = 'uploads/face_photos/' . $fileName;
            }
        }

        return [
            'success' => true,
            'session_id' => $sessionId,
            'nonce' => $nonce,
            'confidence_score' => $confidenceScore,
            'completed_actions' => $completedActions,
            'reference_file_path' => $savedReferencePath,
            'reference_relative_path' => $savedRelativePath,
            'raw_metadata' => $metadata,
        ];
    }

    /**
     * Normalizes PHP's $_FILES array into a uniform list of file items.
     */
    private function normalizeUploadedFiles(array $files): array
    {
        $normalized = [];

        // When files are uploaded with names like images[0], images[1],
        // PHP parses $_FILES['images'] as ['name' => [0 => ...], 'tmp_name' => [0 => ...]]
        if (isset($files['name']) && is_array($files['name'])) {
            foreach ($files['name'] as $key => $name) {
                if (!empty($files['tmp_name'][$key]) && ($files['error'][$key] === UPLOAD_ERR_OK)) {
                    $normalized[] = [
                        'name' => $name,
                        'type' => $files['type'][$key] ?? 'image/jpeg',
                        'tmp_name' => $files['tmp_name'][$key],
                        'error' => $files['error'][$key],
                        'size' => $files['size'][$key] ?? 0,
                    ];
                }
            }
        } elseif (isset($files['tmp_name']) && is_string($files['tmp_name']) && $files['error'] === UPLOAD_ERR_OK) {
            $normalized[] = $files;
        } else {
            // Check all $_FILES entries in case files were keyed directly (e.g. images_0, images_1)
            foreach ($_FILES as $key => $item) {
                if (is_array($item) && isset($item['tmp_name']) && is_string($item['tmp_name']) && $item['error'] === UPLOAD_ERR_OK) {
                    $normalized[] = $item;
                }
            }
        }

        return $normalized;
    }
}
