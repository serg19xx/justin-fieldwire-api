<?php
echo "<h1>File Check</h1>";

$files = [
    '../vendor/autoload.php' => 'Composer Autoloader',
    '../.env' => 'Environment File',
    '../composer.json' => 'Composer Config',
    '../src/Bootstrap/Application.php' => 'Application Bootstrap',
    '../src/Config/Config.php' => 'Config Class',
    'index.php' => 'Main Index',
    'index-php74.php' => 'PHP 7.4 Index',
    'simple-test.php' => 'Simple Test',
    '.htaccess' => 'Apache Config'
];

echo "<table border='1'>";
echo "<tr><th>File</th><th>Description</th><th>Exists</th><th>Size</th></tr>";

foreach ($files as $file => $desc) {
    $fullPath = __DIR__ . '/' . $file;
    $exists = file_exists($fullPath);
    $size = $exists ? filesize($fullPath) : 0;
    
    echo "<tr>";
    echo "<td>$file</td>";
    echo "<td>$desc</td>";
    echo "<td>" . ($exists ? '✅ YES' : '❌ NO') . "</td>";
    echo "<td>" . ($exists ? $size . ' bytes' : 'N/A') . "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Directory Contents</h2>";
echo "<h3>Current Directory (" . __DIR__ . "):</h3>";
echo "<ul>";
$files = scandir(__DIR__);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $path = __DIR__ . '/' . $file;
        $type = is_dir($path) ? 'DIR' : 'FILE';
        $size = is_file($path) ? filesize($path) . ' bytes' : 'N/A';
        echo "<li>$file ($type) - $size</li>";
    }
}
echo "</ul>";

echo "<h3>Parent Directory (" . dirname(__DIR__) . "):</h3>";
echo "<ul>";
$files = scandir(dirname(__DIR__));
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $path = dirname(__DIR__) . '/' . $file;
        $type = is_dir($path) ? 'DIR' : 'FILE';
        $size = is_file($path) ? filesize($path) . ' bytes' : 'N/A';
        echo "<li>$file ($type) - $size</li>";
    }
}
echo "</ul>";
?>
