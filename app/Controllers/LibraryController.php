<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
final class LibraryController {
 public function __construct(private LibraryRepository $repo) {}
 public function home():void { \view('pages/home',['songs'=>$this->repo->songs([],1,6,'newest')['items'],'artists'=>array_slice($this->repo->artists(),0,4),'articles'=>$this->repo->all('SELECT * FROM articles ORDER BY published_at DESC LIMIT 3')]); }
 public function directory(string $type='chords',?string $slug=null):void {
  $f=[];foreach(['q','letter','key','language'] as $k)if(isset($_GET[$k])&&is_string($_GET[$k]))$f[$k]=mb_substr(trim($_GET[$k]),0,120);
  $title=$type==='search'?'Find your next song':'The chord library';$intro='Songs for the moments that bring us together.';$profile=null;
  if($type==='artists'){$profile=$this->repo->one('SELECT * FROM artists WHERE slug=:slug',['slug'=>$slug]);$f['artist']=$slug;}
  if(in_array($type,['languages'],true)){$profile=$this->repo->one('SELECT * FROM '.$type.' WHERE slug=:slug',['slug'=>$slug]);$f['language']=$slug;}
  if($slug && !$profile){$this->notFound();return;}
  if($profile){$title=$profile['name'];$intro=$profile['bio']??$profile['description']??'Explore chord sheets in '.$profile['name'].'.';}
  $page=max(1,min(10000,(int)($_GET['page']??1)));$sort=($_GET['sort']??'')==='newest'?'newest':'title';
  \view('pages/directory',['pageTitle'=>$title,'heading'=>$title,'intro'=>$intro,'result'=>$this->repo->songs($f,$page,12,$sort),'filters'=>$f,'profile'=>$profile,'languages'=>$this->repo->taxonomy('languages')]);
 }
 public function song(string $slug):void {
  $lineup=null;$entry=null;$share='';
  if(isset($_GET['lineup'])){
   $user=\App\Services\Auth::requireUser();header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex');
   $lineup=(new \App\Repositories\LineupRepository($this->repo))->find(\App\Services\Validation::text($_GET,'lineup',36),$user['id']);
   if(!$lineup){$this->notFound();return;}
   foreach($lineup['songs'] as $i=>$item)if($item['id']===\App\Services\Validation::text($_GET,'entry',36)&&$item['slug']===$slug)$entry=$i;
   if($entry===null){$this->notFound();return;}
   $song=(new \App\Repositories\ScheduleSongRepository($this->repo))->get($lineup['songs'][$entry]['id']);
  }else{$song=$this->repo->song($slug);if(!$song){$this->notFound();return;}}
  $target=\App\Services\Validation::text($_GET,'key',8)?:($lineup?$lineup['songs'][$entry]['performance_key']:$song['original_key']);
  if(!\App\Services\ChordTransposer::validTarget($song['original_key'],$target)){http_response_code(422);\view('pages/error',['pageTitle'=>'Choose a valid key','message'=>'Transposition preserves the major or minor mode.']);return;}
  $viewMode=\App\Services\Validation::text($_GET,'view',16)?:'aligned';
  if(!isset(\App\Services\SectionView::MODES[$viewMode]))$viewMode='aligned';
  $song['display_key']=$target;
  foreach($song['sections'] as &$section){
   $content=\App\Services\SectionView::content($section,$viewMode);
   $section['viewer_content']=in_array($viewMode,['lyrics','drums'],true)?$content:\App\Services\ChordTransposer::chart($content,$song['original_key'],$target);
  }unset($section);

  if(!$lineup)$this->repo->trackView($song['id']);
  \view('pages/song',['pageTitle'=>$song['title'].' - Chords in '.$target,'description'=>'Play '.$song['title'].' by '.$song['artist'].'. Key '.$target.', '.$song['tempo'].' BPM.','song'=>$song,'viewMode'=>$viewMode,'lineup'=>$lineup,'entry'=>$entry,'share'=>$share,'related'=>$lineup?[]:array_values(array_filter($this->repo->songs(['artist'=>$song['artist_slug']],1,5)['items'],fn($s)=>$s['id']!==$song['id']))]);
 }

 public function artists():void {\view('pages/artists',['pageTitle'=>'Artists','artists'=>$this->repo->artists()]);}
 public function trending():void {$period=in_array($_GET['period']??'',['7','30','365'],true)?(int)$_GET['period']:7;\view('pages/trending',['pageTitle'=>'Trending chords','period'=>$period,'songs'=>$this->repo->trending($period)]);}
 public function favorites():void {\view('pages/favorites',['pageTitle'=>'Your favorites']);}
 public function favoriteData():void {$raw=$_GET['ids']??'';$ids=is_string($raw)?array_values(array_filter(explode(',',$raw),fn($v)=>preg_match('/^[a-zA-Z0-9-]{1,36}$/',$v))):[];$page=max(1,min(20,(int)($_GET['page']??1)));$result=$this->repo->songs(['ids'=>array_slice($ids,0,200)],$page,50);header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_THROW_ON_ERROR);}
 public function notes(?string $slug=null):void {if($slug){$article=$this->repo->one('SELECT * FROM articles WHERE slug=:slug',['slug'=>$slug]);if(!$article){$this->notFound();return;}\view('pages/article',['pageTitle'=>$article['title'],'description'=>$article['excerpt'],'article'=>$article]);}else \view('pages/notes',['pageTitle'=>'Blog','articles'=>$this->repo->all('SELECT * FROM articles ORDER BY published_at DESC LIMIT 30')]);}
 public function about():void {\view('pages/about',['pageTitle'=>'About Selah']);}
 public function notFound():void {http_response_code(404);\view('pages/error',['pageTitle'=>'Page not found','message'=>'We couldnâ€™t find that page. There are more songs waiting in the library.']);}
}
