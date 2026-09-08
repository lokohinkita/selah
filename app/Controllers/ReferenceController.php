<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Services\Validation as V;
final class ReferenceController {
 public function __construct(private LibraryRepository $repo){}
 public function handle(string $type,string $id,bool $delete):void {
  if(!in_array($type,['artists'],true))throw new \InvalidArgumentException('Invalid directory.');
  if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');return;}
  $artist=$type==='artists';$label=$artist?'artist':'category';$exists=$id!=='new';
  $row=$exists?$this->repo->one('SELECT * FROM '.$type.' WHERE id=:id',['id'=>$id]):['name'=>'','slug'=>'','bio'=>'','description'=>'','color'=>'#2563EB'];
  if(!$row||($delete&&!$exists)){(new LibraryController($this->repo))->notFound();return;}
  $errors=[];$used=0;
  if($exists){$table=$artist?'song_artists':'song_categories';$column=$artist?'artist_id':'category_id';$used=(int)$this->repo->one('SELECT COUNT(*) AS n FROM '.$table.' WHERE '.$column.'=:id',['id'=>$id])['n'];if($artist)$used+=(int)$this->repo->one('SELECT COUNT(*) AS n FROM albums WHERE artist_id=:id',['id'=>$id])['n'];}
  if($_SERVER['REQUEST_METHOD']==='POST'){
   if($delete){
    if(($_POST['confirm']??'')!=='delete')$errors[]='Confirm deletion to continue.';
    if($used)$errors[]='This '.$label.' is used by songs or albums. Reassign those records before deleting it.';
    if(!$errors){try{$this->repo->execute('DELETE FROM '.$type.' WHERE id=:id',['id'=>$id]);\redirect('/admin/'.$type);}catch(\PDOException $e){if(!str_starts_with((string)$e->getCode(),'23'))throw $e;$errors[]='This record is now in use. Reload and reassign its songs or albums before deleting.';}}
   }else{
    $nameMax=$artist?160:80;$slugMax=$artist?180:90;$body=$artist?'bio':'description';
    foreach(['name'=>$nameMax+1,'slug'=>$slugMax+1,$body=>10001] as $key=>$limit)$row[$key]=V::text($_POST,$key,$limit);
    if(mb_strlen($row['name'])<1||mb_strlen($row['name'])>$nameMax)$errors[]='Enter a name up to '.$nameMax.' characters.';
    if(strlen($row['slug'])>$slugMax||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$row['slug']))$errors[]='Use a URL slug up to '.$slugMax.' characters with lowercase letters, numbers and hyphens.';
    if(mb_strlen($row[$body])>10000)$errors[]='Keep the description under 10,001 characters.';
    if($this->repo->one('SELECT id FROM '.$type.' WHERE slug=:slug AND id<>:id',['slug'=>$row['slug'],'id'=>$id]))$errors[]='This URL slug is already in use.';
    if($artist){$row['color']=V::text($_POST,'color',21);if(!preg_match('/^#[0-9a-fA-F]{6}$/',$row['color']))$errors[]='Choose a six-digit hex color.';}
    if(!$errors){
     $fields=$artist?['name','slug','bio','color']:['name','slug','description'];$data=array_intersect_key($row,array_flip($fields));$saveId=$exists?$id:bin2hex(random_bytes(16));$data['id']=$saveId;
     $sql=$exists?'UPDATE '.$type.' SET '.implode(',',array_map(fn($f)=>$f.'=:'.$f,$fields)).' WHERE id=:id':'INSERT INTO '.$type.' ('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')';
     try{$this->repo->execute($sql,$data);\redirect('/admin/'.$type);}catch(\PDOException $e){if(!str_starts_with((string)$e->getCode(),'23'))throw $e;$errors[]='This URL slug is already in use. Choose another.';}
    }
   }
   http_response_code($delete&&$used?409:422);
  }
  \view('admin/reference-editor',['pageTitle'=>($delete?'Delete ':($exists?'Edit ':'Add ')).$label,'type'=>$type,'label'=>$label,'row'=>$row,'exists'=>$exists,'delete'=>$delete,'used'=>$used,'errors'=>$errors]);
 }
}
