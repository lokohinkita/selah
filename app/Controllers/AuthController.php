<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\Auth;
use App\Services\Validation as V;
use App\Repositories\LibraryRepository;
final class AuthController {
    public function __construct(private LibraryRepository $repo){}
    public function handle(string $path):void {
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex');
        if($path==='/account'){$this->account();return;}
        $error='';$next=Auth::next(V::text($_POST+$_GET,'next',1500)?:'/lineups');
        if($_SERVER['REQUEST_METHOD']==='POST'&&!\validCsrf()){http_response_code(403);$error='Your form expired. Please try again.';}
        elseif($path==='/logout'){
            if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');return;}
            Auth::logout();\redirect('/login');
        }elseif($_SERVER['REQUEST_METHOD']==='POST'){
            $email=mb_strtolower(V::text($_POST,'email',255));$password=is_string($_POST['password']??null)?$_POST['password']:'';
            $scopes=[hash('sha256','account:'.$email),hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??''))];$since=gmdate('Y-m-d H:i:s',time()-900);$blocked=false;
            foreach($scopes as $i=>$scope){$n=(int)$this->repo->one('SELECT COUNT(*) AS n FROM auth_attempts WHERE scope_hash=:scope AND attempted_at>=:since',['scope'=>$scope,'since'=>$since])['n'];if($n>=($i===0?10:30))$blocked=true;}
            if($blocked){http_response_code(429);$error='Too many sign-in attempts. Try again in 15 minutes.';}
            else {
                $u=$this->repo->one('SELECT * FROM users WHERE email=:email',['email'=>$email]);
                $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
                $valid=password_verify(substr($password,0,73),$hash);
                if($u&&$u['active']&&strlen($password)<=72&&$valid){
                    if(password_needs_rehash($hash,PASSWORD_DEFAULT))$this->repo->execute('UPDATE users SET password_hash=:hash WHERE id=:id',['hash'=>password_hash($password,PASSWORD_DEFAULT),'id'=>$u['id']]);
                    $this->repo->execute('DELETE FROM auth_attempts WHERE scope_hash=:scope',['scope'=>$scopes[0]]);
                    Auth::login($u);\redirect($next);
                }
                foreach($scopes as $scope)$this->repo->execute('INSERT INTO auth_attempts (id,scope_hash,attempted_at) VALUES (:id,:scope,:now)',['id'=>bin2hex(random_bytes(16)),'scope'=>$scope,'now'=>gmdate('Y-m-d H:i:s')]);
                $this->repo->execute('DELETE FROM auth_attempts WHERE attempted_at<:since',['since'=>gmdate('Y-m-d H:i:s',time()-86400)]);
                http_response_code(401);$error='Email or password is incorrect, or the account is disabled.';
            }
        }
        if(Auth::user()&&$error==='')\redirect($next);
        \view('pages/login',['pageTitle'=>'Sign in','error'=>$error,'next'=>$next,'needsSetup'=>(int)$this->repo->one('SELECT COUNT(*) AS n FROM users')['n']===0]);
    }
    private function account():void {
        $user=Auth::requireUser();$message='';$success=false;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $current=is_string($_POST['current_password']??null)?$_POST['current_password']:'';$new=is_string($_POST['new_password']??null)?$_POST['new_password']:'';
            $record=$this->repo->one('SELECT * FROM users WHERE id=:id',['id'=>$user['id']]);
            if(!\validCsrf()){http_response_code(403);$message='Your form expired. Please try again.';}
            elseif(!password_verify(substr($current,0,73),$record['password_hash'])||strlen($current)>72){http_response_code(422);$message='The current password is incorrect.';}
            elseif(!Auth::passwordValid($new)||$new!==($_POST['confirm_password']??'')){http_response_code(422);$message='Use matching new passwords of 12?72 bytes.';}
            else{$this->repo->execute('UPDATE users SET password_hash=:hash,auth_version=auth_version+1 WHERE id=:id',['hash'=>password_hash($new,PASSWORD_DEFAULT),'id'=>$user['id']]);$record['auth_version']++;Auth::login($record);$success=true;$message='Password updated. Other sessions have been signed out.';}
        }
        \view('pages/account',['pageTitle'=>'My account','user'=>$user,'message'=>$message,'success'=>$success]);
    }

}
