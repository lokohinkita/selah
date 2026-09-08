<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Services\Auth;
use App\Services\Validation as V;
final class UserController {
 public function __construct(private LibraryRepository $repo){}
 public function handle():void {
  if(!Auth::admin()){http_response_code(403);return;}$error='';
  if($_SERVER['REQUEST_METHOD']==='POST'){
   if(!\validCsrf()){http_response_code(403);return;}
   $action=V::text($_POST,'action',30);$password=is_string($_POST['password']??null)?$_POST['password']:'';
   if($action==='create'){
    $name=V::text($_POST,'name',161);$email=mb_strtolower(V::text($_POST,'email',255));$role=V::text($_POST,'role',20);
    if(mb_strlen($name)<2||mb_strlen($name)>160||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['USER','ADMIN'],true)||!Auth::passwordValid($password))$error='Enter a name, valid email, role, and a password of 12–72 bytes.';
    elseif($this->repo->one('SELECT id FROM users WHERE email=:email',['email'=>$email]))$error='That email already has an account.';
    else{$this->repo->execute('INSERT INTO users (id,name,email,password_hash,role,active,auth_version,created_at) VALUES (:id,:name,:email,:hash,:role,1,1,:now)',['id'=>bin2hex(random_bytes(16)),'name'=>$name,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'now'=>gmdate('Y-m-d H:i:s')]);\redirect('/admin/users');}
   }else {
    $u=$this->repo->one('SELECT id,role,active FROM users WHERE id=:id',['id'=>V::text($_POST,'id',36)]);
    if(!$u)$error='Account not found.';
    elseif($action==='reset'){
     if(!Auth::passwordValid($password))$error='Use a password of 12–72 bytes.';
     else{$this->repo->execute('UPDATE users SET password_hash=:hash,auth_version=auth_version+1 WHERE id=:id',['hash'=>password_hash($password,PASSWORD_DEFAULT),'id'=>$u['id']]);\redirect('/admin/users');}
    }elseif($action==='toggle'){
     if($u['role']==='ADMIN')$error='Administrator accounts cannot be disabled here.';
     else{$this->repo->execute('UPDATE users SET active=:active,auth_version=auth_version+1 WHERE id=:id',['active'=>$u['active']?0:1,'id'=>$u['id']]);\redirect('/admin/users');}
    }else $error='Choose a valid account action.';
   }
   if($error)http_response_code(422);
  }
  $page=max(1,min(10000,(int)($_GET['page']??1)));$offset=($page-1)*20;
  \view('admin/users',['pageTitle'=>'User accounts','error'=>$error,'users'=>$this->repo->all('SELECT id,name,email,role,active FROM users ORDER BY name LIMIT 20 OFFSET '.$offset),'page'=>$page,'hasNext'=>(int)$this->repo->one('SELECT COUNT(*) AS n FROM users')['n']>$offset+20]);
 }
}
