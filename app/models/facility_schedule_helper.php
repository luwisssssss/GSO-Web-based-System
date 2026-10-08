<?php
require_once __DIR__ . '/request_helper.php';

function gsoFacilityOperatingHours(PDO $pdo): array
{
    $rows=$pdo->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('facility_operating_start','facility_operating_end')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $start=gsoFacilityClock($rows['facility_operating_start']??null);
    $end=gsoFacilityClock($rows['facility_operating_end']??null);
    if($start===null||$end===null||$start>=$end)throw new RuntimeException('Normal facility operating hours are not configured correctly.');
    return ['start'=>$start,'end'=>$end];
}

function gsoFacilityClock(?string $value): ?string
{
    if(!preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/',(string)$value,$m))return null;
    return $m[1].':'.$m[2].':'.($m[3]??'00');
}

function gsoFacilityPeriodForStart(?string $start): ?string
{
    $clock=gsoFacilityClock($start);
    return $clock===null?null:($clock<'12:00:00'?'AM':'PM'); // Normal clock semantics, not reservation blocks.
}

function gsoFacilityPeriodError(?string $period,?string $start,?string $end): ?string
{
    if(!in_array($period,['AM','PM'],true))return 'Select AM or PM for the facility reservation.';
    $a=gsoFacilityClock($start);$b=gsoFacilityClock($end);
    if($a===null||$b===null||$a>=$b)return 'Enter valid facility start and end times, with the end after the start.';
    if(gsoFacilityPeriodForStart($a)!==$period)return 'The selected AM/PM period must match the reservation start time.';
    return null;
}

function gsoFacilityNeedsExtendedHours(PDO $pdo,?string $start,?string $end): bool
{
    $hours=gsoFacilityOperatingHours($pdo);
    return (gsoFacilityClock($end)??'')>$hours['end'];
}

function gsoFacilitySubmissionHoursError(PDO $pdo,?string $start,?string $end,bool $requestExtension): ?string
{
    $hours=gsoFacilityOperatingHours($pdo);$a=gsoFacilityClock($start);$b=gsoFacilityClock($end);
    if($a===null||$b===null)return 'Enter valid facility reservation times.';
    if($a<$hours['start'])return 'Facility reservations must start at or after the configured campus opening time ('.formatTimeDisplay($hours['start']).').';
    if($b>$hours['end']&&!$requestExtension)return 'This schedule exceeds normal campus hours. Request an extended-hours exception for explicit GSO review.';
    return null;
}

function gsoRequestScheduleDisplay(array $row): string
{
    $times=buildTimeRangeDisplay($row['start_time']??null,$row['end_time']??null);
    if(in_array($row['facility_slot']??null,['AM','PM'],true))return $row['facility_slot'].' | '.$times;
    return ($row['resource_type']??'')==='Facility'?'Legacy schedule | '.$times:$times;
}

// All approvals lock the same resource rows before creating a consistent read
// snapshot, so the second concurrent approver sees the first committed booking.
function gsoLockRequestSchedulingResources(PDO $pdo,int $requestId): void
{
    $s=$pdo->prepare('SELECT resource_id FROM request_items WHERE request_id=? ORDER BY resource_id FOR UPDATE');$s->execute([$requestId]);
    $lock=$pdo->prepare('SELECT resource_id FROM resources WHERE resource_id=? FOR UPDATE');
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){$lock->execute([$id]);$lock->fetchColumn();}
}
