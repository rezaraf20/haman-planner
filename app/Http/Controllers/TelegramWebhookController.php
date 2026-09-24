<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramPlannerBotService;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramPlannerBotService $bot,
        private readonly TelegramService $telegram,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret=(string)config('services.telegram.webhook_secret');
        if($secret==='' && app()->environment('production')) return response()->json(['ok'=>false],503);
        if($secret!==''){
            $provided=(string)$request->header('X-Telegram-Bot-Api-Secret-Token');
            if($provided==='' || !hash_equals($secret,$provided)) return response()->json(['ok'=>false],401);
        }

        $updateId=$request->input('update_id');
        if($updateId!==null){
            $key='telegram:webhook:update:'.(string)$updateId;
            if(!Cache::add($key,true,now()->addMinutes(10))) return response()->json(['ok'=>true]);
        }

        try{
            $callback=$request->input('callback_query');
            if(is_array($callback)){
                $chatId=$callback['message']['chat']['id']??null;
                $messageId=$callback['message']['message_id']??null;
                $callbackId=(string)($callback['id']??'');
                $data=(string)($callback['data']??'');
                $username=(string)($callback['from']['username']??'');
                if($chatId!==null && $messageId!==null && $callbackId!=='') $this->bot->handleCallback($chatId,$username,$callbackId,(int)$messageId,$data);
                return response()->json(['ok'=>true]);
            }

            $message=$request->input('message',[]);
            $chatId=$message['chat']['id']??null;
            if($chatId===null) return response()->json(['ok'=>true]);
            $username=(string)($message['from']['username']??'');

            $text=trim((string)($message['text']??''));
            if($text!==''){
                if(preg_match('/^\/start(?:\s+(.+))?$/u',$text,$m)){
                    $this->bot->start($chatId,$username,isset($m[1])?trim($m[1]):null);
                } else {
                    $this->bot->handleText($chatId,$username,$text);
                }
                return response()->json(['ok'=>true]);
            }

            if(isset($message['voice'])){
                $this->telegram->sendMessage($chatId,'فعلاً Voice را غیرفعال کرده‌ایم تا نسخه دکمه‌ای Planner را کامل و پایدار کنیم. از دکمه‌های ربات استفاده کن.');
            }
        }catch(\Throwable $e){
            Log::error('Telegram planner update failed',['error'=>$e->getMessage(),'chat_id'=>$request->input('message.chat.id')]);
            try{
                $chatId=$request->input('message.chat.id')??$request->input('callback_query.message.chat.id');
                if($chatId!==null) $this->telegram->sendMessage($chatId,'خطا در پردازش درخواست. لطفاً دوباره تلاش کن.');
            }catch(\Throwable){}
        }
        return response()->json(['ok'=>true]);
    }
}