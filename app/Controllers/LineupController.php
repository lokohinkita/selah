<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Repositories\LineupRepository;
use App\Services\LineupIdentity;
use App\Services\ChordTransposer;
use App\Services\Validation as V;
final class LineupController {
    private LineupRepository $events;
    public function __construct(private LibraryRepository $library){$this->events=new LineupRepository($library);}
    public function handle(string $path):void {
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        $owner=LineupIdentity::owner();$share='';
        if($_SERVER['REQUEST_METHOD']==='POST'&&!\validCsrf()){http_response_code(403);\view('pages/error',['pageTitle'=>'Form expired','message'=>'Refresh the lineup page and try again.']);return;}
        if(preg_match('#^/lineups/([a-f0-9]{32})/songs/([a-f0-9]{32})/edit$#',$path,$match)){
            $event=$this->events->find($match[1],$owner);if(!$event){$this->missing();return;}
            (new ScheduleSongController($this->library))->edit($event,$match[2],$owner);return;
        }
        if($path==='/lineups'){
            $past=($_GET['period']??'')==='past';$page=max(1,min(10000,(int)($_GET['page']??1)));
            $add=$this->library->one("SELECT id,title,slug,original_key FROM songs WHERE id=:id AND status='published'",['id'=>V::text($_GET,'add',36)]);
            $key=$add?V::text($_GET,'key',8):'';if($add&&!ChordTransposer::validTarget($add['original_key'],$key))$key=$add['original_key'];
            \view('pages/lineups',['pageTitle'=>'Event lineups','result'=>$this->events->listing($owner,$past,$page),'past'=>$past,'add'=>$add,'addKey'=>$key]);return;
        }
        if($path==='/lineups/new'){$this->edit(null,$owner);return;}
        if(!preg_match('#^/lineups/([a-f0-9]{32})(?:/(edit|members|add|delete))?$#',$path,$m)){$this->missing();return;}
        $event=$this->events->find($m[1],$owner,$share);if(!$event){$this->missing();return;}$action=$m[2]??'';
        if($action && !$event['is_owner']){$this->missing();return;}
        if($action==='delete'){
            if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');return;}
            if($_SERVER['REQUEST_METHOD']==='POST'){
                if(($_POST['confirm']??'')!=='delete'){http_response_code(422);\view('pages/lineup-delete',['pageTitle'=>'Delete lineup','event'=>$event,'error'=>'Confirm that you want to permanently delete this lineup.']);return;}
                try{$this->events->delete($event['id'],$owner,(int)($_POST['version']??0));}
                catch(\App\Services\LineupConflict $e){http_response_code(409);\view('pages/error',['pageTitle'=>'Lineup not deleted','message'=>$e->getMessage()]);return;}
                \redirect('/lineups');
            }
            \view('pages/lineup-delete',['pageTitle'=>'Delete lineup','event'=>$event]);return;
        }
        if($action==='edit'){$this->edit($event,$owner);return;}
        if(in_array($action,['members','add'],true)){
            if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');return;}
            if($action==='members'){
                try{$this->events->assign($event['id'],$owner,V::text($_POST,'email',254),($_POST['action']??'')==='remove',($_POST['can_edit']??'0')==='1');}
                catch(\App\Services\LineupConflict $e){http_response_code(422);\view('pages/error',['pageTitle'=>'Assignment not saved','message'=>$e->getMessage()]);return;}
                \redirect('/lineups/'.$event['id']);
            }
            $song=$this->library->one("SELECT id,original_key FROM songs WHERE id=:id AND status='published'",['id'=>V::text($_POST,'song_id',36)]);
            $key=V::text($_POST,'key',8);
            if(!$song||!ChordTransposer::validTarget($song['original_key'],$key)||count($event['songs'])>=40){http_response_code(422);\view('pages/error',['pageTitle'=>'Could not add this song','message'=>'Choose an available song and valid key. A lineup holds up to 40 songs.']);return;}
            $songs=array_map(fn($s)=>['entry_id'=>$s['id'],'song_id'=>$s['song_id'],'performance_key'=>$s['performance_key'],'notes'=>$s['notes']],$event['songs']);$songs[]=['song_id'=>$song['id'],'performance_key'=>$key,'notes'=>''];
            try{$this->events->save($event,$owner,array_intersect_key($event,array_flip(['title','starts_at','timezone','location','notes'])),$songs,(int)($_POST['version']??0));}catch(\App\Services\LineupConflict $e){http_response_code(409);\view('pages/error',['pageTitle'=>'Lineup changed','message'=>$e->getMessage()]);return;}
            \redirect('/lineups/'.$event['id']);
        }
        if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');return;}
        \view('pages/lineup',['pageTitle'=>$event['title'],'event'=>$event,'share'=>$event['is_owner']?'':$share]);
    }
    private function missing():void {(new LibraryController($this->library))->notFound();}
    private function edit(?array $event,string $owner):void {
        $values=$event??['title'=>'','timezone'=>'UTC','location'=>'','notes'=>'','version'=>0,'songs'=>[]];
        $values['local_time']=$event?(new \DateTimeImmutable($event['starts_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($event['timezone']))->format('Y-m-d\TH:i'):'';
        if(!$event&&isset($_GET['add'])){
            $s=$this->library->one("SELECT s.id AS song_id,s.title,s.original_key,a.name AS artist FROM songs s JOIN song_artists sa ON sa.song_id=s.id AND sa.role='primary' JOIN artists a ON a.id=sa.artist_id WHERE s.id=:id AND s.status='published'",['id'=>V::text($_GET,'add',36)]);
            if($s){$key=V::text($_GET,'key',8);$values['songs'][]=$s+['performance_key'=>ChordTransposer::validTarget($s['original_key'],$key)?$key:$s['original_key'],'notes'=>''];}
        }
        $errors=[];
        if($_SERVER['REQUEST_METHOD']==='POST'){
            foreach(['title'=>181,'location'=>181,'notes'=>3001,'timezone'=>81,'local_time'=>30] as $k=>$max)$values[$k]=V::text($_POST,$k,$max);
            $values['version']=(int)($_POST['version']??0);
            if(mb_strlen($values['title'])<2||mb_strlen($values['title'])>180)$errors[]='Use an event title between 2 and 180 characters.';
            if(mb_strlen($values['location'])>180||mb_strlen($values['notes'])>3000)$errors[]='Keep the location under 180 characters and notes under 3,000.';
            $time=null;
            if(!in_array($values['timezone'],\DateTimeZone::listIdentifiers(),true))$errors[]='Choose a valid time zone.';
            else{$time=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$values['local_time'],new \DateTimeZone($values['timezone']));if(!$time||$time->format('Y-m-d\TH:i')!==$values['local_time']||(int)$time->format('Y')<2000||(int)$time->format('Y')>2100)$errors[]='Enter a valid event date and time (2000–2100).';}
            $raw=$_POST['songs']??[];$values['songs']=[];
            if(!is_array($raw)||count($raw)>40)$errors[]='A lineup can contain up to 40 songs.';
            else foreach(array_values($raw) as $row){
                if(!is_array($row)){$errors[]='Invalid song entry.';continue;}
                $entryId=V::text($row,'entry_id',36);$s=null;
                if($entryId){foreach(($event['songs']??[]) as $existing)if($existing['id']===$entryId&&$existing['song_id']===V::text($row,'song_id',36))$s=$existing;}
                else {
                $s=$this->library->one("SELECT s.id AS song_id,s.title,s.original_key,a.name AS artist FROM songs s JOIN song_artists sa ON sa.song_id=s.id AND sa.role='primary' JOIN artists a ON a.id=sa.artist_id WHERE s.id=:id AND s.status='published'",['id'=>V::text($row,'song_id',36)]);
                }
                if(!$s){$errors[]='One of these songs is no longer published. Remove it and try again.';continue;}
                $key=V::text($row,'performance_key',8);$notes=V::text($row,'notes',1001);
                if(!ChordTransposer::validTarget($s['original_key'],$key))$errors[]='Choose a valid performance key for '.$s['title'].'.';
                if(mb_strlen($notes)>1000)$errors[]='Song notes must be 1,000 characters or fewer.';
                $values['songs'][]=array_merge($s,['entry_id'=>$entryId,'performance_key'=>$key,'notes'=>$notes]);
            }
            if(!$errors){
                $data=['title'=>$values['title'],'location'=>$values['location'],'notes'=>$values['notes'],'timezone'=>$values['timezone'],'starts_at'=>$time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
                $songs=array_map(fn($s)=>array_intersect_key($s,array_flip(['entry_id','song_id','performance_key','notes'])),$values['songs']);
                try{$id=$this->events->save($event,$owner,$data,$songs,$values['version']);\redirect('/lineups/'.$id);}catch(\App\Services\LineupConflict $e){$errors[]=$e->getMessage();http_response_code(409);}
            }else http_response_code(422);
        }
        \view('pages/lineup-edit',['pageTitle'=>$event?'Edit lineup':'New event lineup','values'=>$values,'errors'=>array_unique($errors),'event'=>$event,'timezones'=>\DateTimeZone::listIdentifiers()]);
    }
}
