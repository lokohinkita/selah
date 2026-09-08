<?php
declare(strict_types=1);
namespace App\Models;
final class SongSection {
 public const TYPES=['intro','verse','pre-chorus','chorus','bridge','interlude','instrumental','tag','refrain','breakdown','solo','outro','custom'];
 public const DYNAMICS=['ppp','pp','p','mp','mf','f','ff','fff','<','>','cresc.','dim.','decresc.','sfz','sfz.','fz','sf','fp','rfz'];
 public function __construct(public string $name,public string $type,public int $order,public string $alignedContent,public string $chords,public string $lyrics,public string $dynamics='mf',public int $repeat=1,public string $rhythm='',public string $notes='',public string $cues='',public ?string $drums=null){}
 public static function fromInput(array $v,int $order):self {return new self(trim((string)($v['name']??'')),(string)($v['section_type']??'custom'),$order,(string)($v['aligned_content']??''),(string)($v['chord_content']??''),(string)($v['lyric_content']??''),(string)($v['dynamics']??'mf'),(int)($v['repeat_count']??1),(string)($v['rhythm_pattern']??''),(string)($v['musician_notes']??''),(string)($v['cues']??''),isset($v['custom_drums'])?(string)($v['drums_content']??''):null);}
 public function errors():array {$e=[];if($this->name===''||mb_strlen($this->name)>80)$e[]='Each section needs a name up to 80 characters.';if(!in_array($this->type,self::TYPES,true))$e[]='Choose a supported section type.';if(!in_array($this->dynamics,self::DYNAMICS,true))$e[]='Choose valid dynamics.';if($this->repeat<1||$this->repeat>16)$e[]='Repeat counts must be 1–16.';if(trim($this->alignedContent)===''||mb_strlen($this->alignedContent)>10000)$e[]='Each aligned chart must contain 1–10,000 characters.';foreach([$this->chords,$this->lyrics,$this->drums??'',$this->notes,$this->rhythm] as $text)if(mb_strlen($text)>10000)$e[]='Section fields must be at most 10,000 characters.';if(mb_strlen($this->cues)>1000)$e[]='Cue tokens must be at most 1,000 characters.';return $e;}
}
