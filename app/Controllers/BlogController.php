<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Services\Validation as V;
final class BlogController {
 public function __construct(private LibraryRepository $repo){}
 public function handle(?string $id,bool $delete):void {
  if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');return;}
  if($id===null){\view('admin/blog-list',['pageTitle'=>'Blog','rows'=>$this->repo->all('SELECT * FROM articles ORDER BY published_at DESC')]);return;}
  $exists=$id!=='new';$row=$exists?$this->repo->one('SELECT * FROM articles WHERE id=:id',['id'=>$id]):['title'=>'','slug'=>'','excerpt'=>'','content'=>''];
  if(!$row||($delete&&!$exists)){(new LibraryController($this->repo))->notFound();return;}$errors=[];
  if($_SERVER['REQUEST_METHOD']==='POST'){
   if($delete){if(($_POST['confirm']??'')!=='delete')$errors[]='Confirm permanent deletion.';else{$this->repo->execute('DELETE FROM articles WHERE id=:id',['id'=>$id]);\redirect('/admin/blog');}}
   else{
    foreach(['title'=>180,'slug'=>200,'excerpt'=>2000,'content'=>50000] as $field=>$max){$row[$field]=V::text($_POST,$field,$max+1);if($row[$field]===''||mb_strlen($row[$field])>$max)$errors[]='Enter '.$field.' between 1 and '.$max.' characters.';}
    if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$row['slug']))$errors[]='Use lowercase letters, numbers and hyphens for the slug.';
    if($this->repo->one('SELECT id FROM articles WHERE slug=:slug AND id<>:id',['slug'=>$row['slug'],'id'=>$id]))$errors[]='That slug is already in use.';
    if(!$errors){$data=array_intersect_key($row,array_flip(['title','slug','excerpt','content']));$data+=['id'=>$exists?$id:bin2hex(random_bytes(16)),'category'=>'Blog','reading_minutes'=>max(1,(int)ceil(str_word_count($row['content'])/200)),'published_at'=>$row['published_at']??gmdate('Y-m-d H:i:s')];
     $sql=$exists?'UPDATE articles SET title=:title,slug=:slug,excerpt=:excerpt,content=:content,category=:category,reading_minutes=:reading_minutes,published_at=:published_at WHERE id=:id':'INSERT INTO articles ('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')';
     try{$this->repo->execute($sql,$data);\redirect('/admin/blog');}catch(\PDOException $e){if(!str_starts_with((string)$e->getCode(),'23'))throw $e;$errors[]='That slug is already in use.';}
    }
   }http_response_code(422);
  }
  \view('admin/blog-editor',['pageTitle'=>$delete?'Delete blog post':($exists?'Edit blog post':'Add blog post'),'row'=>$row,'errors'=>$errors,'delete'=>$delete]);
 }
}
