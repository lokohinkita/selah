<?php
declare(strict_types=1);
namespace App\Services;
final class SectionView {
 public const MODES=['aligned'=>'Chords & lyrics','lyrics'=>'Lyrics only','chords'=>'Chords only','drums'=>'Drums arrangement'];
 public static function content(array $section,string $mode):string {
  if($mode==='drums')return $section['drums_content']??ChordTransposer::withoutChords($section['aligned_content']);
  if($mode==='aligned')return $section['aligned_content'];
  $field=$mode==='lyrics'?'lyric_content':'chord_content';
  if(trim($section[$field]??'')!=='')return $section[$field];
  // Older songs may contain only an aligned chart. Prefer explicit fields when present.
  $lines=[];foreach(explode("\n",$section['aligned_content']) as $line){
   $plain=trim(Dynamics::strip($line));$chord=ChordTransposer::isChordLine($line);
   if($plain===''||($mode==='chords'?$chord:!$chord))$lines[]=$line;
  }
  return trim(implode("\n",$lines),"\r\n");
 }
}
