<?php
require_once __DIR__ . '/incident_upload_helper.php';
require_once __DIR__ . '/role_helper.php';

function gsoRequestLetterStorageRoot(bool $writable = false): string
{
    $path = trim((string) gsoEnv('GSO_REQUEST_LETTER_DIR', ''));
    if ($path === '') $path = dirname(dirname(__DIR__, 2), 2) . '/private/GSO_WebSystem/request_letters';
    if (!incidentPathIsAbsolute($path) || is_link($path)) throw new RuntimeException('Invalid request-letter storage.');
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) throw new RuntimeException('Request-letter storage unavailable.');
    $resolved = realpath($path);
    $roots = [realpath(dirname(__DIR__, 2)), realpath(dirname(dirname(__DIR__, 2)))];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $roots[] = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($resolved === false || is_link($path) || !is_readable($resolved) || ($writable && !is_writable($resolved))) throw new RuntimeException('Request-letter storage unavailable.');
    foreach ($roots as $root) if ($root !== false && incidentPathIsWithin($resolved, $root)) throw new RuntimeException('Request letters must be outside the public web root.');
    return $resolved;
}

function gsoRequestLetterPath(string $name): string
{
    if (!preg_match('/\Aletter_[a-f0-9]{32}\.(pdf|jpg|png)\z/', $name)) throw new RuntimeException('Invalid document reference.');
    $root = gsoRequestLetterStorageRoot();
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (is_link($path)) throw new RuntimeException('Invalid document reference.');
    return $path;
}

function gsoValidateRequestLetter(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) throw new DomainException(gsoUploadErrorMessage($error, 'signed facility request letter', 5 * 1024 * 1024));
    $tmp = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new DomainException('Invalid request-letter upload.');
    $size = filesize($tmp);
    if (!$size || $size > gsoEffectiveUploadLimitBytes(5 * 1024 * 1024)) throw new DomainException('The request letter exceeds the upload limit or is empty.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $extensions = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
    if (!isset($types[$mime]) || !in_array($extension, $extensions[$mime], true)) throw new DomainException('Upload a PDF, JPG or PNG request letter with a matching file format.');
    $content = file_get_contents($tmp);
    if ($content === false || preg_match('/<\?(?:php|=)|<script\b/i', $content)) throw new DomainException('Script content is not allowed in request letters.');
    if ($mime === 'application/pdf') {
        $pdfNames=preg_replace_callback('/#([a-f0-9]{2})/i',static fn($m)=>chr(hexdec($m[1])),$content);
        if (!str_starts_with($content, '%PDF-') || !str_contains(substr($content, -2048), '%%EOF')
            || preg_match('~/(?:JavaScript|JS|Launch|EmbeddedFile|RichMedia|OpenAction|AA|SubmitForm|ImportData|ObjStm)\b~i', $pdfNames)) throw new DomainException('Upload a static PDF without scripts or embedded executable content.');
    } else {
        $image = @getimagesize($tmp);
        if (!$image || ($image['mime'] ?? '') !== $mime || $image[0] < 1 || $image[1] < 1 || $image[0] > 4096 || $image[1] > 4096 || $image[0] * $image[1] > 12000000) throw new DomainException('Invalid or oversized request-letter image.');
        if ($mime === 'image/png') {
            $offset=8;$hasData=false;$hasEnd=false;
            while ($offset+12<=strlen($content)) {
                $length=unpack('N',substr($content,$offset,4))[1];
                if ($length>strlen($content)-$offset-12) throw new DomainException('Incomplete request-letter PNG.');
                $type=substr($content,$offset+4,4);$chunk=substr($content,$offset+4,$length+4);
                if (hash('crc32b',$chunk)!==bin2hex(substr($content,$offset+8+$length,4))) throw new DomainException('Invalid request-letter PNG.');
                $offset+=$length+12;if($type==='IDAT')$hasData=true;
                if($type==='IEND'){$hasEnd=$length===0;break;}
            }
            if(!$hasData||!$hasEnd||$offset!==strlen($content)) throw new DomainException('Invalid PNG or trailing content in request letter.');
        } elseif (!str_starts_with($content,"\xFF\xD8") || !str_ends_with($content,"\xFF\xD9")) throw new DomainException('Incomplete JPG or trailing content in request letter.');
    }
    $original = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'request-letter')));
    $original = preg_replace('/[\x00-\x1F\x7F]/', '', $original);
    return ['tmp' => $tmp, 'mime' => $mime, 'extension' => $types[$mime], 'original' => mb_substr($original, 0, 255), 'size' => $size];
}

function gsoStoreRequestLetter(array $validated): array
{
    $name = 'letter_' . bin2hex(random_bytes(16)) . '.' . $validated['extension'];
    $path = gsoRequestLetterStorageRoot(true) . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($validated['tmp'], $path)) throw new RuntimeException('Could not store request letter.');
    @chmod($path, 0640);
    return $validated + ['stored_file_name' => $name, 'path' => $path, 'stored_size' => filesize($path)];
}

function gsoRequestHasFacility(PDO $pdo, int $id): bool
{
    $s = $pdo->prepare("SELECT 1 FROM request_items WHERE request_id=? AND line_kind='Facility' LIMIT 1");
    $s->execute([$id]); return (bool) $s->fetchColumn();
}

function gsoRequestLetters(PDO $pdo, int $id): array
{
    $s = $pdo->prepare("SELECT a.*,u.full_name AS verifier_name FROM request_attachments a LEFT JOIN users u ON u.user_id=a.verified_by WHERE a.request_id=? AND a.attachment_type='Facility Request Letter' ORDER BY a.version_no DESC");
    $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC);
}

function gsoRequestLetterAllowsApproval(PDO $pdo, int $id): bool
{
    if (!gsoRequestHasFacility($pdo, $id)) return true;
    $current = gsoRequestLetters($pdo, $id)[0] ?? null;
    return $current && $current['verification_status'] === 'Verified' && is_file(gsoRequestLetterPath($current['stored_file_name']));
}

// Caller holds the request row lock until commit. New uploads append versions.
function gsoInsertRequestLetter(PDO $pdo, int $id, int $uploader, array $stored): int
{
    $s = $pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM request_attachments WHERE request_id=?');$s->execute([$id]);$version=(int)$s->fetchColumn();
    $s = $pdo->prepare('INSERT INTO request_attachments(request_id,uploaded_by,version_no,original_file_name,stored_file_name,mime_type,file_size) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$id,$uploader,$version,$stored['original'],$stored['stored_file_name'],$stored['mime'],$stored['stored_size']]);
    return (int) $pdo->lastInsertId();
}
