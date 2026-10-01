<?php

declare(strict_types=1);

$token = (string) env('BALE_BOT_TOKEN', '');

return [
    // Bot token from @botfather in Bale (format 123456789:abc...)
    'bot_token' => $token,
    // Bot username without @ (used for the deep link https://ble.ir/<username>?start=...)
    'bot_username' => ltrim((string) env('BALE_BOT_USERNAME', ''), '@'),
    // Numeric bot id = the part of the token before ":" (needed by Safir)
    'bot_id' => (int) (env('BALE_BOT_ID', '') ?: (str_contains($token, ':') ? strstr($token, ':', true) : 0)),
    // Wallet payment token (provider_token) from @botfather. Test token: WALLET-TEST-1111111111111111
    'wallet_token' => (string) env('BALE_WALLET_TOKEN', ''),
    // Safir (business panel https://business.bale.ai) api-access-key — sends OTP to users who never started the bot
    'safir_api_key' => (string) env('BALE_SAFIR_API_KEY', ''),
    // Send login codes through Bale (falls back to SMS when the number has no Bale account)
    'otp_enabled' => (bool) env('BALE_OTP_ENABLED', false),
    // API endpoints (change only for a proxy or testing)
    'api_base' => rtrim((string) env('BALE_API_BASE', 'https://tapi.bale.ai'), '/'),
    'safir_url' => (string) env('BALE_SAFIR_URL', 'https://safir.bale.ai/api/v3/send_message'),
    // Secret part of the webhook URL. Empty => derived from APP_KEY
    'webhook_secret' => (string) env('BALE_WEBHOOK_SECRET', ''),
];
