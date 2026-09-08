<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Services\LineupConflict;
final class ScheduleSongRepository {
    public function __construct(private LibraryRepository $repo){}
    public function snapshot(string $entry,string $songId):void {
        $source=$this->repo->one('SELECT slug FROM songs WHERE id=:id',['id'=>$songId]);
        $song=$source?$this->repo->song($source['slug'],true):null;
        if(!$song)throw new \RuntimeException('Song could not be copied.');
        $this->repo->execute('INSERT INTO lineup_song_copies (entry_id,content_json,version,updated_at,updated_by) VALUES (:entry,:json,1,:now,NULL)',['entry'=>$entry,'json'=>json_encode($song,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'now'=>gmdate('Y-m-d H:i:s')]);
    }
    public function get(string $entry):?array {
        $copy=$this->repo->one('SELECT * FROM lineup_song_copies WHERE entry_id=:entry',['entry'=>$entry]);
        if(!$copy)return null;$song=json_decode($copy['content_json'],true,512,JSON_THROW_ON_ERROR);$song['copy_version']=(int)$copy['version'];return $song;
    }
    public function save(string $eventId,string $entry,string $user,int $version,array $song,string $key):void {
        if(empty((new LineupRepository($this->repo))->find($eventId,$user)['can_edit']))throw new LineupConflict('Schedule access is no longer available.');
        $db=$this->repo->db;$db->beginTransaction();try{
            // Claim the event revision first: lineup removal/reordering and copy edits serialize.
            $this->repo->execute('UPDATE event_lineups SET version=version+1,updated_at=:now WHERE id=:id',['now'=>gmdate('Y-m-d H:i:s'),'id'=>$eventId]);
            $q=$db->prepare('UPDATE lineup_song_copies SET content_json=:json,version=version+1,updated_at=:now,updated_by=:user WHERE entry_id=:entry AND version=:version AND EXISTS (SELECT 1 FROM event_lineup_songs es WHERE es.id=:entry2 AND es.lineup_id=:event)');
            unset($song['copy_version']);$q->execute(['json'=>json_encode($song,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'now'=>gmdate('Y-m-d H:i:s'),'user'=>$user,'entry'=>$entry,'version'=>$version,'entry2'=>$entry,'event'=>$eventId]);
            if($q->rowCount()!==1)throw new LineupConflict('This arrangement changed or was removed. Reload before saving.');
            $this->repo->execute('UPDATE event_lineup_songs SET performance_key=:key WHERE id=:id AND lineup_id=:event',['key'=>$key,'id'=>$entry,'event'=>$eventId]);
            $db->commit();
        }catch(\Throwable $e){$db->rollBack();throw $e;}
    }
}
