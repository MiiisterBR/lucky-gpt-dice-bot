<?php

namespace App\Services;

use GuzzleHttp\Client;

class OpenAIService
{
    private Client $client;

    public function __construct(private string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 15.0,
        ]);
    }

    public function generateThreeDigit(string $model = 'gpt-5'): string
    {
        try {
            $res = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => "Produce a random 3-digit number (digits only, no text). Return only the number (e.g., 527)."],
                    ],
                    'temperature' => 1.0,
                    'max_tokens' => 5,
                ]
            ]);
            $data = json_decode((string)$res->getBody(), true);
            $cand = trim($data['choices'][0]['message']['content'] ?? '');
            if (preg_match('/^\d{3}$/', $cand)) {
                return $cand;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }
        return (string)random_int(100, 999);
    }

    public function generateAnnouncementText(string $model = 'gpt-5'): string
    {
        $fallback = 'عدد طلایی جدید ساخته شد! شانست رو امتحان کن؛ شاید با سه تا تاس بتونی برنده بشی. الان حدست رو با /guess 123 بفرست.';
        try {
            $res = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => "به فارسی یک پیام کوتاه و هیجان‌انگیز بساز که به کاربر بگوید عدد طلایی جدید ساخته شده و بهتر است شانسش را امتحان کند. اشاره مختصر به ‘سه تا تاس’ و دستور /guess 123 داشته باشد. فقط متن پیام را بده."],
                    ],
                    'temperature' => 0.9,
                    'max_tokens' => 60,
                ]
            ]);
            $data = json_decode((string)$res->getBody(), true);
            $txt = trim($data['choices'][0]['message']['content'] ?? '');
            if ($txt !== '') {
                return $txt;
            }
        } catch (\Throwable $e) {
        }
        return $fallback;
    }
}
