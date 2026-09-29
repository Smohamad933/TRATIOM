<?php

declare(strict_types=1);

namespace Terrarium\Application\DTO;

final readonly class CreateOrderInput
{
    /**
     * @param string $userId
     * @param string $configurationId
     * @param string $paymentGateway
     * @param string $callbackUrl
     */
    public function __construct(
        public string $userId,
        public string $configurationId,
        public string $paymentGateway = 'zarinpal',
        public string $callbackUrl = ''
    ) {}
}
