<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Models\SongSection;
final class SongEditorRepository {
 public function __construct(private LibraryRepository $repo){}
 public function find(string $id):?array {$s=$this->repo->one('SELECT * FROM songs WHERE id=:id',['id'=>$id]);if(!$s)return null;$s['artist_id']=$this->repo->one("SELECT artist_id FROM song_artists WHERE song_id=:id AND role='primary'",['id'=>$id])['artist_id']??'';$s['sections']=$this->repo->all('SELECT * FROM song_sections WHERE song_id=:id ORDER BY section_order',['id'=>$id]);foreach($s['sections'] as &$sec)$sec['cues']=implode(' ',array_column($this->repo->all('SELECT token FROM song_performance_cues WHERE section_id=:id ORDER BY cue_order',['id'=>$sec['id']]),'token'));return $s;}
 public function save(string $id,array $data,array $sections,bool $exists):void {
  $db=$this->repo->db;$db->beginTransaction();try{
   $now=gmdate('Y-m-d H:i:s');$row=array_intersect_key($data,array_flip(['title','slug','language_id','original_key','capo','tempo','time_signature','status','youtube_url','spotify_url','source_url','copyright_notice']));$row['display_key']=$row['original_key'];$row['lyrics']=implode("\n\n",array_map(fn(SongSection $s)=>$s->lyrics,$sections));$row['chord_sheet']=implode("\n\n",array_map(fn(SongSection $s)=>$s->name."\n".$s->alignedContent,$sections));$row['updated_at']=$now;
   if($exists){if($data['status']==='published')$this->repo->execute('UPDATE songs SET published_at=COALESCE(published_at,:date) WHERE id=:id',['date'=>$now,'id'=>$id]);$set=implode(',',array_map(fn($k)=>$k.'=:'.$k,array_keys($row)));$this->repo->execute('UPDATE songs SET '.$set.' WHERE id=:id',$row+['id'=>$id]);$this->repo->execute("DELETE FROM song_artists WHERE song_id=:id AND role='primary'",['id'=>$id]);$this->repo->execute('DELETE FROM song_sections WHERE song_id=:id',['id'=>$id]);}
   else{$row+=['id'=>$id,'album_id'=>null,'release_year'=>(int)date('Y'),'created_at'=>$now,'published_at'=>$data['status']==='published'?$now:null];$this->insert('songs',$row);}
   $this->insert('song_artists',['song_id'=>$id,'artist_id'=>$data['artist_id'],'role'=>'primary']);
   foreach($sections as $s){$sectionId=bin2hex(random_bytes(16));$this->insert('song_sections',['id'=>$sectionId,'song_id'=>$id,'name'=>$s->name,'section_type'=>$s->type,'section_order'=>$s->order,'chord_content'=>$s->chords,'lyric_content'=>$s->lyrics,'drums_content'=>$s->drums,'aligned_content'=>$s->alignedContent,'performance_notes'=>'','rhythm_pattern'=>$s->rhythm,'dynamics'=>$s->dynamics,'repeat_count'=>$s->repeat,'musician_notes'=>$s->notes]);preg_match_all('/\[([^\]\r\n]{1,118})\]/u',$s->cues,$matches);foreach($matches[0] as $i=>$token)$this->insert('song_performance_cues',['id'=>bin2hex(random_bytes(16)),'section_id'=>$sectionId,'cue_order'=>$i,'token'=>$token,'beat_position'=>null]);}
   $db->commit();
  }catch(\Throwable $e){$db->rollBack();throw $e;}
 }
 private function insert(string $table,array $row):void {$cols=array_keys($row);$this->repo->execute('INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES (:'.implode(',:',$cols).')',$row);}
}
