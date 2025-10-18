<?php

$controllersDir = __DIR__ . '/src/Controllers';
$files = glob($controllersDir . '/*.php');

echo "Removing checkAuth() calls from controllers...\n\n";

foreach ($files as $file) {
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // Remove the pattern: if (!$this->checkAuth()) {\n            return;\n        }\n\n
    $content = preg_replace(
        '/\s+if \(\!\\$this->checkAuth\(\)\) \{\s+return;\s+\}\s+/m',
        "\n        ",
        $content
    );
    
    // Also try alternative spacing
    $content = preg_replace(
        '/\s+\/\/ Проверка токена\s+if \(\!\\$this->checkAuth\(\)\) \{\s+return;\s+\}\s+/m',
        "\n        ",
        $content
    );
    
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        echo "✅ Updated: " . basename($file) . "\n";
    } else {
        echo "⏭️  Skipped: " . basename($file) . " (no changes)\n";
    }
}

echo "\nDone!\n";

