<?php

declare(strict_types=1);

namespace Terrarium\Kernel;

use InvalidArgumentException;
use PDOException;
use Terrarium\Application\Exceptions\NotFoundException;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Application\UseCases\Admin\AdminService;
use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Application\UseCases\Auth\TooManyRequestsException;
use Terrarium\Application\UseCases\Configurator\ValidateConfigurationUseCase;
use Terrarium\Application\UseCases\Ordering\PaymentUnavailableException;
use Terrarium\Application\UseCases\Ordering\PlaceOrderService;
use Terrarium\Application\UseCases\Payment\PaymentCallbackService;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Gateways\PaymentGatewayFactory;
use Terrarium\Infrastructure\Gateways\TestGateway;
use Terrarium\Infrastructure\Http\HttpClient;
use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Http\Router;
use Terrarium\Infrastructure\Logging\Logger;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Migrator;
use Terrarium\Infrastructure\Persistence\Repositories\CatalogRepository;
use Terrarium\Infrastructure\Persistence\Repositories\OrderRepository;
use Terrarium\Infrastructure\Persistence\Repositories\OtpRepository;
use Terrarium\Infrastructure\Persistence\Repositories\PaymentRepository;
use Terrarium\Infrastructure\Persistence\Repositories\TokenRepository;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;
use Terrarium\Infrastructure\SMS\KavenegarSmsService;
use Terrarium\Infrastructure\SMS\LogSmsService;
use Terrarium\Infrastructure\SMS\SmsServiceInterface;
use Terrarium\Infrastructure\SMS\BaleOtpService;
use Terrarium\Infrastructure\Bale\BaleBotClient;
use Terrarium\Infrastructure\Bale\SafirClient;
use Terrarium\Infrastructure\Gateways\BaleGateway;
use Terrarium\Infrastructure\Persistence\Repositories\BaleChatRepository;
use Terrarium\Application\UseCases\Bale\BaleBotService;
use Terrarium\Support\Config;
use Throwable;

/**
 * Composition root: lazily builds services and handles HTTP requests.
 */
final class Application
{
    /** @var array<string, object> */
    private array $services = [];

    public function __construct(
        public readonly string $basePath,
        public readonly Config $config
    ) {
        date_default_timezone_set((string) $config->get('app.timezone', 'Asia/Tehran'));
    }

    public function isProduction(): bool
    {
        return $this->config->get('app.env') === 'production';
    }

    public function debug(): bool
    {
        return (bool) $this->config->get('app.debug') && !$this->isProduction();
    }

    // ------------------------------------------------------------------ services

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        return $this->services[$id] ??= $this->build($id);
    }

    private function build(string $id): object
    {
        $c = $this->config;
        return match ($id) {
            Logger::class => new Logger($this->basePath . '/storage/logs', (string) $c->get('app.log_level', 'warning')),
            Database::class => new Database((array) $c->get('database')),
            Migrator::class => new Migrator($this->get(Database::class), $this->basePath . '/database/migrations'),
            HttpClient::class => new HttpClient(),
            CatalogRepository::class => new CatalogRepository($this->get(Database::class), (string) $c->get('app.currency', 'IRR')),
            UserRepository::class => new UserRepository($this->get(Database::class)),
            OtpRepository::class => new OtpRepository($this->get(Database::class)),
            TokenRepository::class => new TokenRepository($this->get(Database::class)),
            OrderRepository::class => new OrderRepository($this->get(Database::class)),
            PaymentRepository::class => new PaymentRepository($this->get(Database::class)),
            SmsServiceInterface::class => $this->buildSms(),
            OtpService::class => new OtpService(
                $this->get(SmsServiceInterface::class),
                $this->get(OtpRepository::class),
                $this->get(UserRepository::class),
                $this->get(TokenRepository::class),
                (array) $c->get('sms.otp'),
                (int) $c->get('app.token_ttl_days', 30),
                (array) $c->get('app.admin_mobiles', [])
            ),
            ValidateConfigurationUseCase::class => new ValidateConfigurationUseCase(
                $this->get(CatalogRepository::class),
                shippingFlatRate: (int) $c->get('app.shipping_flat_rate', 0)
            ),
            PlaceOrderService::class => new PlaceOrderService(
                $this->get(Database::class),
                $this->get(ValidateConfigurationUseCase::class),
                $this->get(OrderRepository::class),
                $this->get(PaymentRepository::class),
                fn (string $name) => $this->gateway($name),
                (string) $c->get('app.url'),
                $this->get(Logger::class)
            ),
            PaymentCallbackService::class => new PaymentCallbackService(
                $this->get(Database::class),
                $this->get(OrderRepository::class),
                $this->get(PaymentRepository::class),
                $this->get(CatalogRepository::class),
                $this->get(Logger::class)
            ),
            AdminService::class => new AdminService(
                $this->get(Database::class),
                $this->get(CatalogRepository::class),
                $this->get(OrderRepository::class),
                $this->get(PaymentRepository::class),
                $this->get(UserRepository::class)
            ),
            BaleBotClient::class => new BaleBotClient((string) $c->get('bale.bot_token', ''), $this->get(HttpClient::class), (string) $c->get('bale.api_base', 'https://tapi.bale.ai')),
            SafirClient::class => new SafirClient((string) $c->get('bale.safir_api_key', ''), (int) $c->get('bale.bot_id', 0), new HttpClient(15), (string) $c->get('bale.safir_url', 'https://safir.bale.ai/api/v3/send_message')),
            BaleChatRepository::class => new BaleChatRepository($this->get(Database::class)),
            BaleGateway::class => new BaleGateway(
                $this->get(BaleBotClient::class),
                $this->get(SafirClient::class),
                (string) $c->get('bale.bot_username', ''),
                (string) $c->get('bale.wallet_token', ''),
                $this->get(Logger::class),
                $this->basePath . '/storage/bale_bot.json'
            ),
            BaleBotService::class => new BaleBotService(
                $this->get(BaleBotClient::class),
                $this->get(BaleGateway::class),
                $this->get(BaleChatRepository::class),
                $this->get(UserRepository::class),
                $this->get(OrderRepository::class),
                $this->get(PaymentRepository::class),
                $this->get(PaymentCallbackService::class),
                $this->get(Database::class),
                rtrim((string) $c->get('app.url'), '/'),
                $this->get(Logger::class)
            ),
            default => throw new InvalidArgumentException("Unknown service {$id}"),
        };
    }

    private function buildSms(): SmsServiceInterface
    {
        if ($this->config->get('bale.otp_enabled') && $this->get(SafirClient::class)->isConfigured()) {
            // Login code only through the Bale bot (Safir), no SMS.
            return new BaleOtpService($this->get(SafirClient::class), $this->get(Logger::class));
        }
        return $this->buildSmsProvider();
    }

    private function buildSmsProvider(): SmsServiceInterface
    {
        $provider = (string) $this->config->get('sms.default', 'log');
        if ($provider === 'kavenegar') {
            return new KavenegarSmsService(
                (string) $this->config->get('sms.api_key', ''),
                (string) $this->config->get('sms.sender', ''),
                (string) $this->config->get('sms.otp_template', ''),
                $this->get(HttpClient::class),
                $this->get(Logger::class)
            );
        }
        return new LogSmsService($this->get(Logger::class));
    }

    /** Gateways customers may choose at checkout (configured + enabled). */
    public function availableGateways(): array
    {
        $names = array_values(array_intersect((array) $this->config->get('payment.enabled', []), [...PaymentGatewayFactory::SUPPORTED, 'bale']));
        if (!$this->isProduction() && in_array('test', (array) $this->config->get('payment.enabled', []), true)) {
            $names[] = 'test';
        }
        $out = [];
        foreach (array_unique($names) as $n) {
            if ($this->gatewayInstance($n)->isConfigured()) {
                $out[] = $n;
            }
        }
        return $out;
    }

    public function gatewayInstance(string $name): PaymentGatewayInterface
    {
        if ($name === 'bale') {
            return $this->get(BaleGateway::class);
        }
        if ($name === 'test') {
            if ($this->isProduction()) {
                throw new InvalidArgumentException('Test gateway is disabled in production.');
            }
            return new TestGateway((string) $this->config->get('app.url'), $this->appSecret());
        }
        return (new PaymentGatewayFactory($this->get(HttpClient::class)))->create($name, (array) $this->config->get('payment'));
    }

    /** Resolves a gateway for checkout; must be enabled and configured. */
    public function gateway(string $name): PaymentGatewayInterface
    {
        if (!in_array($name, $this->availableGateways(), true)) {
            throw new ValidationException('درگاه پرداخت انتخاب‌شده در دسترس نیست.', ['field' => 'gateway']);
        }
        return $this->gatewayInstance($name);
    }

    /** Secret path segment of the Bale webhook URL (Bale sends no signature header). */
    public function baleWebhookSecret(): string
    {
        $s = (string) $this->config->get('bale.webhook_secret', '');
        return $s !== '' ? $s : self::deriveBaleSecret($this->appSecret());
    }

    /** Also used by the installer, which runs before the new .env is loaded. */
    public static function deriveBaleSecret(string $appSecret): string
    {
        return substr(hash_hmac('sha256', 'bale-webhook', $appSecret), 0, 40);
    }

    public function baleWebhookUrl(): string
    {
        return rtrim((string) $this->config->get('app.url'), '/') . '/api/v1/bale/webhook/' . $this->baleWebhookSecret();
    }

    public function appSecret(): string
    {
        $key = (string) env('APP_KEY', '');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if (strlen($key) < 16) {
            if ($this->isProduction()) {
                throw new \RuntimeException('APP_KEY is missing or too short. Run: php bin/console key:generate');
            }
            $key = 'insecure-development-key-' . $this->basePath;
        }
        return $key;
    }

    // ------------------------------------------------------------------ HTTP

    public function handle(Request $request, Router $router): Response
    {
        $logger = $this->get(Logger::class);
        try {
            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->error($e->status, $e->getMessage(), $e->errorCode ?: 'error', $e->details);
            if ($e->status === 429 && isset($e->details['retry_after'])) {
                $response->withHeader('Retry-After', (string) $e->details['retry_after']);
            }
        } catch (ValidationException $e) {
            $response = $this->error(422, $e->getMessage(), 'validation_failed', $e->details);
        } catch (NotFoundException $e) {
            $response = $this->error(404, $e->getMessage(), 'not_found');
        } catch (TooManyRequestsException $e) {
            $response = $this->error(429, $e->getMessage(), 'too_many_requests', ['retry_after' => $e->retryAfter])
                ->withHeader('Retry-After', (string) $e->retryAfter);
        } catch (PaymentUnavailableException $e) {
            $response = $this->error(502, $e->getMessage(), 'payment_gateway_unavailable', ['order_id' => $e->orderId]);
        } catch (PDOException $e) {
            $logger->error('Database error', ['exception' => $e, 'path' => $request->path]);
            $response = $this->error(503, 'سرویس پایگاه داده در دسترس نیست. لطفاً بعداً تلاش کنید.', 'database_unavailable', $this->debugDetails($e));
        } catch (Throwable $e) {
            $logger->error('Unhandled exception', ['exception' => $e, 'path' => $request->path]);
            $message = $e instanceof \RuntimeException && preg_match('/\p{Arabic}/u', $e->getMessage())
                ? $e->getMessage()
                : 'خطای داخلی سرور رخ داد.';
            $response = $this->error(500, $message, 'server_error', $this->debugDetails($e));
        }

        return $this->withSecurityHeaders($request, $response);
    }

    private function error(int $status, string $message, string $code, array $details = []): Response
    {
        return Response::json(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $status);
    }

    private function debugDetails(Throwable $e): array
    {
        return $this->debug() ? ['exception' => get_class($e), 'message' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()] : [];
    }

    private function withSecurityHeaders(Request $request, Response $response): Response
    {
        $response->withHeader('X-Content-Type-Options', 'nosniff');
        if (str_starts_with($request->path, '/api/') || $request->path === '/health') {
            $response->withHeader('Cache-Control', 'no-store');
        }
        $origin = $request->header('origin');
        $allowed = (array) $this->config->get('app.cors_origins', []);
        if ($origin !== null && in_array($origin, $allowed, true)) {
            $response->withHeader('Access-Control-Allow-Origin', $origin);
            $response->withHeader('Vary', 'Origin');
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        }
        return $response;
    }
}
