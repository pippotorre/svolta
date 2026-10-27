<?php
/**
 * Ponte per i PDF: il lettore dell'app li carica da qui, così non dipende
 * dalle impostazioni di Amazon S3. Accetta solo PDF della cartella delle circolari.
 */
require __DIR__ . '/inc/lib.php';

$url = (string) ($_GET['u'] ?? '');
$prefix = (string) cfg('pdf_prefix');
if ($url === '' || !str_starts_with($url, $prefix) || preg_match('#\.\./|[\s<>"]#', $url) || !preg_match('#\.pdf$#i', parse_url($url, PHP_URL_PATH) ?? '')) {
    http_response_code(400);
    exit('Indirizzo non consentito');
}

$cacheDir = __DIR__ . '/cache/pdf';
$cacheFile = $cacheDir . '/' . sha1($url) . '.pdf';

if (!is_file($cacheFile)) {
    $data = http_get($url, 40);
    if ($data === null || strncmp($data, '%PDF', 4) !== 0) {
        http_response_code(502);
        exit('PDF non disponibile');
    }
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    if (!@file_put_contents($cacheFile, $data)) {
        header('Content-Type: application/pdf');
        header('Cache-Control: public, max-age=86400');
        echo $data;
        exit;
    }
    // tengo in cache al massimo gli ultimi 200 PDF
    $files = glob($cacheDir . '/*.pdf') ?: [];
    if (count($files) > 200) {
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - 200) as $old) {
            @unlink($old);
        }
    }
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($cacheFile));
header('Cache-Control: public, max-age=86400');
readfile($cacheFile);
