<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $details = [],
        public readonly string $errorCode = ''
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $m, array $details = []): self { return new self(400, $m, $details, 'bad_request'); }
    public static function unauthorized(string $m = 'برای ادامه ابتدا وارد حساب کاربری شوید.'): self { return new self(401, $m, [], 'unauthorized'); }
    public static function forbidden(string $m = 'شما به این بخش دسترسی ندارید.'): self { return new self(403, $m, [], 'forbidden'); }
    public static function notFound(string $m = 'موردی یافت نشد.'): self { return new self(404, $m, [], 'not_found'); }
    public static function unprocessable(string $m, array $details = []): self { return new self(422, $m, $details, 'validation_failed'); }
    public static function tooManyRequests(string $m, int $retryAfter = 60): self { return new self(429, $m, ['retry_after' => $retryAfter], 'too_many_requests'); }
}
