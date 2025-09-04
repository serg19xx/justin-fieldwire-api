<?php

// Simple avatar display script
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract filename from path like /avatar/user_1_1234567890.jpg
if (preg_match('/\/avatar\/(.+)$/', $path, $matches)) {
    $filename = $matches[1];
    $filepath = __DIR__ . '/uploads/avatars/' . $filename;
    
    if (file_exists($filepath)) {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        // Set appropriate content type
        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpeg':
                header('Content-Type: image/jpeg');
                break;
            case 'png':
                header('Content-Type: image/png');
                break;
            case 'gif':
                header('Content-Type: image/gif');
                break;
            default:
                header('Content-Type: application/octet-stream');
        }
        
        // Set cache headers
        header('Cache-Control: public, max-age=31536000'); // 1 year
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
        
        // Output file
        readfile($filepath);
        exit;
    }
}

// If file not found, return 404
http_response_code(404);
echo 'Avatar not found';
