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

    public function sendMessage(int|string $chatId, string $text, array $buttons = []): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if (!empty($buttons)) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $buttons]);
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

    public function defaultKeyboard(): array
    {
        return [[
            ['text' => 'Start', 'callback_data' => 'start'],
            ['text' => 'Leaderboard', 'callback_data' => 'leaderboard'],
            ['text' => 'Status', 'callback_data' => 'status']
        ]];
    }
}
