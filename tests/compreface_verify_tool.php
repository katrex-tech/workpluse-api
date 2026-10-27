<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/compreface_service.php';

echo "=================================================================\n";
echo "WorkPulse - Exadel CompreFace Biometric Engine Diagnostic Tool\n";
echo "=================================================================\n\n";

$cf = new CompreFaceService($config);

echo "1. Configuration Check:\n";
echo "   - Configured: " . ($cf->isConfigured() ? "YES" : "NO (API key or URL missing)") . "\n";
echo "   - Base URL:   " . ($config['compreface']['url'] ?? 'not set') . "\n";
echo "   - API Key:    " . (!empty($config['compreface']['api_key']) ? substr($config['compreface']['api_key'], 0, 8) . '...' : 'EMPTY (Need to generate in CompreFace UI)') . "\n";
echo "   - Threshold:  " . ($config['compreface']['similarity_threshold'] ?? 0.85) . " (85% required for punch)\n\n";

echo "2. Connection & Health Check:\n";
$health = $cf->checkHealth();
if ($health['healthy']) {
    echo "   [SUCCESS] CompreFace server is ONLINE and responding at {$health['base_url']} (HTTP {$health['http_code']})\n\n";
} else {
    echo "   [OFFLINE] CompreFace server is not reachable: " . ($health['error'] ?? 'Unknown error') . "\n";
    echo "   -> To start CompreFace on your machine:\n";
    echo "      1. Install Docker Desktop: https://www.docker.com/products/docker-desktop/\n";
    echo "      2. Open folder: cd c:\\Users\\ASUS\\Downloads\\CompreFace-master\\CompreFace-master\n";
    echo "      3. Run: docker-compose up -d\n";
    echo "      4. Open browser: http://localhost:8000/login\n";
    echo "      5. Sign up, create an application 'WorkPulse', and create a Recognition Service.\n";
    echo "      6. Copy the generated API Key and paste it into config/config.php!\n\n";
}

echo "3. API Verification Endpoints Summary:\n";
echo "   - Face Enrollment:  POST /api/v1/recognition/faces?subject={employee_id}\n";
echo "   - 1:1 Verification: POST /api/v1/recognition/faces/{image_id}/verify\n";
echo "   - 1:N Recognition:  POST /api/v1/recognition/recognize\n";
echo "   - Face Deletion:    DELETE /api/v1/recognition/faces/{image_id}\n\n";

echo "=================================================================\n";
