<?php
declare(strict_types=1);
namespace App\Services;
final class ChartRenderer {
 public static function inline(string $text):string {
  $html='';$cursor=0;preg_match_all(Dynamics::pattern(),$text,$matches,PREG_OFFSET_CAPTURE);
  foreach($matches[0] as $i=>[$token,$at]){
   $html.=\e(substr($text,$cursor,$at-$cursor));$symbol=$matches[1][$i][0];
   // Width equals source-token columns: rendering cannot pull chords away from lyrics.
   $html.='<span class="inline-dynamic" style="width:'.strlen($token).'ch" role="img" aria-label="'.\e(Dynamics::label($symbol)).'" title="'.\e(Dynamics::label($symbol)).'"><span>'.\e($symbol).'</span></span>';
   $cursor=$at+strlen($token);
  }
  return $html.\e(substr($text,$cursor));
 }
 public static function render(string $text,bool $highlight=true):string {
  $lines=[];foreach(explode("\n",$text) as $line){$html=self::inline($line);$lines[]=$highlight&&ChordTransposer::isChordLine($line)?'<span class="chord-line">'.$html.'</span>':$html;}
  return implode("\n",$lines);
 }
}
