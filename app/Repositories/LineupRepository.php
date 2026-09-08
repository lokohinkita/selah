<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Services\LineupConflict;
final class LineupRepository {
 public function __construct(private LibraryRepository $library) {}
 private function access(string $user):array {
  $account=$this->library->one("SELECT role,active FROM users WHERE id=:id",['id'=>$user]);
  if(!$account||!$account['active'])return ['1=0',[]];
  return $account['role']==='ADMIN'?['1=1',[]]:['(EXISTS (SELECT 1 FROM lineup_owners o WHERE o.lineup_id=e.id AND o.user_id=:owner) OR EXISTS (SELECT 1 FROM lineup_members m WHERE m.lineup_id=e.id AND m.user_id=:member))',['owner'=>$user,'member'=>$user]];
 }
 public function listing(string $user,bool $past,int $page):array {
  [$allowed,$p]=$this->access($user);$op=$past?'<':'>=';$order=$past?'DESC':'ASC';$offset=($page-1)*12;$p['now']=gmdate('Y-m-d H:i:s');$where="$allowed AND starts_at $op :now";
  $total=(int)$this->library->one('SELECT COUNT(*) AS n FROM event_lineups e WHERE '.$where,$p)['n'];
  $items=$this->library->all("SELECT e.*, (SELECT COUNT(*) FROM event_lineup_songs es WHERE es.lineup_id=e.id) AS song_count FROM event_lineups e WHERE $where ORDER BY starts_at $order,id LIMIT 12 OFFSET $offset",$p);
  return ['items'=>$items,'total'=>$total,'pages'=>(int)ceil($total/12),'page'=>$page];
 }
 public function find(string $id,string $user,string $unusedShare=''):?array {
  [$allowed,$p]=$this->access($user);$p['id']=$id;$event=$this->library->one('SELECT e.* FROM event_lineups e WHERE e.id=:id AND '.$allowed,$p);if(!$event)return null;
  $owner=$this->library->one('SELECT u.id,u.name,u.email FROM lineup_owners o JOIN users u ON u.id=o.user_id WHERE o.lineup_id=:id',['id'=>$id]);
  $admin=$this->library->one("SELECT id FROM users WHERE id=:id AND role='ADMIN' AND active=1",['id'=>$user]);$event['is_owner']=(bool)$admin||($owner&&$owner['id']===$user);$event['owner']=$owner;$permission=$this->library->one('SELECT can_edit FROM lineup_members WHERE lineup_id=:id AND user_id=:user',['id'=>$id,'user'=>$user]);$event['can_edit']=$event['is_owner']||!empty($permission['can_edit']);
  $event['members']=$this->library->all('SELECT u.id,u.name,u.email,u.active,m.can_edit FROM lineup_members m JOIN users u ON u.id=m.user_id WHERE m.lineup_id=:id ORDER BY u.name',['id'=>$id]);
  $event['songs']=$this->library->all('SELECT es.*,c.content_json,c.version AS copy_version FROM event_lineup_songs es JOIN lineup_song_copies c ON c.entry_id=es.id WHERE es.lineup_id=:id ORDER BY es.position',['id'=>$id]);
  foreach($event['songs'] as &$s){$copy=json_decode($s['content_json'],true,512,JSON_THROW_ON_ERROR);$s+=array_intersect_key($copy,array_flip(['title','slug','original_key','tempo','time_signature','artist']));$s['status']='published';unset($s['content_json']);}return $event;
 }
 public function save(?array $event,string $user,array $data,array $songs,int $version):string {
  if($event){$authorized=$this->find($event['id'],$user);if(!$authorized||!$authorized['is_owner'])throw new LineupConflict('You do not manage this schedule.');}
  $db=$this->library->db;$db->beginTransaction();try{
   $id=$event['id']??bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s');$p=$data+['id'=>$id,'now'=>$now];
   if($event){$q=$db->prepare('UPDATE event_lineups SET title=:title,starts_at=:starts_at,timezone=:timezone,location=:location,notes=:notes,updated_at=:now,version=version+1 WHERE id=:id AND version=:version');$q->execute($p+['version'=>$version]);if($q->rowCount()!==1)throw new LineupConflict('This lineup changed in another tab. Reload before saving.');}
   else{$this->library->execute('INSERT INTO event_lineups (id,owner_hash,title,starts_at,timezone,location,notes,share_token,version,created_at,updated_at) VALUES (:id,:legacy,:title,:starts_at,:timezone,:location,:notes,NULL,1,:created,:now)',$p+['legacy'=>hash('sha256','user:'.$user),'created'=>$now]);$this->library->execute('INSERT INTO lineup_owners (lineup_id,user_id) VALUES (:id,:user)',['id'=>$id,'user'=>$user]);}
   $existing=array_column($this->library->all('SELECT id,song_id FROM event_lineup_songs WHERE lineup_id=:id',['id'=>$id]),'song_id','id');$kept=[];$copies=new ScheduleSongRepository($this->library);
   foreach(array_values($songs) as $i=>$s){$entry=$s['entry_id']??'';
    if($entry){if(!isset($existing[$entry])||$existing[$entry]!==$s['song_id']||isset($kept[$entry]))throw new LineupConflict('Invalid or duplicate schedule song. Reload this page.');$this->library->execute('UPDATE event_lineup_songs SET position=:position,performance_key=:key,notes=:notes WHERE id=:id',['position'=>$i,'key'=>$s['performance_key'],'notes'=>$s['notes'],'id'=>$entry]);}
    else{$entry=bin2hex(random_bytes(16));$this->library->execute('INSERT INTO event_lineup_songs (id,lineup_id,song_id,position,performance_key,notes) VALUES (:id,:lineup,:song,:position,:key,:notes)',['id'=>$entry,'lineup'=>$id,'song'=>$s['song_id'],'position'=>$i,'key'=>$s['performance_key'],'notes'=>$s['notes']]);$copies->snapshot($entry,$s['song_id']);}$kept[$entry]=true;
   }
   foreach($existing as $entry=>$source)if(!isset($kept[$entry]))$this->library->execute('DELETE FROM event_lineup_songs WHERE id=:id',['id'=>$entry]);
   $db->commit();return $id;
  }catch(\Throwable $e){$db->rollBack();throw $e;}
 }
 public function delete(string $id,string $user,int $version):void {
  $event=$this->find($id,$user);if(!$event||!$event['is_owner'])throw new LineupConflict('You do not manage this schedule.');
  // Foreign keys cascade only into this lineup's entries, snapshots and assignments.
  $q=$this->library->db->prepare('DELETE FROM event_lineups WHERE id=:id AND version=:version');
  $q->execute(['id'=>$id,'version'=>$version]);
  if($q->rowCount()!==1)throw new LineupConflict('This lineup changed in another tab. Open it again before deleting.');
 }
 public function assign(string $id,string $manager,string $email,bool $remove,bool $canEdit=false):void {
  $event=$this->find($id,$manager);if(!$event||!$event['is_owner'])throw new LineupConflict('You do not manage this schedule.');
  $u=$this->library->one('SELECT id FROM users WHERE email=:email'.($remove?'':' AND active=1'),['email'=>mb_strtolower($email)]);if(!$u)throw new LineupConflict('No active account was found for that email. Ask an administrator to create it first.');
  if($remove)$this->library->execute('DELETE FROM lineup_members WHERE lineup_id=:id AND user_id=:user',['id'=>$id,'user'=>$u['id']]);
  elseif(!$this->library->one('SELECT user_id FROM lineup_members WHERE lineup_id=:id AND user_id=:user',['id'=>$id,'user'=>$u['id']]))$this->library->execute('INSERT INTO lineup_members (lineup_id,user_id,can_edit) VALUES (:id,:user,:edit)',['id'=>$id,'user'=>$u['id'],'edit'=>(int)$canEdit]);
  else $this->library->execute('UPDATE lineup_members SET can_edit=:edit WHERE lineup_id=:id AND user_id=:user',['id'=>$id,'user'=>$u['id'],'edit'=>(int)$canEdit]);
 }
 public static function songUrl(array $event,array $song,string $unused=''):string {$p=['key'=>$song['performance_key'],'lineup'=>$event['id'],'entry'=>$song['id']];if(isset($_GET['view'])&&is_string($_GET['view'])&&isset(\App\Services\SectionView::MODES[$_GET['view']]))$p['view']=$_GET['view'];return '/chords/'.$song['slug'].'?'.http_build_query($p);}
}
