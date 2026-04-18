<?php
declare(strict_types=1);

/**
 * Envía mensajes de alerta a Telegram.
 */
class TelegramNotifier
{
    public function send(string $message): bool
    {
        $botToken = trim((string)(Config::get('TELEGRAM_BOT_TOKEN') ?? ''));
        $chatId   = trim((string)(Config::get('TELEGRAM_CHAT_ID') ?? ''));

        if ($botToken === '' || $chatId === '') {
            return false;
        }

        $endpoint = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($botToken));
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => 'true',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 8,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($endpoint, false, $ctx);
        if ($response === false || $response === '') {
            return false;
        }

        $json = json_decode($response, true);
        return is_array($json) && ($json['ok'] ?? false) === true;
    }
}
