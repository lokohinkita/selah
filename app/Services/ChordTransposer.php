<?php
declare(strict_types=1);
namespace App\Services;

/** Pure pitch transformation. Source charts are never mutated in storage. */
final class ChordTransposer {
    private const PITCH = ['C'=>0,'D'=>2,'E'=>4,'F'=>5,'G'=>7,'A'=>9,'B'=>11];
    private const SHARPS = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
    private const FLATS = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];
    private const TOKEN = '[A-G][#b]?(?:(?:maj|min|dim|aug|sus|add|m|M|ø|°|\+|-)|[0-9]+|[#b][0-9]+|\((?:add|no)?[#b]?[0-9]+(?:,[#b]?[0-9]+)*\))*(?:/[A-G][#b]?)?';

    public static function keys(string $source): array {
        $minor = str_ends_with($source, 'm');
        return array_map(fn($n)=>$n.($minor?'m':''), ['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B']);
    }
    public static function pitch(string $note): int {
        if (!preg_match('/^([A-G])([#b]?)(m?)$/', $note, $m)) throw new \InvalidArgumentException('Invalid key.');
        return (self::PITCH[$m[1]] + (($m[2]??'')==='#'?1:(($m[2]??'')==='b'?-1:0)) + 12) % 12;
    }
    public static function validTarget(string $source, string $target): bool {
        return Validation::key($target) && str_ends_with($source,'m')===str_ends_with($target,'m');
    }
    public static function step(string $key, int $steps): string {
        return self::keys($key)[(self::pitch($key)+$steps+120)%12];
    }
    private static function note(string $note, int $offset, bool $flat): string {
        return ($flat?self::FLATS:self::SHARPS)[(self::pitch($note)+$offset+12)%12];
    }
    public static function chord(string $chord, int $offset, bool $flat): string {
        if (!preg_match('~^('.self::TOKEN.')$~u', $chord)) return $chord;
        return preg_replace_callback('/^([A-G][#b]?)|\/([A-G][#b]?)$/', fn($m)=>isset($m[2])&&$m[2]!==''?'/'.self::note($m[2],$offset,$flat):self::note($m[1],$offset,$flat), $chord);
    }
    public static function isChordLine(string $line): bool {
        $line=Dynamics::mask($line);
        $without = preg_replace('~(?<![A-Za-z0-9.])'.self::TOKEN.'(?![A-Za-z0-9])~u', '', $line, -1, $count);
        return $count>0 && preg_match('/^[\s|:;.\/0-9xX()\-]*(?:N\.?C\.?[\s|:;.\/0-9xX()\-]*)?$/u', $without)===1;
    }
    private static function expandTabs(string $line): string {
        $out=''; foreach(preg_split('//u',$line,-1,PREG_SPLIT_NO_EMPTY) as $char) $out.=$char==="\t"?str_repeat(' ',8-mb_strlen($out)%8):$char;
        return $out;
    }
    public static function withoutChords(string $chart):string {
        $lines=explode("\n",$chart);
        foreach($lines as &$line){
            if(!self::isChordLine($line))continue;
            $dynamic=substr(Dynamics::pattern(),1,-1);
            $line=preg_replace_callback('~'.$dynamic.'|(?<![A-Za-z0-9.])'.self::TOKEN.'(?![A-Za-z0-9])~u',fn($m)=>str_starts_with($m[0],'[dyn:')?$m[0]:str_repeat(' ',mb_strlen($m[0])),$line);
        }unset($line);
        return implode("\n",$lines);
    }
    public static function chart(string $chart, string $source, string $target): string {
        if (!self::validTarget($source,$target)) throw new \InvalidArgumentException('The selected key must preserve major/minor mode.');
        $offset=(self::pitch($target)-self::pitch($source)+12)%12;
        if ($source===$target) return $chart;
        $flat=str_contains($target,'b') || in_array($target,['F','Dm','Gm','Cm','Fm'],true);
        $lines=explode("\n",$chart);
        for($i=0;$i<count($lines);$i++) {
            if (!self::isChordLine($lines[$i])) continue;
            $line=self::expandTabs($lines[$i]);
            // Dynamics are fixed-width atoms, as are bar/rhythm symbols. Never let
            // an expanded chord consume a marker's reserved columns.
            $dynamic=substr(Dynamics::pattern(),1,-1);
            preg_match_all('~'.$dynamic.'|(?<![A-Za-z0-9.])'.self::TOKEN.'(?![A-Za-z0-9])|\S~u',$line,$matches,PREG_OFFSET_CAPTURE);
            $out='';$cursor=0;$shift=0;$insertions=[];
            foreach($matches[0] as [$token,$byte]) {
                $start=mb_strlen(substr($line,0,$byte));
                $gap=$start-$cursor;
                $minimum=mb_strlen($out)+($gap>0&&$out!==''?1:0);
                $anchor=max($start+$shift,$minimum);
                $extra=$anchor-($start+$shift);
                if($extra){$insertions[]=[$start,$extra];$shift+=$extra;}
                $out.=str_repeat(' ',max(0,$anchor-mb_strlen($out)));
                $out.=preg_match(Dynamics::pattern(),$token)?$token:self::chord($token,$offset,$flat);
                $cursor=$start+mb_strlen($token);
            }
            $lines[$i]=$out.mb_substr($line,$cursor);
            if($insertions && isset($lines[$i+1]) && !self::isChordLine($lines[$i+1]) && trim($lines[$i+1])!=='') {
                $lyric=self::expandTabs($lines[$i+1]);
                preg_match_all(Dynamics::pattern(),$lyric,$marks,PREG_OFFSET_CAPTURE);
                foreach($insertions as &$insertion)foreach($marks[0] as [$mark,$byte]){
                    $start=mb_strlen(substr($lyric,0,$byte));
                    if($insertion[0]>$start&&$insertion[0]<$start+strlen($mark))$insertion[0]=$start;
                }unset($insertion);
                foreach(array_reverse($insertions) as [$at,$extra]) if($at<mb_strlen($lyric)) $lyric=mb_substr($lyric,0,$at).str_repeat(' ',$extra).mb_substr($lyric,$at);
                $lines[$i+1]=$lyric;
            }
        }
        return implode("\n",$lines);
    }
}
