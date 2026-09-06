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

/**
 * Absolute path to the Tesseract OCR binary (override via
 * TESSERACT_PATH environment variable if installed elsewhere).
 */
function tesseractPath(): string
{
    return trim((string) (getenv('TESSERACT_PATH') ?: 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'));
}

/**
 * Check whether OCR (Tesseract) is available on this machine.
 *
 * Used to surface an install reminder on upload/screening pages when the OCR
 * binary is missing, so image-only PDFs fail to be readable for a known reason
 * instead of silently showing "Unavailable".
 *
 * @return array{ok: bool, path: string, reason: string, hint: string}
 */
function ocrAvailabilityCheck(): array
{
    $path = tesseractPath();
    $ok = $path !== '';
    $reason = '';
    $hint = '';

    if (!$ok) {
        $reason = 'Tesseract OCR path is not configured (TESSERACT_PATH is empty).';
    } elseif (!file_exists($path)) {
        $ok = false;
        $reason = 'Tesseract OCR is not installed at: ' . $path;
    } elseif (!is_executable($path)) {
        $ok = false;
        $reason = 'Tesseract OCR binary exists but is not executable: ' . $path;
    }

    if (!$ok) {
        $hint = 'Install Tesseract OCR to enable resume text extraction for image-only PDF files. '
            . 'On Windows run:  winget install --id UB-Mannheim.TesseractOCR -e '
            . '--accept-source-agreements --accept-package-agreements --silent';
    }

    return ['ok' => $ok, 'path' => $path, 'reason' => $reason, 'hint' => $hint];
}

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

    // Text-object extraction (handles FlateDecode/ASCII85Decode streams).
    $fromStreams = extractRawText($filePath);
    if (trim($fromStreams) !== '') {
        return trim($fromStreams);
    }

    // Image-only PDF (no text layer): OCR embedded JPEGs via Tesseract.
    $ocr = ocrPdfImages($filePath);
    if (trim($ocr) !== '') {
        return trim($ocr);
    }

    return '';
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

    // For PDF, extract text from BT/ET text objects. Only accept real text
    // objects; JPEG/image-only streams produce no text and must NOT be scored
    // as binary noise.
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf') {
        return extractPdfTextStreams($raw);
    }

    // Generic: pull sequences of 4+ printable ASCII chars
    preg_match_all('/[\x20-\x7E\x0A\x0D]{5,}/', $raw, $matches);
    $text = implode(' ', $matches[0] ?? []);

    // Remove common binary artifacts
    $text = preg_replace('/\[(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\]/', '', $text);
    $text = preg_replace('/obj\s*<<.*?>>/s', '', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * OCR an image-only PDF by extracting its embedded JPEG (DCTDecode) streams
 * and running Tesseract on each page image.
 *
 * Returns '' if Tesseract is unavailable/fails to produce text, so callers
 * can treat the resume as unreadable.
 */
function ocrPdfImages(string $filePath): string
{
    $tesseract = tesseractPath();
    if ($tesseract === '' || !is_executable($tesseract) || !file_exists($tesseract)) {
        return '';
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false || $raw === '') {
        return '';
    }

    // Extract each image stream. Use /Length so binary DCTDecode payload
    // containing the literal string "endstream" can't truncate the stream.
    $images = [];
    if (preg_match_all('/<<(?:(?!stream).)*?\/Subtype\s*\/Image\b(?:(?!stream).)*?\/Filter\s*\/DCTDecode\b(?:(?!stream).)*?>>\s*stream/s', $raw, $dicts, PREG_OFFSET_CAPTURE)) {
        foreach ($dicts[0] as $m) {
            $dictAndMarker = $m[0];
            $dictStart = $m[1];
            if (!preg_match('/\/Length\s+(\d+)/', $dictAndMarker, $lm)) {
                continue;
            }
            $length = (int) $lm[1];
            if ($length <= 0 || $length > 20000000) {
                continue;
            }
            $streamMarkerPos = strrpos($dictAndMarker, 'stream');
            if ($streamMarkerPos === false) {
                continue;
            }
            $dataStart = $dictStart + $streamMarkerPos + 6; // "stream"
            if (substr($raw, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (substr($raw, $dataStart, 1) === "\n") {
                $dataStart += 1;
            } elseif (substr($raw, $dataStart, 1) === "\r") {
                $dataStart += 1;
            }
            $images[] = substr($raw, $dataStart, $length);
        }
    }

    // Fallback: scan for raw JPEG SOI markers (SOI + a few bytes then EOI).
    if (empty($images)) {
        $pos = 0;
        while (($soi = strpos($raw, "\xFF\xD8", $pos)) !== false) {
            $eoi = strpos($raw, "\xFF\xD9", $soi + 2);
            if ($eoi === false) {
                break;
            }
            $images[] = substr($raw, $soi, $eoi - $soi + 2);
            $pos = $eoi + 2;
        }
    }

    if (empty($images)) {
        return '';
    }

    $combined = '';
    foreach ($images as $i => $jpg) {
        $tmpImg = tempfile_path('img' . $i . '.jpg');
        $tmpBase = substr($tmpImg, 0, -4);
        @file_put_contents($tmpImg, $jpg);
        if (!file_exists($tmpImg)) {
            continue;
        }

        $cmd = escapeshellarg($tesseract)
             . ' ' . escapeshellarg($tmpImg)
             . ' ' . escapeshellarg($tmpBase)
             . ' -l eng --psm 3 2>&1';
        $output = trim((string) @shell_exec($cmd));

        $txtFile = $tmpBase . '.txt';
        if (file_exists($txtFile)) {
            $combined .= ' ' . (string) @file_get_contents($txtFile);
            @unlink($txtFile);
        }
        @unlink($tmpImg);
    }

    return trim(preg_replace('/\s+/', ' ', $combined));
}

/**
 * Attempt to extract text from raw PDF binary by finding text stream content.
 *
 * Handles plain (uncompressed) BT/ET text objects as well as streams using
 * /FlateDecode and /ASCII85Decode filters (individually or chained, e.g.
 * [ /ASCII85Decode /FlateDecode ]). Image-only streams never yield text.
 */
function extractPdfTextStreams(string $raw): string
{
    $text = '';

    // Method 1: Extract text between BT and ET markers (uncompressed content)
    $text .= extractTextFromPdfContent($raw);

    // Method 2: decode /FlateDecode and /ASCII85Decode streams and parse them
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($streams as $streamMatch) {
            $payload = $streamMatch[1][0];
            $fullOffset = $streamMatch[0][1];

            // Find the /Filter declaration in the object dictionary right
            // before this stream to determine the decoding chain.
            $windowStart = max(0, $fullOffset - 700);
            $before = substr($raw, $windowStart, $fullOffset - $windowStart);

            $filters = [];
            if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $before, $fm)) {
                if (preg_match_all('/\/([A-Za-z0-9]+)/', $fm[1], $inner)) {
                    $filters = $inner[1];
                }
            } elseif (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $before, $fm)) {
                $filters = [$fm[1]];
            }

            if (empty($filters)) {
                continue;
            }

            $decoded = $payload;
            $changed = false;
            foreach ($filters as $filter) {
                if ($filter === 'ASCII85Decode') {
                    $decoded = ascii85Decode((string) $decoded);
                    $changed = true;
                } elseif ($filter === 'FlateDecode') {
                    $gz = @gzuncompress((string) $decoded);
                    if ($gz === false && function_exists('gzinflate')) {
                        $gz = @gzinflate((string) $decoded);
                    }
                    if ($gz !== false) {
                        $decoded = $gz;
                        $changed = true;
                    }
                }
            }

            if ($changed && $decoded !== '') {
                $text .= ' ' . extractTextFromPdfContent((string) $decoded);
            }
        }
    }

    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Parse PDF content instructions for text operators (Tj and TJ within
 * BT/ET blocks) and concatenate the extracted strings.
 */
function extractTextFromPdfContent(string $content): string
{
    $text = '';

    if (preg_match_all('/BT\s(.*?)\sET/s', $content, $btMatches)) {
        foreach ($btMatches[1] as $btContent) {
            // Single string: (text) Tj
            if (preg_match_all('/\(([^)]*)\)\s*Tj/', $btContent, $strMatches)) {
                foreach ($strMatches[1] as $str) {
                    $text .= decodePdfString($str) . ' ';
                }
            }
            // Array of strings with adjustments: [(text1) (text2)] TJ
            if (preg_match_all('/\[((?:[^\[\]]|\([^)]*\))+)\]\s*TJ/s', $btContent, $arrMatches)) {
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
 * Decode an ASCII85 (Base85) encoded string (PDF /ASCII85Decode filter).
 * Supports the 'z' shorthand for four zero bytes and partial trailing groups.
 */
function ascii85Decode(string $data): string
{
    $data = preg_replace('/\s+/', '', $data);
    if (substr($data, -2) === '~>') {
        $data = substr($data, 0, -2);
    }

    $out = '';
    $len = strlen($data);
    $i = 0;
    while ($i < $len) {
        $ch = $data[$i];
        if ($ch === 'z') {
            $out .= "\0\0\0\0";
            $i++;
            continue;
        }

        $chunk = substr($data, $i, 5);
        $clen = strlen($chunk);
        if ($chunk === '') {
            break;
        }

        if ($clen < 5) {
            // Final partial group: pad with 'u' (84); n chars yield n-1 bytes
            $padded = str_pad($chunk, 5, 'u');
            $value = 0;
            for ($k = 0; $k < 5; $k++) {
                $value = $value * 85 + (ord($padded[$k]) - 33);
            }
            $out .= substr(pack('N', $value), 0, $clen - 1);
            break;
        }

        $value = 0;
        for ($k = 0; $k < 5; $k++) {
            $value = $value * 85 + (ord($chunk[$k]) - 33);
        }
        $out .= pack('N', $value);
        $i += 5;
    }

    return $out;
}

/**
 * Decode a PDF string: unescape PDF literal-string escapes (including octal
 * \ddd), then convert the byte stream to UTF-8. UTF-16BE is detected via
 * embedded NUL bytes; otherwise the typical Windows-1252 (WinAnsiEncoding)
 * mapping is used.
 */
function decodePdfString(string $str): string
{
    if ($str === '') {
        return '';
    }

    // Unescape PDF literal-string backslash escapes (incl. octal \ddd).
    $out = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $ch = $str[$i];
        if ($ch !== '\\') {
            $out .= $ch;
            continue;
        }
        $i++;
        if ($i >= $len) {
            break;
        }
        $nx = $str[$i];
        if ($nx === 'n') {
            $out .= "\n";
        } elseif ($nx === 'r') {
            $out .= "\r";
        } elseif ($nx === 't') {
            $out .= "\t";
        } elseif ($nx === 'b') {
            $out .= "\x08";
        } elseif ($nx === 'f') {
            $out .= "\x0C";
        } elseif ($nx === '(' || $nx === ')' || $nx === '\\') {
            $out .= $nx;
        } elseif (strpos('01234567', $nx) !== false) {
            $oct = $nx;
            $j = $i + 1;
            while ($j < $len && $j < $i + 3 && strpos('01234567', $str[$j]) !== false) {
                $oct .= $str[$j];
                $j++;
            }
            $out .= chr(octdec($oct));
            $i = $j - 1;
        } else {
            $out .= $nx;
        }
    }

    // UTF-16BE detection: genuine UTF-16 text carries NUL bytes for ASCII.
    if (strpos($out, "\x00") !== false) {
        $decoded = @iconv('UTF-16BE', 'UTF-8//IGNORE', $out);
        if ($decoded !== false) {
            return $decoded;
        }
    }

    // Windows-1252 (WinAnsiEncoding) is the standard for PDF text strings.
    $converted = @iconv('CP1252', 'UTF-8//IGNORE', $out);
    return ($converted !== false && $converted !== '') ? $converted : $out;
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
