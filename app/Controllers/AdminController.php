<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Repositories\SongEditorRepository;
use App\Services\Authorization;
use App\Services\Validation as V;
use App\Models\SongSection;
final class AdminController {
 public function __construct(private LibraryRepository $repo){}
 public function handle(string $path):void {
  header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
  if($_SERVER['REQUEST_METHOD']==='POST'&&!\validCsrf()){http_response_code(403);\view('pages/error',['pageTitle'=>'Form expired','message'=>'Return to the editor, refresh the page, and try again.']);return;}
  $user=\App\Services\Auth::requireUser();
  if(!\App\Services\Auth::admin()){http_response_code(403);\view('pages/error',['pageTitle'=>'Administrator access required','message'=>'You can edit songs within your assigned schedules. The main library is managed by administrators.']);return;}
  $_SESSION['role']='ADMIN';
  if($path==='/admin/logout'&&$_SERVER['REQUEST_METHOD']==='POST'){\App\Services\Auth::logout();\redirect('/login');}
  if(preg_match('#^/admin/blog(?:/(new|[a-zA-Z0-9-]{1,36})(/delete)?)?$#',$path,$m)){(new BlogController($this->repo))->handle($m[1]??null,isset($m[2]));return;}
  if($path==='/admin/users'){(new UserController($this->repo))->handle();return;}
  if(preg_match('#^/admin/songs/(new|[a-zA-Z0-9-]{1,36})$#',$path,$m)){$this->editor($m[1]);return;}
  if(preg_match('#^/admin/(artists)/(new|[a-zA-Z0-9-]{1,36})(/delete)?$#',$path,$m)){(new ReferenceController($this->repo))->handle($m[1],$m[2],isset($m[3]));return;}
  if($path==='/admin/submissions'){$this->submissions();return;}
  $types=['/admin/songs'=>'songs','/admin/artists'=>'artists','/admin/languages'=>'languages','/admin/blog'=>'articles'];
  if(isset($types[$path])){$type=$types[$path];$page=max(1,min(10000,(int)($_GET['page']??1)));$offset=($page-1)*30;$order=$type==='songs'?'title':($type==='articles'?'title':'name');\view('admin/list',['pageTitle'=>ucfirst($type),'type'=>$type,'rows'=>$this->repo->all('SELECT * FROM '.$type.' ORDER BY '.$order.' LIMIT 30 OFFSET '.$offset),'page'=>$page,'hasNext'=>(int)$this->repo->one('SELECT COUNT(*) AS n FROM '.$type)['n']>$offset+30]);return;}
  if($path==='/admin'){\view('admin/home',['pageTitle'=>'Library editor','counts'=>['Songs'=>(int)$this->repo->one('SELECT COUNT(*) AS n FROM songs')['n'],'Pending submissions'=>(int)$this->repo->one("SELECT COUNT(*) AS n FROM song_submissions WHERE status='pending'")['n'],'Artists'=>(int)$this->repo->one('SELECT COUNT(*) AS n FROM artists')['n']]]);return;}
  (new LibraryController($this->repo))->notFound();
 }
 private function editor(string $id):void {
  $editor=new SongEditorRepository($this->repo);$exists=$id!=='new';$song=$exists?$editor->find($id):['title'=>'','slug'=>'','original_key'=>'G','capo'=>0,'tempo'=>72,'time_signature'=>'4/4','status'=>'draft','language_id'=>'english','copyright_notice'=>'','sections'=>[]];
  if(!$song){(new LibraryController($this->repo))->notFound();return;}$errors=[];
  if($_SERVER['REQUEST_METHOD']==='POST'){
   $data=[];foreach(['title','slug','original_key','time_signature','status','artist_id','language_id','youtube_url','spotify_url','source_url','copyright_notice'] as $key)$data[$key]=V::text($_POST,$key,$key==='copyright_notice'?2000:501);
   $data['capo']=(int)($_POST['capo']??0);$data['tempo']=(int)($_POST['tempo']??0);
   if(mb_strlen($data['title'])<2||mb_strlen($data['title'])>180)$errors[]='Title must be 2–180 characters.';
   if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$data['slug'])||strlen($data['slug'])>200)$errors[]='Use a slug up to 200 characters, with lowercase letters, numbers, and hyphens.';
   if($this->repo->one('SELECT id FROM songs WHERE slug=:slug AND id<>:id',['slug'=>$data['slug'],'id'=>$id]))$errors[]='That URL slug is already in use.';
   if(!V::key($data['original_key']))$errors[]='Enter a valid original key.';
   if($data['tempo']<20||$data['tempo']>300)$errors[]='Tempo must be between 20 and 300 BPM.';
   if($data['capo']<0||$data['capo']>12)$errors[]='Capo must be 0–12.';
   if(!in_array($data['time_signature'],['4/4','3/4','6/8','12/8'],true))$errors[]='Choose a supported time signature.';
   if(!in_array($data['status'],['draft','published','archived'],true))$errors[]='Choose draft or published.';
   foreach(['youtube_url','spotify_url','copyright_notice'] as $hidden)$data[$hidden]=$song[$hidden]??'';
   foreach(['artists'=>'artist_id','languages'=>'language_id'] as $table=>$key)if(!$this->repo->one('SELECT id FROM '.$table.' WHERE id=:id',['id'=>$data[$key]]))$errors[]='Choose a valid '.str_replace('_id','',$key).'.';
   foreach(['youtube_url','spotify_url','source_url'] as $key)if(!V::safeUrl($data[$key]))$errors[]='Use valid http/https links up to 500 characters.';
   $raw=$_POST['sections']??[];$sections=[];
   if(!is_array($raw)||count($raw)<1||count($raw)>40)$errors[]='Include 1–40 sections.';
   else foreach(array_values($raw) as $i=>$v){if(!is_array($v)||array_filter($v,fn($field)=>!is_scalar($field))){$errors[]='Invalid section data.';continue;}$s=SongSection::fromInput($v,$i);$errors=array_merge($errors,$s->errors());$sections[]=$s;}
   if(is_array($raw))foreach($raw as &$sectionInput){if(is_array($sectionInput)&&!isset($sectionInput['custom_drums']))$sectionInput['drums_content']=null;}unset($sectionInput);
   $song=array_merge($song,$data,['sections'=>is_array($raw)?array_values($raw):[]]);
   if(!$errors){$saveId=$exists?$id:bin2hex(random_bytes(16));$editor->save($saveId,$data,$sections,$exists);$_SESSION['editor_saved']=true;\redirect('/admin/songs/'.$saveId);}http_response_code(422);
  }
  $saved=$_SESSION['editor_saved']??false;unset($_SESSION['editor_saved']);\view('admin/editor',['pageTitle'=>$exists?'Edit song':'New song','song'=>$song,'errors'=>array_unique($errors),'saved'=>$saved,'artists'=>$this->repo->artists(),'languages'=>$this->repo->taxonomy('languages')]);
 }
 private function submissions():void {
  if(!Authorization::can($_SESSION['role']??'','moderate')){http_response_code(403);return;}
  $error='';if($_SERVER['REQUEST_METHOD']==='POST'){
   $id=V::text($_POST,'id',36);$action=V::text($_POST,'action');
   if(in_array($action,['reviewed','rejected'],true)){$this->repo->execute("UPDATE song_submissions SET status=:status WHERE id=:id AND status='pending'",['status'=>$action,'id'=>$id]);\redirect('/admin/submissions');}
   $error='Choose a valid moderation action.';http_response_code(422);
  }
  $page=max(1,min(10000,(int)($_GET['page']??1)));$offset=($page-1)*20;
  \view('admin/submissions',['pageTitle'=>'Song submissions','error'=>$error,'rows'=>$this->repo->all('SELECT * FROM song_submissions ORDER BY created_at DESC LIMIT 20 OFFSET '.$offset),'page'=>$page,'hasNext'=>(int)$this->repo->one('SELECT COUNT(*) AS n FROM song_submissions')['n']>$offset+20]);
 }
}
