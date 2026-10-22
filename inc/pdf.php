<?php
// HTML -> PDF with headless Chrome/Edge on the server (Apache runs as SYSTEM and can exec it). The page is served back by Apache
// from tmp/ (local-only) so the browser loads the site fonts like a normal page.
declare(strict_types=1);

/** Renders $html (a full page) to PDF bytes. Throws RuntimeException when no browser is installed or printing failed. */
function html_to_pdf(string $html): string
{
    @set_time_limit(60);
    $browser = null;
    foreach ([
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    ] as $p) { if (is_file($p)) { $browser = $p; break; } }
    if (!$browser) throw new RuntimeException('مفيش متصفح Chrome أو Edge على السيرفر لتوليد الـPDF');

    $base = 'http://127.0.0.1' . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/factory/x.php')), '/') . '/';
    $html = preg_replace('/<head>/i', '<head><base href="' . htmlspecialchars($base, ENT_QUOTES) . '">', $html, 1);

    $key = bin2hex(random_bytes(8));
    $webTmp = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($webTmp)) @mkdir($webTmp);
    @file_put_contents($webTmp . DIRECTORY_SEPARATOR . '.htaccess', "Require local\n");
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'html_pdf_' . $key;
    @mkdir($tmp);
    $htmlFile = $webTmp . DIRECTORY_SEPARATOR . 'pdf_' . $key . '.html';
    $pdfFile = $tmp . DIRECTORY_SEPARATOR . 'doc.pdf';
    file_put_contents($htmlFile, $html);
    $cmd = '"' . $browser . '" --headless=new --disable-gpu --no-sandbox --no-first-run --disable-extensions --user-data-dir="' . $tmp . DIRECTORY_SEPARATOR . 'profile"'
         . ' --no-pdf-header-footer --virtual-time-budget=5000 --print-to-pdf="' . $pdfFile . '" "' . $base . 'tmp/pdf_' . $key . '.html" 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    $ok = is_file($pdfFile) && filesize($pdfFile) > 1000;
    $pdf = $ok ? (string)file_get_contents($pdfFile) : '';
    @unlink($htmlFile);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $f) @unlink($f);
    $rm = function (string $d) use (&$rm) { foreach (glob($d . '/*') ?: [] as $f) is_dir($f) ? $rm($f) : @unlink($f); @rmdir($d); };
    $rm($tmp);
    if (!$ok) throw new RuntimeException('تعذر توليد الـPDF: ' . implode(' ', array_slice($out, -3)));
    return $pdf;
}

/** Sends PDF bytes as a download (or inline) with a UTF-8 file name. */
function send_pdf(string $pdf, string $name, bool $inline = false): never
{
    $name = trim(preg_replace('/[\\\\\\/:*?"<>|]+/u', ' ', $name)) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"document.pdf\"; filename*=UTF-8''" . rawurlencode($name));
    echo $pdf;
    exit;
}
