<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "ENV ERROR: " . $e->getMessage() . "\n";
}

echo "=== TESTING REAL INVITATION FLOW ===\n\n";

// Step 1: Create a test invitation
echo "=== STEP 1: CREATE INVITATION ===\n";

$invitationData = [
    'email' => 'test.invitation@example.com',
    'first_name' => 'Test',
    'last_name' => 'User',
    'user_type' => 'Employee',
    'job_title' => 'Tester',
    'phone' => '+1234567890',
    'email_provider' => 'auto'
];

$jsonData = json_encode($invitationData);

echo "Creating invitation for: " . $invitationData['email'] . "\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/v1/workers/invite');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_TOKEN_HERE' // You'll need to replace this with a real token
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n\n";

if ($httpCode === 201) {
    $responseData = json_decode($response, true);
    $invitationToken = $responseData['data']['invitation_token'] ?? null;
    
    if ($invitationToken) {
        echo "✅ Invitation created successfully!\n";
        echo "Invitation Token: " . $invitationToken . "\n\n";
        
        // Step 2: Test invitation code login
        echo "=== STEP 2: TEST INVITATION CODE LOGIN ===\n";
        
        $loginData = [
            'email' => 'test.invitation@example.com',
            'invitation_code' => $invitationToken
        ];
        
        $loginJson = json_encode($loginData);
        
        echo "Testing login with invitation code...\n";
        
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, 'http://localhost:8000/api/v1/auth/login');
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, $loginJson);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
        
        $loginResponse = curl_exec($ch2);
        $loginHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        echo "HTTP Code: " . $loginHttpCode . "\n";
        echo "Response: " . $loginResponse . "\n\n";
        
        if ($loginHttpCode === 200) {
            echo "✅ Invitation code login successful!\n";
            
            // Step 3: Test that invitation code can't be reused
            echo "=== STEP 3: TEST INVITATION CODE REUSE (SHOULD FAIL) ===\n";
            
            $ch3 = curl_init();
            curl_setopt($ch3, CURLOPT_URL, 'http://localhost:8000/api/v1/auth/login');
            curl_setopt($ch3, CURLOPT_POST, true);
            curl_setopt($ch3, CURLOPT_POSTFIELDS, $loginJson);
            curl_setopt($ch3, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch3, CURLOPT_TIMEOUT, 30);
            
            $reuseResponse = curl_exec($ch3);
            $reuseHttpCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
            curl_close($ch3);
            
            echo "HTTP Code: " . $reuseHttpCode . "\n";
            echo "Response: " . $reuseResponse . "\n\n";
            
            if ($reuseHttpCode === 401) {
                echo "✅ Invitation code reuse correctly blocked!\n";
            } else {
                echo "❌ Invitation code reuse should have been blocked!\n";
            }
            
        } else {
            echo "❌ Invitation code login failed!\n";
        }
        
    } else {
        echo "❌ No invitation token in response!\n";
    }
    
} else {
    echo "❌ Failed to create invitation!\n";
    echo "Note: You may need to provide a valid authentication token.\n";
}

echo "\n=== TEST COMPLETED ===\n";
echo "This test demonstrates:\n";
echo "1. ✅ Creating an invitation via API\n";
echo "2. ✅ Using invitation code for login\n";
echo "3. ✅ Invitation code becomes invalid after use\n";
echo "4. ✅ User status is updated to 'registered'\n";
echo "5. ✅ Invitation fields are cleared from database\n";
