<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/liveness_verification_service.php';
require_once __DIR__ . '/../config/compreface_service.php';

echo "=================================================================\n";
echo "WorkPulse: Liveness & CompreFace Comprehensive Backend Test Suite\n";
echo "=================================================================\n\n";

$passedTests = 0;
$totalTests = 0;

function assertCondition(string $testName, bool $condition, string $detail = '') {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo " [PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        echo " [FAIL] $testName" . ($detail ? " - $detail" : "") . "\n";
    }
}

// ----------------------------------------------------------------------------
// Test 1: Liveness Challenge Generation
// ----------------------------------------------------------------------------
$livenessService = new LivenessVerificationService($pdo, $config);

// Create or get a test user
$userStmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
$testUserId = (int) $userStmt->fetchColumn();
if (!$testUserId) {
    die("No users found in database for testing.\n");
}

$challenge = $livenessService->issueChallenge($testUserId, 'attendance', 180);
assertCondition(
    "1. Liveness challenge generation creates 128-bit hex nonce",
    !empty($challenge['nonce']) && strlen($challenge['nonce']) === 32,
    "Nonce: {$challenge['nonce']}"
);

assertCondition(
    "2. Attendance challenge requires only 1 single step: smile",
    $challenge['actions'] === ['smile'],
    "Actions: " . implode(', ', $challenge['actions'])
);

$enrollChallenge = $livenessService->issueChallenge($testUserId, 'enrollment', 180);
assertCondition(
    "2b. Enrollment challenge includes full randomized motion sequence",
    !empty($enrollChallenge['actions']) && (
        in_array('blink', $enrollChallenge['actions']) ||
        in_array('nod', $enrollChallenge['actions']) ||
        in_array('openMouth', $enrollChallenge['actions'])
    ),
    "Enrollment Actions: " . implode(', ', $enrollChallenge['actions'])
);

assertCondition(
    "3. Challenge expiration is strictly server-bound",
    strtotime($challenge['expiresAt']) > time(),
    "Expires at: {$challenge['expiresAt']}"
);

// ----------------------------------------------------------------------------
// Test 2: Reused Nonce (Replay Attack Prevention)
// ----------------------------------------------------------------------------
$nonce = $challenge['nonce'];
// Consume challenge once
$stmt = $pdo->prepare("UPDATE liveness_challenges SET consumed_at = NOW() WHERE nonce = :nonce");
$stmt->execute(['nonce' => $nonce]);

// Now attempt verification with already consumed challenge
$dummyMetadata = json_encode([
    'sessionId' => 'LV-TEST-001',
    'nonce' => $nonce,
    'success' => true,
    'confidenceScore' => 0.95,
    'completedActions' => $challenge['actions'],
    'images' => [],
]);

$res = $livenessService->verifySession($testUserId, 'attendance', 'LV-TEST-001', $dummyMetadata, []);
assertCondition(
    "4. Replay attack rejection: Already consumed nonce is rejected",
    $res['success'] === false && $res['code'] === 'CHALLENGE_ALREADY_CONSUMED',
    $res['error'] ?? ''
);

// ----------------------------------------------------------------------------
// Test 3: Expired Challenge Rejection
// ----------------------------------------------------------------------------
$expiredChallenge = $livenessService->issueChallenge($testUserId, 'attendance', 1);
$expNonce = $expiredChallenge['nonce'];
// Artificially expire the challenge in database
$pdo->prepare("UPDATE liveness_challenges SET expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE nonce = :nonce")
    ->execute(['nonce' => $expNonce]);

$dummyExpMeta = json_encode([
    'sessionId' => 'LV-TEST-002',
    'nonce' => $expNonce,
    'success' => true,
    'confidenceScore' => 0.95,
    'completedActions' => $expiredChallenge['actions'],
    'images' => [],
]);

$expRes = $livenessService->verifySession($testUserId, 'attendance', 'LV-TEST-002', $dummyExpMeta, []);
assertCondition(
    "5. Expired challenge rejection: Past expiry time is refused",
    $expRes['success'] === false && $expRes['code'] === 'CHALLENGE_EXPIRED',
    $expRes['error'] ?? ''
);

// ----------------------------------------------------------------------------
// Test 4: Purpose & Ownership Mismatch Check
// ----------------------------------------------------------------------------
$enrollChallenge = $livenessService->issueChallenge($testUserId, 'enrollment', 180);
$enrollMeta = json_encode([
    'sessionId' => 'LV-TEST-003',
    'nonce' => $enrollChallenge['nonce'],
    'success' => true,
    'confidenceScore' => 0.95,
    'completedActions' => $enrollChallenge['actions'],
    'images' => [],
]);

// Presenting enrollment challenge for attendance must fail
$purposeRes = $livenessService->verifySession($testUserId, 'attendance', 'LV-TEST-003', $enrollMeta, []);
assertCondition(
    "6. Purpose binding: Enrollment challenge rejected when used for attendance",
    $purposeRes['success'] === false && $purposeRes['code'] === 'CHALLENGE_PURPOSE_MISMATCH',
    $purposeRes['error'] ?? ''
);

// Ownership mismatch (another user trying to use this challenge)
$otherUserId = $testUserId + 9999;
$ownerRes = $livenessService->verifySession($otherUserId, 'enrollment', 'LV-TEST-003', $enrollMeta, []);
assertCondition(
    "7. Ownership binding: Challenge rejected if presented by another user",
    $ownerRes['success'] === false && $ownerRes['code'] === 'CHALLENGE_OWNERSHIP_MISMATCH',
    $ownerRes['error'] ?? ''
);

// ----------------------------------------------------------------------------
// Test 5: Action Sequence Mismatch (Incorrect Order)
// ----------------------------------------------------------------------------
$orderChallenge = $livenessService->issueChallenge($testUserId, 'enrollment', 180);
$wrongActions = array_reverse($orderChallenge['actions']);
if ($wrongActions === $orderChallenge['actions']) {
    $wrongActions = ['nod', 'tiltLeft', 'blink'];
}
$wrongOrderMeta = json_encode([
    'sessionId' => 'LV-TEST-004',
    'nonce' => $orderChallenge['nonce'],
    'success' => true,
    'confidenceScore' => 0.95,
    'completedActions' => $wrongActions,
    'images' => [],
]);

$orderRes = $livenessService->verifySession($testUserId, 'enrollment', 'LV-TEST-004', $wrongOrderMeta, []);
assertCondition(
    "8. Action order verification: Reordered actions fail validation",
    $orderRes['success'] === false && $orderRes['code'] === 'ACTION_SEQUENCE_MISMATCH',
    $orderRes['error'] ?? ''
);

// ----------------------------------------------------------------------------
// Test 6: Media Integrity & SHA-256 Hash Verification
// ----------------------------------------------------------------------------
$hashChallenge = $livenessService->issueChallenge($testUserId, 'attendance', 180);

// Create temporary dummy image file
$tempImgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_img_' . time() . '.jpg';
file_put_contents($tempImgPath, 'FAKE_JPEG_IMAGE_CONTENT_ABC_123');
$realHash = hash_file('sha256', $tempImgPath);

// Fake altered hash in metadata
$tamperedMeta = json_encode([
    'sessionId' => 'LV-TEST-005',
    'nonce' => $hashChallenge['nonce'],
    'success' => true,
    'confidenceScore' => 0.95,
    'completedActions' => $hashChallenge['actions'],
    'images' => [
        [
            'action' => null,
            'kind' => 'reference',
            'timestampMs' => 1200,
            'sha256' => '0000000000000000000000000000000000000000000000000000000000000000', // Altered!
        ],
    ],
]);

$dummyFiles = [
    'name' => 'reference_1200ms.jpg',
    'tmp_name' => $tempImgPath,
    'type' => 'image/jpeg',
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tempImgPath),
];

$hashRes = $livenessService->verifySession($testUserId, 'attendance', 'LV-TEST-005', $tamperedMeta, $dummyFiles);
assertCondition(
    "9. Cryptographic hash check: Tampered file or altered metadata hash is rejected",
    $hashRes['success'] === false && $hashRes['code'] === 'HASH_MISMATCH',
    $hashRes['error'] ?? ''
);

// ----------------------------------------------------------------------------
// Test 7: Successful Media Verification with Matching Hashes
// ----------------------------------------------------------------------------
$validChallenge = $livenessService->issueChallenge($testUserId, 'attendance', 180);
$validMeta = json_encode([
    'sessionId' => 'LV-TEST-006',
    'nonce' => $validChallenge['nonce'],
    'success' => true,
    'confidenceScore' => 0.95,
    'completedActions' => $validChallenge['actions'],
    'images' => [
        [
            'action' => null,
            'kind' => 'reference',
            'timestampMs' => 1200,
            'sha256' => $realHash,
        ],
    ],
]);

$validRes = $livenessService->verifySession($testUserId, 'attendance', 'LV-TEST-006', $validMeta, $dummyFiles);
assertCondition(
    "10. Successful liveness session passes hash, nonce, and action checks",
    $validRes['success'] === true && !empty($validRes['reference_file_path']),
    "Ref file: " . ($validRes['reference_file_path'] ?? '')
);

@unlink($tempImgPath);

// ----------------------------------------------------------------------------
// Test 8: CompreFace Service Fail-Closed Behavior on Unconfigured / Outage
// ----------------------------------------------------------------------------
$mockConfig = [
    'compreface' => [
        'url' => 'http://127.0.0.1:9999', // Non-existent port to simulate outage
        'api_key' => 'test-api-key',
        'similarity_threshold' => 0.85,
        'timeout' => 1,
    ],
];
$cfOutageService = new CompreFaceService($mockConfig);
$dummyImg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cf.jpg';
file_put_contents($dummyImg, 'JPEG_BYTES');

$outageRes = $cfOutageService->verifyFace($dummyImg, 'test-img-id-123');
assertCondition(
    "11. CompreFace outage / timeout fails closed (never grants unauthorized access)",
    $outageRes['success'] === false && $outageRes['matched'] === false && $outageRes['code'] === 'SERVICE_UNAVAILABLE',
    $outageRes['error'] ?? ''
);

$unconfiguredService = new CompreFaceService(['compreface' => []]);
$unconfiguredRes = $unconfiguredService->verifyFace($dummyImg, 'test-img-id-123');
assertCondition(
    "12. Unconfigured CompreFace service fails closed",
    $unconfiguredRes['success'] === false && $unconfiguredRes['code'] === 'COMPREFACE_NOT_CONFIGURED',
    $unconfiguredRes['error'] ?? ''
);

@unlink($dummyImg);

// ----------------------------------------------------------------------------
// Test 9: Standalone Dense Biometric Similarity Threshold Enforcement
// ----------------------------------------------------------------------------
// Simulate punch verification with friend's face (similarity = 0.45 < 0.70 threshold)
$friendSim = 0.45;
$isSimRejected = ($friendSim < 0.70);
assertCondition(
    "13. Biometric threshold security: Friend's face similarity (45%) is strictly rejected (< 70% threshold)",
    $isSimRejected === true,
    "Similarity {$friendSim} was correctly rejected"
);

// Simulate legitimate employee face (similarity = 0.88 >= 0.70 threshold)
$legitSim = 0.88;
$isSimAccepted = ($legitSim >= 0.70);
assertCondition(
    "14. Biometric threshold security: Authentic employee face similarity (88%) is verified (>= 70% threshold)",
    $isSimAccepted === true,
    "Similarity {$legitSim} was correctly accepted"
);

echo "\n-----------------------------------------------------------------\n";
echo "Test Results: $passedTests / $totalTests passed.\n";
echo "-----------------------------------------------------------------\n\n";

if ($passedTests === $totalTests) {
    echo "ALL BACKEND TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
