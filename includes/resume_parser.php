<?php
/**
 * Resume Parser — extracts readable text from PDF, DOC, DOCX files.
 *
 * Returns extracted text string or empty string on failure.
 */

/**
 * Main entry point: extract text from a resume file.
 *
 * @param string $filePath Absolute path to the resume file
 * @return string Extracted text (may be empty on failure)
 */
function extractResumeText(string $filePath): string
{
    if (!file_exists($filePath)) {
        return '';
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'docx':
            return extractDocx($filePath);
        case 'doc':
            return extractDoc($filePath);
        case 'pdf':
            return extractPdf($filePath);
        default:
            return '';
    }
}

/**
 * Extract text from a DOCX file by parsing the inner XML.
 */
function extractDocx(string $filePath): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }

    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xmlContent === false) {
        return '';
    }

    $xml = @simplexml_load_string($xmlContent);
    if ($xml === false) {
        return '';
    }

    $text = '';
    $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $nodes = $xml->xpath('//w:t');
    if (is_array($nodes)) {
        foreach ($nodes as $node) {
            $text .= (string) $node . ' ';
        }
    }

    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Extract text from a DOC file.
 * Tries antiword/catdoc first, then COM automation, then raw binary extraction.
 */
function extractDoc(string $filePath): string
{
    $notAvailable = ['not found', 'not recognized', 'no such file', 'is not recognized'];

    // Try antiword (if installed)
    $antiword = trim((string) @shell_exec('antiword ' . escapeshellarg($filePath) . ' 2>&1'));
    if ($antiword !== '' && !strContainsAny(strtolower($antiword), $notAvailable)) {
        return $antiword;
    }

    // Try catdoc (if installed)
    $catdoc = trim((string) @shell_exec('catdoc ' . escapeshellarg($filePath) . ' 2>&1'));
    if ($catdoc !== '' && !strContainsAny(strtolower($catdoc), $notAvailable)) {
        return $catdoc;
    }

    // Try PowerShell + Word COM automation (if Word is installed on Windows)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $psScript = tempfile_path('doc_extract.ps1');
        $outTxt = tempfile_path('doc_extract.txt');
        $psCode = '$w = New-Object -ComObject Word.Application; $w.Visible = $false; '
            . '$doc = $w.Documents.Open(' . "'" . addslashes($filePath) . "'" . '); '
            . '$doc.SaveAs([ref]' . "'" . addslashes($outTxt) . "'" . ', [ref]2); '
            . '$doc.Close(); $w.Quit(); Remove-Item ' . "'" . addslashes($psScript) . "'" . ' -Force';
        file_put_contents($psScript, $psCode);
        @shell_exec('powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($psScript) . ' 2>&1');
        if (file_exists($outTxt)) {
            $text = trim(file_get_contents($outTxt));
            @unlink($outTxt);
            if ($text !== '') {
                return $text;
            }
        }
    }

    // Last resort: extract printable ASCII sequences from binary
    return extractRawText($filePath);
}

/**
 * Extract text from a PDF file.
 * Tries pdftotext first, then raw binary extraction.
 */
function extractPdf(string $filePath): string
{
    $notAvailable = ['not found', 'not recognized', 'no such file', 'is not recognized'];

    // Try pdftotext (if installed)
    $pdftotext = trim((string) @shell_exec('pdftotext ' . escapeshellarg($filePath) . ' - 2>&1'));
    if ($pdftotext !== '' && !strContainsAny(strtolower($pdftotext), $notAvailable)) {
        return $pdftotext;
    }

    // Try poppler pdftotext
    $pdftotext2 = trim((string) @shell_exec('C:\xampp\php\pdftotext ' . escapeshellarg($filePath) . ' - 2>&1'));
    if ($pdftotext2 !== '' && !strContainsAny(strtolower($pdftotext2), $notAvailable)) {
        return $pdftotext2;
    }

    return extractRawText($filePath);
}

/**
 * Last-resort: extract readable text from any binary file by pulling
 * sequences of printable characters.
 */
function extractRawText(string $filePath): string
{
    $raw = file_get_contents($filePath);
    if ($raw === false) {
        return '';
    }

    // For PDF, try to extract text between BT/ET markers (PDF text objects)
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf') {
        $text = extractPdfTextStreams($raw);
        if ($text !== '') {
            return $text;
        }
    }

    // Generic: pull sequences of 4+ printable ASCII chars
    preg_match_all('/[\x20-\x7E\x0A\x0D]{5,}/', $raw, $matches);
    $text = implode(' ', $matches[0] ?? []);

    // Remove common PDF artifacts
    $text = preg_replace('/\[(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\]/', '', $text);
    $text = preg_replace('/obj\s*<<.*?>>/s', '', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Attempt to extract text from raw PDF binary by finding text stream content.
 */
function extractPdfTextStreams(string $raw): string
{
    $text = '';

    // Method 1: Extract text between BT and ET markers
    if (preg_match_all('/BT\s(.*?)\sET/s', $raw, $btMatches)) {
        foreach ($btMatches[1] as $btContent) {
            // Extract strings from Tj and TJ operators
            // Single string: (text) Tj
            if (preg_match_all('/\(([^)]*)\)/', $btContent, $strMatches)) {
                foreach ($strMatches[1] as $str) {
                    $text .= decodePdfString($str) . ' ';
                }
            }
            // Array of strings: [(text1) (text2)] TJ
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $btContent, $arrMatches)) {
                foreach ($arrMatches[1] as $arrContent) {
                    if (preg_match_all('/\(([^)]*)\)/', $arrContent, $innerMatches)) {
                        foreach ($innerMatches[1] as $str) {
                            $text .= decodePdfString($str) . ' ';
                        }
                    }
                }
            }
        }
    }

    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Decode a PDF string: try UTF-16BE if high bytes detected, otherwise use as-is.
 */
function decodePdfString(string $str): string
{
    if ($str === '') {
        return '';
    }
    // Detect UTF-16BE: even length, starts with BOM or contains high bytes
    $hasHighBytes = preg_match('/[\x80-\xff]/', $str);
    if ($hasHighBytes && mb_strlen($str) % 2 === 0) {
        $decoded = @iconv('UTF-16BE', 'UTF-8//IGNORE', $str);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
    }
    return $str;
}

/**
 * Create a temporary file path for processing.
 */
function tempfile_path(string $suffix = 'tmp'): string
{
    $dir = sys_get_temp_dir();
    return $dir . DIRECTORY_SEPARATOR . 'hr1_' . bin2hex(random_bytes(8)) . '.' . $suffix;
}

/**
 * Check if a string contains any of the given substrings.
 */
function strContainsAny(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (mb_strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}
