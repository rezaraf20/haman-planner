<?php
declare(strict_types=1);
namespace App\Services\Telegram;
use Illuminate\Support\Facades\Http;
use RuntimeException;
final class TelegramService {
 private function token(): string { $token=(string)config('services.telegram.bot_token'); if($token==='') throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.'); return $token; }
 private function api(string $method): string { return 'https://api.telegram.org/bot'.$this->token().'/'.$method; }
 public function sendMessage(string|int $chatId,string $text,?array $keyboard=null): array {
  $payload=['chat_id'=>$chatId,'text'=>mb_substr($text,0,4096)];
  $keyboard=$this->normalizeKeyboard($keyboard);
  if($keyboard!==null) $payload['reply_markup']=json_encode(['inline_keyboard'=>$keyboard],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $response=Http::post($this->api('sendMessage'),$payload); if($response->failed()) throw new RuntimeException('Telegram request failed: '.$response->status()); return $response->json();
 }
 public function editMessage(string|int $chatId,int $messageId,string $text,?array $keyboard=null): array {
  $payload=['chat_id'=>$chatId,'message_id'=>$messageId,'text'=>mb_substr($text,0,4096)];
  $keyboard=$this->normalizeKeyboard($keyboard);
  if($keyboard!==null) $payload['reply_markup']=json_encode(['inline_keyboard'=>$keyboard],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $response=Http::post($this->api('editMessageText'),$payload); if($response->failed()) throw new RuntimeException('Telegram edit request failed: '.$response->status()); return $response->json();
 }
 /**
  * Guarantees a 2D array of InlineKeyboardButton: a bare button becomes its own row,
  * invalid entries and empty rows are dropped, and an empty keyboard is omitted.
  */
 private function normalizeKeyboard(?array $keyboard): ?array {
  if($keyboard===null) return null;
  $rows=[];
  foreach($keyboard as $row){
   if(!is_array($row)) continue;
   if(isset($row['text'])) $row=[$row];
   $row=array_values(array_filter($row,fn($b)=>is_array($b)&&isset($b['text'])&&(isset($b['callback_data'])||isset($b['url']))));
   if($row!==[]) $rows[]=$row;
  }
  return $rows===[]?null:$rows;
 }
 public function answerCallback(string $callbackId,?string $text=null): array {
  $payload=['callback_query_id'=>$callbackId]; if($text!==null) $payload['text']=mb_substr($text,0,200);
  $response=Http::post($this->api('answerCallbackQuery'),$payload); if($response->failed()) throw new RuntimeException('Telegram callback request failed: '.$response->status()); return $response->json();
 }
 public function getFilePath(string $fileId): string { $response=Http::get($this->api('getFile'),['file_id'=>$fileId]); if($response->failed()||!$response->json('ok')) throw new RuntimeException('Telegram getFile request failed.'); $path=(string)$response->json('result.file_path'); if($path==='') throw new RuntimeException('Telegram returned no file path.'); return $path; }
 public function downloadFile(string $filePath,string $destination): void { $response=Http::timeout(60)->get('https://api.telegram.org/file/bot'.$this->token().'/'.$filePath); if($response->failed()) throw new RuntimeException('Telegram file download failed: '.$response->status()); if(file_put_contents($destination,$response->body())===false) throw new RuntimeException('Unable to write Telegram audio file.'); }
}