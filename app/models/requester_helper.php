<?php
/** Trusted internal aliases only; never pass query parameters to this SQL builder. */
function gsoRequesterSql(string $field,string $requestAlias='rr',string $userAlias='borrower'): string
{
    $snapshot=match($field){'full_name'=>'walkin_full_name','email'=>'walkin_email','department'=>'walkin_organization',default=>null};
    $external=$snapshot ? "$requestAlias.$snapshot" : "'Walk-in / External Client'";
    if($field==='full_name')$external="CONCAT($external,' (Walk-in)')";
    return "CASE WHEN $requestAlias.requester_type='Walk-in' THEN $external ELSE $userAlias.$field END";
}
function gsoIsWalkin(array $request): bool { return ($request['requester_type']??'Registered')==='Walk-in'; }
function gsoWalkinActor(PDO $pdo,int $actor,string $permission='walk_in.create'): void
{
    $s=$pdo->prepare("SELECT role FROM users WHERE user_id=? AND account_status='Approved' AND email_verified=1");$s->execute([$actor]);$role=$s->fetchColumn();
    if(!is_string($role)||!gsoUserHasPermission($role,$permission))throw new DomainException('Not authorized for this walk-in action.');
}
