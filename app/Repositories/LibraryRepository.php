<?php
declare(strict_types=1);
namespace App\Repositories;
use PDO;
final class LibraryRepository {
    public function __construct(public PDO $db) {}
    public function all(string $sql, array $params=[]): array { $q=$this->db->prepare($sql); $q->execute($params); return $q->fetchAll(); }
    public function one(string $sql, array $params=[]): ?array { return $this->all($sql,$params)[0]??null; }
    public function execute(string $sql, array $params=[]): void { $this->db->prepare($sql)->execute($params); }
    private function filter(array $f, array &$p): string {
        $where="s.status='published'";
        if (!empty($f['q'])) { $where.=" AND (LOWER(s.title) LIKE :q1 OR EXISTS (SELECT 1 FROM song_artists sa2 JOIN artists a2 ON a2.id=sa2.artist_id WHERE sa2.song_id=s.id AND LOWER(a2.name) LIKE :q2))"; $p['q1']=$p['q2']='%'.mb_strtolower($f['q']).'%'; }
        if (!empty($f['letter'])) { $where.=' AND LOWER(s.title) LIKE :letter'; $p['letter']=strtolower($f['letter']).'%'; }
        if (!empty($f['artist'])) { $where.=' AND EXISTS (SELECT 1 FROM song_artists sa3 JOIN artists a3 ON a3.id=sa3.artist_id WHERE sa3.song_id=s.id AND a3.slug=:artist)'; $p['artist']=$f['artist']; }
        if (!empty($f['language'])) { $where.=' AND l.slug=:language'; $p['language']=$f['language']; }
        if (!empty($f['key'])) { $where.=' AND s.original_key=:key'; $p['key']=$f['key']; }
        if (isset($f['ids'])) { $ids=array_slice($f['ids'],0,200); if (!$ids) $where.=' AND 1=0'; else { $names=[]; foreach($ids as $i=>$id){$names[]=':id'.$i;$p['id'.$i]=$id;} $where.=' AND s.id IN ('.implode(',',$names).')'; } }
        return $where;
    }
    public function songs(array $filters=[], int $page=1, int $limit=12, string $sort='title'): array {
        $p=[]; $where=$this->filter($filters,$p); $limit=max(1,min(50,$limit)); $offset=(max(1,$page)-1)*$limit;
        $order=$sort==='newest'?'s.published_at DESC, s.title':'s.title';
        $from=' FROM songs s JOIN song_artists sa ON sa.song_id=s.id AND sa.role=\'primary\' JOIN artists a ON a.id=sa.artist_id JOIN languages l ON l.id=s.language_id';
        $total=(int)$this->one('SELECT COUNT(*) AS total'.$from.' WHERE '.$where,$p)['total'];
        $items=$this->all('SELECT s.id,s.title,s.slug,s.original_key,s.tempo,s.time_signature,s.capo,s.published_at,a.name AS artist,a.slug AS artist_slug,a.color,l.name AS language'.$from.' WHERE '.$where.' ORDER BY '.$order." LIMIT $limit OFFSET $offset",$p);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)];
    }
    public function song(string $slug, bool $includeUnpublished=false): ?array {
        $s=$this->one("SELECT s.*, a.name AS artist, a.slug AS artist_slug, a.color, l.name AS language, l.slug AS language_slug, al.title AS album FROM songs s JOIN song_artists sa ON sa.song_id=s.id AND sa.role='primary' JOIN artists a ON a.id=sa.artist_id JOIN languages l ON l.id=s.language_id LEFT JOIN albums al ON al.id=s.album_id WHERE s.slug=:slug".($includeUnpublished?"":" AND s.status='published'"),['slug'=>$slug]);
        if (!$s) return null;
        $s['sections']=$this->all('SELECT * FROM song_sections WHERE song_id=:id ORDER BY section_order',['id'=>$s['id']]);
        $cues=$this->all('SELECT pc.* FROM song_performance_cues pc JOIN song_sections ss ON ss.id=pc.section_id WHERE ss.song_id=:id ORDER BY pc.cue_order',['id'=>$s['id']]);
        foreach($s['sections'] as &$section) $section['cues']=array_values(array_filter($cues,fn($c)=>$c['section_id']===$section['id']));
        
        $s['featured_artists']=$this->all("SELECT a.* FROM artists a JOIN song_artists sa ON sa.artist_id=a.id WHERE sa.song_id=:id AND sa.role='featured'",['id'=>$s['id']]);
        return $s;
    }
    public function artists(): array { return $this->all("SELECT a.*, (SELECT COUNT(*) FROM song_artists sa JOIN songs s ON s.id=sa.song_id WHERE sa.artist_id=a.id AND s.status='published') AS song_count FROM artists a ORDER BY a.name"); }
    public function taxonomy(string $type): array { if (!in_array($type,['languages'],true)) throw new \InvalidArgumentException(); return $this->all('SELECT * FROM '.$type.' ORDER BY name'); }
    public function trending(int $days=7): array {
        $since=gmdate('Y-m-d H:i:s',time()-86400*max(1,min(365,$days)));
        return $this->all("SELECT s.id,s.title,s.slug,s.original_key,s.tempo,s.time_signature,s.capo,s.published_at,a.name AS artist,a.slug AS artist_slug,a.color,l.name AS language,counts.view_count FROM songs s JOIN song_artists sa ON sa.song_id=s.id AND sa.role='primary' JOIN artists a ON a.id=sa.artist_id JOIN languages l ON l.id=s.language_id JOIN (SELECT song_id, COUNT(*) AS view_count FROM song_views WHERE viewed_at>=:since GROUP BY song_id) counts ON counts.song_id=s.id WHERE s.status='published' ORDER BY counts.view_count DESC,s.title LIMIT 30",['since'=>$since]);
    }
    public function trackView(string $id): void {
        $visitor=hash('sha256',session_id()); $since=gmdate('Y-m-d H:i:s',time()-1800);
        if (!$this->one('SELECT id FROM song_views WHERE song_id=:id AND visitor_hash=:visitor AND viewed_at>=:since',['id'=>$id,'visitor'=>$visitor,'since'=>$since])) $this->execute('INSERT INTO song_views (id,song_id,visitor_hash,viewed_at) VALUES (:id,:song,:visitor,:date)',['id'=>bin2hex(random_bytes(16)),'song'=>$id,'visitor'=>$visitor,'date'=>gmdate('Y-m-d H:i:s')]);
    }
}
