<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Services\Validation;
final class SubmissionController {
 public function __construct(private LibraryRepository $repo){}
 public function handle():void {
  $errors=[];$values=[];
  if($_SERVER['REQUEST_METHOD']==='POST'){
   $values=$_POST;$errors=Validation::submission($_POST);
   if(!\validCsrf()){$errors['form']='Your form has expired. Please review it and submit again.';http_response_code(403);}
   if(time()-($_SESSION['last_submission']??0)<60)$errors['form']='Please wait a minute before sending another song.';
   $language=Validation::text($_POST,'language',80);if(!$this->repo->one('SELECT id FROM languages WHERE id=:id',['id'=>$language]))$errors['language']='Choose a language from the list.';
   if(!$errors){$this->repo->execute('INSERT INTO song_submissions (id,title,artist,email,song_key,language,content,source_url,rights_confirmed,status,created_at) VALUES (:id,:title,:artist,:email,:song_key,:language,:content,:source_url,1,\'pending\',:created_at)',['id'=>bin2hex(random_bytes(16)),'title'=>Validation::text($_POST,'title',180),'artist'=>Validation::text($_POST,'artist',160),'email'=>Validation::text($_POST,'email',254),'song_key'=>Validation::text($_POST,'song_key',8),'language'=>$language,'content'=>Validation::text($_POST,'content',50000),'source_url'=>Validation::text($_POST,'source_url',500),'created_at'=>gmdate('Y-m-d H:i:s')]);$_SESSION['last_submission']=time();$_SESSION['submission_success']=true;\redirect('/submit-song');}
   elseif(http_response_code()!==403)http_response_code(422);
  }
  $success=$_SESSION['submission_success']??false;unset($_SESSION['submission_success']);
  \view('pages/submit',['pageTitle'=>'Submit a song','errors'=>$errors,'values'=>$values,'success'=>$success,'languages'=>$this->repo->taxonomy('languages')]);
 }
}
