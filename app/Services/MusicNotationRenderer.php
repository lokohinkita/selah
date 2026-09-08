<?php
declare(strict_types=1);
namespace App\Services;
final class MusicNotationRenderer {
    private const CUES=['build'=>'↑ Build','drop'=>'↓ Drop','stop'=>'× Stop','hold'=>'— Hold','crescendo'=>'< Crescendo','decrescendo'=>'> Decrescendo','soft'=>'Soft','full'=>'Full','accent'=>'> Accent','staccato'=>'· Staccato','sustain'=>'— Sustain','palm-mute'=>'P.M. · Palm mute','dead-note'=>'× Dead note','muted-strum'=>'× Muted strum','open-strum'=>'Open strum','chord-hit'=>'Chord hit','rake'=>'Rake','arpeggio'=>'Arpeggio','picking'=>'Picking','fingerstyle'=>'Fingerstyle','tremolo'=>'Tremolo','slide'=>'Slide','hammer-on'=>'H · Hammer-on','pull-off'=>'P · Pull-off','full-band'=>'Full band','guitar-only'=>'Guitar only','keys-only'=>'Keys only','drums-in'=>'Drums in','bass-in'=>'Bass in','half-time'=>'Half-time','unison-hit'=>'Unison hit','ambient'=>'Ambient','instrumental'=>'Instrumental','vocal-only'=>'Vocal only','all-in'=>'All in'];
    public static function render(string $token): string {
        $token=trim($token,'[] '); [$kind,$value]=array_pad(explode(':',$token,2),2,'');
        if($kind==='dyn' && Dynamics::valid($value)) return '<span class="notation dynamic" aria-label="Dynamics '.\e($value).'" title="'.\e(Dynamics::label($value)).'">'.\e($value).'</span>';
        if($kind==='strum') return '<span class="notation strum" aria-label="Strumming '.\e($value).'">'.\e(strtr($value,['D'=>'↓','U'=>'↑'])).'</span>';
        if($kind==='repeat' && ctype_digit($value)) return '<span class="notation">↻ '.min(16,(int)$value).'×</span>';
        if(in_array($kind,['note','rest'],true)) {
            $dotted=str_starts_with($value,'dotted-'); $base=str_replace('dotted-','',$value);
            $symbols=$kind==='note'?['whole'=>'𝅝','half'=>'𝅗𝅥','quarter'=>'♩','eighth'=>'♪','sixteenth'=>'𝅘𝅥𝅯']:['whole'=>'𝄻','half'=>'𝄼','quarter'=>'𝄽','eighth'=>'𝄾','sixteenth'=>'𝄿'];
            if(isset($symbols[$base])) return '<span class="notation music-symbol" aria-label="'.\e($value.' '.$kind).'">'.$symbols[$base].($dotted?'·':'').'<small>'.\e(str_replace('-',' ',$value).' '.$kind).'</small></span>';
        }
        if(isset(self::CUES[$kind]) && $value==='') return '<span class="notation cue">'.\e(self::CUES[$kind]).'</span>';
        return '<span class="notation">'.\e('['.$token.']').'</span>';
    }
    public static function tokens(string $text): string { preg_match_all('/\[([^\]\r\n]+)\]/u',$text,$m); return implode(' ',array_map([self::class,'render'],$m[1])); }
}
