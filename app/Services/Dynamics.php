<?php
declare(strict_types=1);
namespace App\Services;
/** Shared vocabulary for section dynamics, inline tokens, and editor controls. */
final class Dynamics {
 public const SYMBOLS=[
  'ppp'=>['Pianississimo','Very, very soft'],'pp'=>['Pianissimo','Very soft'],'p'=>['Piano','Soft'],
  'mp'=>['Mezzo-piano','Moderately soft'],'mf'=>['Mezzo-forte','Moderately loud'],
  'f'=>['Forte','Loud'],'ff'=>['Fortissimo','Very loud'],'fff'=>['Fortississimo','Very, very loud'],
  '<'=>['Crescendo','Gradually get louder'],'>'=>['Decrescendo / Diminuendo','Gradually get softer'],
  'cresc.'=>['Crescendo','Increase volume gradually'],'dim.'=>['Diminuendo','Decrease volume gradually'],
  'decresc.'=>['Decrescendo','Gradually become softer'],
  'sfz'=>['Sforzando','Sudden strong accent'],'sfz.'=>['Sforzando','Sudden strong accent'],
  'fz'=>['Forzando','Forcefully accented'],'sf'=>['Sforzato','Strong emphasis'],
  'fp'=>['Forte-piano','Loud, then immediately soft'],'rfz'=>['Rinforzando','Sudden reinforcement / emphasis'],
 ];
 public static function label(string $symbol):string {return isset(self::SYMBOLS[$symbol])?implode(' — ',self::SYMBOLS[$symbol]):$symbol;}
 public static function valid(string $symbol):bool {return isset(self::SYMBOLS[$symbol]);}
 public static function pattern():string {return '/\[dyn:('.implode('|',array_map(fn($s)=>preg_quote($s,'/'),array_keys(self::SYMBOLS))).')\]/';}
 public static function mask(string $text):string {return preg_replace_callback(self::pattern(),fn($m)=>str_repeat(' ',strlen($m[0])),$text);}
 public static function strip(string $text):string {return preg_replace(self::pattern(),'',$text);}
}
