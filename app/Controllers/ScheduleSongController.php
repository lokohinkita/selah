<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\LibraryRepository;
use App\Repositories\ScheduleSongRepository;
use App\Services\Validation as V;
use App\Services\ChordTransposer;
use App\Models\SongSection;
final class ScheduleSongController {
    public function __construct(private LibraryRepository $repo){}
    public function edit(array $event,string $entry,string $user):void {
        if(empty($event['can_edit'])){http_response_code(403);\view('pages/error',['pageTitle'=>'View-only access','message'=>'The organizer has given you view-only access to this lineup.']);return;}
        $item=null;foreach($event['songs'] as $s)if($s['id']===$entry)$item=$s;
        if(!$item){(new LibraryController($this->repo))->notFound();return;}
        $copies=new ScheduleSongRepository($this->repo);$song=$copies->get($entry);$errors=[];$key=$item['performance_key'];
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $song['title']=V::text($_POST,'title',181);$song['tempo']=(int)($_POST['tempo']??0);$song['capo']=(int)($_POST['capo']??0);$song['time_signature']=V::text($_POST,'time_signature',8);$key=V::text($_POST,'performance_key',8);$version=(int)($_POST['copy_version']??0);
            if(mb_strlen($song['title'])<2||mb_strlen($song['title'])>180)$errors[]='Use a title between 2 and 180 characters.';
            if($song['tempo']<20||$song['tempo']>300||$song['capo']<0||$song['capo']>12)$errors[]='Tempo must be 20–300 BPM and capo 0–12.';
            if(!in_array($song['time_signature'],['4/4','3/4','6/8','12/8'],true))$errors[]='Choose a valid time signature.';
            if(!ChordTransposer::validTarget($song['original_key'],$key))$errors[]='Choose a valid performance key.';
            $raw=$_POST['sections']??[];$sections=[];
            if(!is_array($raw)||count($raw)<1||count($raw)>40)$errors[]='Include 1–40 sections.';
            else foreach(array_values($raw) as $i=>$row){
                if(!is_array($row)||array_filter($row,fn($v)=>!is_scalar($v))){$errors[]='Invalid section fields.';continue;}
                $model=SongSection::fromInput($row,$i);$errors=array_merge($errors,$model->errors());preg_match_all('/\[[^\]\r\n]{1,118}\]/u',$model->cues,$tokens);
                $sections[]=['id'=>$entry.'-s'.$i,'name'=>$model->name,'section_type'=>$model->type,'section_order'=>$i,'aligned_content'=>$model->alignedContent,'chord_content'=>$model->chords,'lyric_content'=>$model->lyrics,'drums_content'=>$model->drums,'dynamics'=>$model->dynamics,'repeat_count'=>$model->repeat,'rhythm_pattern'=>$model->rhythm,'musician_notes'=>$model->notes,'performance_notes'=>'','cues'=>array_map(fn($t)=>['token'=>$t],$tokens[0])];
            }
            $song['sections']=$sections;$song['copy_version']=$version;
            if(!$errors){try{
                $copies->save($event['id'],$entry,$user,$version,$song,$key);
                \redirect('/lineups/'.$event['id']);
            }catch(\App\Services\LineupConflict $e){$errors[]=$e->getMessage();http_response_code(409);}}else http_response_code(422);
        }
        // Shared editor partial expects cue tokens as an editable string.
        foreach($song['sections'] as &$section)$section['cues']=implode(' ',array_column($section['cues'],'token'));
        \view('pages/schedule-song-edit',['pageTitle'=>'Edit schedule arrangement','event'=>$event,'item'=>$item,'song'=>$song,'key'=>$key,'errors'=>array_unique($errors)]);
    }
}
