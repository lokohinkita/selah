<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\LibraryRepository;
final class Auth {
    private static ?array $user=null;
    public static function boot(LibraryRepository $repo):void {
        self::$user=null;
        $id=$_SESSION['user_id']??null;if(!$id)return;
        $u=$repo->one('SELECT id,name,email,role,active,auth_version FROM users WHERE id=:id',['id'=>$id]);
        if(!$u||!$u['active']||(int)$u['auth_version']!==($_SESSION['auth_version']??0)||time()-($_SESSION['login_at']??0)>43200||time()-($_SESSION['last_active']??0)>7200){unset($_SESSION['user_id'],$_SESSION['auth_version']);return;}
        self::$user=$u;$_SESSION['last_active']=time();
    }
    public static function user():?array {return self::$user;}
    public static function admin():bool {return (self::$user['role']??'')==='ADMIN';}
    public static function requireUser():array {
        if(!self::$user)\redirect('/login?'.http_build_query(['next'=>self::next($_SERVER['REQUEST_URI']??'/lineups')]));
        return self::$user;
    }
    public static function next(string $path):string {
        $decoded=rawurldecode($path);
        if(strlen($path)>1500||str_contains($decoded,'\\')||preg_match('/[\x00-\x20]/',$decoded))return '/lineups';
        return preg_match('#^/(?:lineups|admin|account|chords)(?:[/?].*)?$#',$path)?$path:'/lineups';
    }

    public static function login(array $user):void {
        session_regenerate_id(true);$_SESSION=[];
        $_SESSION['user_id']=$user['id'];$_SESSION['auth_version']=(int)$user['auth_version'];$_SESSION['login_at']=$_SESSION['last_active']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));self::$user=$user;
    }
    public static function logout():void {$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));self::$user=null;}
    public static function passwordValid(string $password):bool {return strlen($password)>=12 && strlen($password)<=72;}
}
