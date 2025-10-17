<?php

namespace App\Services;

use GuzzleHttp\Client;

class TelegramService
{
    private Client $client;

    public function __construct(private string $botToken)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.telegram.org/bot' . $this->botToken . '/',
            'timeout'  => 10.0,
        ]);
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        $res = $this->client->post('sendMessage', ['form_params' => $payload]);
        return json_decode((string)$res->getBody(), true) ?? [];
    }

    public function sendDice(int|string $chatId): array
    {
        $res = $this->client->post('sendDice', ['form_params' => [
            'chat_id' => $chatId
        ]]);
        return json_decode((string)$res->getBody(), true) ?? [];
    }
    public function defaultReplyKeyboard(bool $hasActiveSession = false): array
    {
        $primary = $hasActiveSession ? 'Next' : 'Start';
        return [
            'keyboard' => [
                [ ['text' => $primary], ['text' => 'Status'] ],
                [ ['text' => 'Leaderboard'], ['text' => 'Wallet'] ],
                [ ['text' => 'Deposit'], ['text' => 'Withdraw'] ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true
        ];
    }
}

