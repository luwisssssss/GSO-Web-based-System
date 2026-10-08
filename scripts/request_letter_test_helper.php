<?php
// Required fixture setup for older active suites whose subject is not documents.
require_once dirname(__DIR__) . '/includes/request_attachment_helper.php';
function gsoTestLetterDatabase(): void {
    if (!preg_match('/^gso_r(?:2[ab]|3|4)_test_[a-zA-Z0-9_]+$/',(string)gsoEnv('GSO_DB_NAME',''))) throw new RuntimeException('Request-letter fixtures require an isolated R2 test database.');
}
function gsoTestLetterImage(): string {
    gsoTestLetterDatabase();$path=tempnam(sys_get_temp_dir(),'gso_letter_');
    $chunk=static fn(string $type,string $data)=>pack('N',strlen($data)).$type.$data.pack('H*',hash('crc32b',$type.$data));
    file_put_contents($path,"\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',1,1,8,2,0,0,0)).$chunk('IDAT',gzcompress("\0\0\0\0")).$chunk('IEND',''));
    register_shutdown_function(static function()use($path){@unlink($path);});return $path;
}
function gsoTestLetterForm(PDO $pdo,int $primary,array $data): array|string {
    $ids=[$primary];foreach((array)($data['extra_lines']??[]) as $line)$ids[]=(int)$line['resource_id'];
    $s=$pdo->prepare("SELECT COUNT(*) FROM resources WHERE resource_type='Facility' AND resource_id IN (".implode(',',array_fill(0,count($ids),'?')).")");$s->execute($ids);
    if(!(int)$s->fetchColumn())return http_build_query($data);
    $data["facility_slot"]=$data["facility_slot"]??gsoFacilityPeriodForStart((string)($data["start_time"]??""));
    gsoTestLetterDatabase();$flat=[];parse_str(http_build_query($data),$normalized);
    $flatten=function(array $values,string $prefix='')use(&$flatten,&$flat){foreach($values as $k=>$v){$name=$prefix===''?(string)$k:$prefix.'['.$k.']';if(is_array($v))$flatten($v,$name);else $flat[$name]=$v;}};$flatten($normalized);
    $flat['request_letter']=new CURLFile(gsoTestLetterImage(),'image/png','signed-test-letter.png');return $flat;
}
function gsoTestVerifiedLetter(PDO $pdo,int $id,int $reviewer): void {
    gsoTestLetterDatabase();if(!gsoRequestHasFacility($pdo,$id))return;
    $current=gsoRequestLetters($pdo,$id)[0]??null;
    if(!$current){$name='letter_'.bin2hex(random_bytes(16)).'.png';$path=gsoRequestLetterStorageRoot(true).DIRECTORY_SEPARATOR.$name;copy(gsoTestLetterImage(),$path);
        $s=$pdo->prepare('SELECT borrower_id FROM resource_requests WHERE request_id=?');$s->execute([$id]);
        gsoInsertRequestLetter($pdo,$id,(int)$s->fetchColumn(),['original'=>'signed-test-letter.png','stored_file_name'=>$name,'mime'=>'image/png','stored_size'=>filesize($path)]);
    }
    $current=gsoRequestLetters($pdo,$id)[0];
    $pdo->prepare("UPDATE request_attachments SET verification_status='Verified',verified_by=?,verified_at=NOW(),verification_remarks='Explicit verified-letter fixture for shared regression' WHERE attachment_id=?")->execute([$reviewer,$current['attachment_id']]);
}
function gsoTestDeleteLetters(PDO $pdo,int $id): void {
    gsoTestLetterDatabase();foreach(gsoRequestLetters($pdo,$id) as $letter)@unlink(gsoRequestLetterPath($letter['stored_file_name']));
    $pdo->prepare('DELETE FROM request_attachments WHERE request_id=?')->execute([$id]);
}
