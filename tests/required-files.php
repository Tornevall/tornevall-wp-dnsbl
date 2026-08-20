<?php

$root = dirname(__DIR__);
$mainFile = $root . '/tornevall-wp-dnsbl.php';
$main = file_get_contents($mainFile);

if ($main === false) {
    fwrite(STDERR, "Unable to read tornevall-wp-dnsbl.php\n");
    exit(1);
}

$referenced = [];

if (preg_match('/foreach\s*\(\s*\[(.*?)\]\s+as\s+\$tornevallDnsblInclude/s', $main, $includeBlock)) {
    preg_match_all('/[\'\"]([^\'\"]+\.php)[\'\"]/', $includeBlock[1], $includeMatches);
    $referenced = array_merge($referenced, $includeMatches[1]);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }

    preg_match_all(
        '/TORNEVALL_DNSBL_PLUGIN_DIR\s*\.\s*[\'\"]([^\'\"]+\.php)[\'\"]/',
        $content,
        $directMatches
    );
    $referenced = array_merge($referenced, $directMatches[1]);

    preg_match_all('/[\'\"](templates\/[^\'\"]+\.php)[\'\"]/', $content, $templateMatches);
    $referenced = array_merge($referenced, $templateMatches[1]);
}

$referenced = array_values(array_unique($referenced));
$missing = [];

foreach ($referenced as $relativePath) {
    if (!is_file($root . '/' . $relativePath)) {
        $missing[] = $relativePath;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "Missing referenced plugin files:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

echo 'Verified ' . count($referenced) . " referenced plugin files.\n";
