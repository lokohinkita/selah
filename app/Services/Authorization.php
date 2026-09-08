<?php
declare(strict_types=1);
namespace App\Services;
final class Authorization {
 public const ADMIN='ADMIN';public const EDITOR='EDITOR';public const CONTRIBUTOR='CONTRIBUTOR';
 public static function can(string $role,string $permission):bool {$permissions=[self::ADMIN=>['edit_songs','moderate','manage_library'],self::EDITOR=>['edit_songs','moderate'],self::CONTRIBUTOR=>['submit']];return in_array($permission,$permissions[$role]??[],true);}
 // Compatibility boundary; administrator privileges always come from an active account.
 public static function authenticated(string $unused=''):bool {return Auth::admin();}
}
