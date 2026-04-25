<?php
/**
 * combo.php — YUI combo-loader port from combo.asp
 *
 * Serves concatenated YUI JS or CSS files from a pre-built library file.
 * Query string contains file paths separated by '&'.
 * Example: combo.php?yui/2.9.0/build/yahoo/yahoo-min.js&yui/2.9.0/build/dom/dom-min.js
 */

$queryString = $_SERVER['QUERY_STRING'] ?? '';

if ($queryString === '') {
    exit;
}

$yuiFiles   = explode('&', $queryString);
$firstFile  = $yuiFiles[0] ?? '';

// Determine content type from the first file's extension
$contentType = (strpos($firstFile, '.js') !== false)
    ? 'application/x-javascript'
    : 'text/css';

$yuiComponents = [];

if ($contentType === 'application/x-javascript') {
    foreach ($yuiFiles as $yuiFile) {
        $parts = explode('/', $yuiFile);
        if (count($parts) === 4) {
            // e.g. yui/2.9.0/yahoo/yahoo → component = "yahoo/yahoo"
            if (!empty($parts[0]) && !empty($parts[1]) && !empty($parts[2])) {
                $yuiComponents[] = $parts[2] . '/' . $parts[2];
            } else {
                exit;
            }
        } else {
            // General path: extract between "/build/" and ".js"
            $start = strpos($yuiFile, '/build/');
            if ($start === false) exit;
            $start += strlen('/build/');
            $end = strpos($yuiFile, '.js');
            if ($end === false) exit;
            $yuiComponents[] = substr($yuiFile, $start, $end - $start);
        }
    }
    $libraryPath = __DIR__ . '/include/yui/yui.lib';
} else {
    foreach ($yuiFiles as $yuiFile) {
        $start = strpos($yuiFile, '/build/');
        if ($start === false) exit;
        $start += strlen('/build/');
        $yuiComponents[] = substr($yuiFile, $start);
    }
    $libraryPath = __DIR__ . '/include/yui/yuicss.lib';
}

// Read library file
if (!file_exists($libraryPath)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}
$library = file_get_contents($libraryPath);

// Set long-lived cache headers
header('Cache-Control: max-age=315360000');
header('Expires: Thu, 29 Oct 2020 20:00:00 GMT');
header('Content-Type: ' . $contentType);

// Output each component's slice from the library
foreach ($yuiComponents as $y) {
    $marker = 'begincombofile ' . $y;
    $start  = strpos($library, $marker);
    if ($start === false) exit;
    $start += strlen($marker);

    $end = strpos($library, 'endcombofile', $start);
    if ($end === false) exit;

    echo substr($library, $start, $end - $start);
}
