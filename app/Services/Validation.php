<?php
declare(strict_types=1);
namespace App\Services;
final class Validation {
 public static function safeUrl(?string $url):bool {if(!$url)return true;return strlen($url)<=500 && filter_var($url,FILTER_VALIDATE_URL)!==false && in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['https','http'],true) && !parse_url($url,PHP_URL_USER) && !parse_url($url,PHP_URL_PASS);}
 public static function text(array $input,string $key,int $max=200):string {$v=$input[$key]??'';return is_string($v)?mb_substr(trim($v),0,$max):'';}
 public static function key(string $key):bool {return (bool)preg_match('/^[A-G](?:#|b)?m?$/',$key);}
 public static function submission(array $input):array {
  $errors=[];$title=self::text($input,'title',181);$artist=self::text($input,'artist',161);$email=self::text($input,'email',255);$content=self::text($input,'content',50001);$key=self::text($input,'song_key');$url=self::text($input,'source_url',501);
  if(mb_strlen($title)<2||mb_strlen($title)>180)$errors['title']='Enter a song title between 2 and 180 characters.';
  if(mb_strlen($artist)<2||mb_strlen($artist)>160)$errors['artist']='Enter an artist name between 2 and 160 characters.';
  if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))$errors['email']='Enter a valid email address.';
  if(!self::key($key))$errors['song_key']='Choose a valid musical key.';
  if(mb_strlen($content)<20||mb_strlen($content)>50000)$errors['content']='Enter a chord sheet between 20 and 50,000 characters.';
  if(!self::safeUrl($url))$errors['source_url']='Use a full http or https source URL, up to 500 characters.';
  if(($input['rights']??'')!=='1')$errors['rights']='Confirm that you have permission to share this material.';
  return $errors;
 }
}
