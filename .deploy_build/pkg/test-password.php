<?php

require_once 'vendor/autoload.php';

// Test password verification
$password = 'password1234';
$hash = '$2y$10$FxdruuEb7C0ltbVB12eB3uxJtjbSDewiztVrKYHNGuOatLrt9IKnC';

echo "Testing password verification...\n";
echo "Password: $password\n";
echo "Hash: $hash\n\n";

$result = password_verify($password, $hash);
echo "Password verification result: " . ($result ? 'TRUE' : 'FALSE') . "\n";

// Test with different passwords
$testPasswords = [
    'password1234',
    'password123',
    'password',
    'admin',
    '123456',
    'password1234!',
    'Password1234',
    'PASSWORD1234'
];

echo "\nTesting different passwords:\n";
foreach ($testPasswords as $testPassword) {
    $result = password_verify($testPassword, $hash);
    echo "- '$testPassword': " . ($result ? 'MATCH' : 'NO MATCH') . "\n";
}
