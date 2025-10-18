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

    public function generateSevenDigit(string $model = 'gpt-5'): string
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
                        ['role' => 'user', 'content' => "Produce a random 7-digit number using only digits 1-6 (like dice values). Return only the number (e.g., 2461535)."],
                    ],
                    'temperature' => 1.0,
                    'max_tokens' => 8,
                ]
            ]);
            $data = json_decode((string)$res->getBody(), true);
            $cand = trim($data['choices'][0]['message']['content'] ?? '');
            // Validate that all digits are between 1-6
            if (preg_match('/^[1-6]{7}$/', $cand)) {
                return $cand;
            }
        } catch (\Throwable $e) {
        }
        // Fallback: generate random 7-digit number using only 1-6 (dice values)
        $n = '';
        for ($i = 0; $i < 7; $i++) { $n .= (string)random_int(1, 6); }
        return $n;
    }

    // v1 generateThreeDigit removed - using generateSevenDigit for v2

    public function generateAnnouncementText(string $model = 'gpt-5'): string
    {
        $fallback = 'The new golden number is ready! Try your luck and start a new game with /startgame.';
        try {
            $res = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => "Write a short, exciting English message telling the user that a new 7-digit golden number is ready and they should try their luck. Mention that they can start a new game with /startgame and roll up to 7 times."],
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

    public function generateCongratsText(string $model, string $digits): string
    {
        $fallback = 'Incredible! Exact match. You just hit the Golden Number!';
        try {
            $res = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => "Write a short, energetic English congratulation for a player who matched the exact 7-digit golden number (".$digits."). Keep it under 25 words."],
                    ],
                    'temperature' => 0.9,
                    'max_tokens' => 40,
                ]
            ]);
            $data = json_decode((string)$res->getBody(), true);
            $txt = trim($data['choices'][0]['message']['content'] ?? '');
            if ($txt !== '') return $txt;
        } catch (\Throwable $e) {
        }
        return $fallback;
    }
}
