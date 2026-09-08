<?php
declare(strict_types=1);
namespace App\Services;
final class LineupIdentity {
 public static function owner():string {return Auth::requireUser()['id'];}
}
