<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function sendMessage(string $html): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        Log::info('Telegram sendMessage called', [
            'chat_id' => $chatId,
            'message_length' => strlen($html),
        ]);

        if (! $token || ! $chatId) {
            Log::warning('Telegram credentials not configured, skipping message.');

            return;
        }

        $response = Http::withoutVerifying()
            ->timeout(15)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
            ]);

        if ($response->failed()) {
            Log::error('Telegram sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } else {
            Log::info('Telegram sendMessage succeeded', [
                'status' => $response->status(),
            ]);
        }

        sleep(2);
    }
}
