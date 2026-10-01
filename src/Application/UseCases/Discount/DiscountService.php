<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Discount;

use Terrarium\Application\Exceptions\NotFoundException;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Infrastructure\Persistence\Database;

/**
 * Discount codes.
 *  - type "percent" (value = 1..100, optional cap max_discount_cents) or "fixed" (value = Rial amount)
 *  - max_uses: total uses across all accounts (NULL = unlimited)
 *  - per_user_limit: uses per account (NULL = unlimited)
 *  - allowed_mobiles: if set, only these accounts (mobiles) may use the code
 *  - min_order_cents, starts_at, expires_at, is_active
 * A use is counted when an order is placed with the code; uses on cancelled/failed orders are released.
 * The discount applies to the items subtotal (not shipping). The payable amount never drops below MIN_PAYABLE.
 */
final class DiscountService
{
    public const MIN_PAYABLE = 10000; // Rial — payment gateways reject zero amounts
    private const RELEASED = "('cancelled','failed')";

    public function __construct(private readonly Database $db) {}

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim(strtr($code, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])));
        return preg_replace('/\s+/', '', $code) ?? '';
    }

    /**
     * @param array<string,mixed> $user
     * @return array{id:string, code:string, discount_cents:int, label:string}
     */
    public function evaluate(string $code, array $user, int $subtotalCents, int $shippingCents = 0): array
    {
        $code = self::normalizeCode($code);
        $row = $code === '' ? null : $this->db->first('SELECT * FROM discount_codes WHERE code = :c', ['c' => $code]);
        if ($row === null || !(int) $row['is_active']) {
            throw new ValidationException('کد تخفیف معتبر نیست.', ['field' => 'discount_code']);
        }
        $now = Database::now();
        if (!empty($row['starts_at']) && $now < $row['starts_at']) {
            throw new ValidationException('زمان استفاده از این کد تخفیف هنوز شروع نشده است.', ['field' => 'discount_code']);
        }
        if (!empty($row['expires_at']) && $now > $row['expires_at']) {
            throw new ValidationException('مهلت استفاده از این کد تخفیف تمام شده است.', ['field' => 'discount_code']);
        }
        $allowed = self::mobiles((string) ($row['allowed_mobiles'] ?? ''));
        if ($allowed && !in_array(self::mobileOf((string) ($user['mobile'] ?? '')), $allowed, true)) {
            throw new ValidationException('این کد تخفیف برای حساب شما فعال نیست.', ['field' => 'discount_code']);
        }
        if ($subtotalCents < (int) $row['min_order_cents']) {
            throw new ValidationException('حداقل مبلغ سفارش برای این کد ' . number_format(intdiv((int) $row['min_order_cents'], 10)) . ' تومان است.', ['field' => 'discount_code']);
        }
        if ($row['max_uses'] !== null && $this->uses((string) $row['id']) >= (int) $row['max_uses']) {
            throw new ValidationException('ظرفیت استفاده از این کد تخفیف تمام شده است.', ['field' => 'discount_code']);
        }
        if ($row['per_user_limit'] !== null && $this->uses((string) $row['id'], (string) $user['id']) >= (int) $row['per_user_limit']) {
            throw new ValidationException('شما قبلاً از این کد تخفیف به تعداد مجاز استفاده کرده‌اید.', ['field' => 'discount_code']);
        }

        if ($row['type'] === 'percent') {
            $amount = intdiv($subtotalCents * (int) $row['value'], 100);
            if ($row['max_discount_cents'] !== null) $amount = min($amount, (int) $row['max_discount_cents']);
            $label = self::fa((int) $row['value']) . '٪ تخفیف';
        } else {
            $amount = (int) $row['value'];
            $label = number_format(intdiv((int) $row['value'], 10)) . ' تومان تخفیف';
        }
        $amount = max(0, min($amount, $subtotalCents + $shippingCents - self::MIN_PAYABLE, $subtotalCents));
        return ['id' => (string) $row['id'], 'code' => $code, 'discount_cents' => $amount, 'label' => $label];
    }

    public function redeem(string $codeId, string $userId, string $orderId, int $amount): void
    {
        $this->db->insert('discount_redemptions', [
            'id' => Database::uuid(), 'discount_code_id' => $codeId, 'user_id' => $userId,
            'order_id' => $orderId, 'amount_cents' => $amount, 'created_at' => Database::now(),
        ]);
    }

    private function uses(string $codeId, ?string $userId = null): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM discount_redemptions r JOIN orders o ON o.id = r.order_id
                WHERE r.discount_code_id = :c AND o.status NOT IN ' . self::RELEASED;
        $p = ['c' => $codeId];
        if ($userId !== null) { $sql .= ' AND r.user_id = :u'; $p['u'] = $userId; }
        return (int) ($this->db->first($sql, $p)['n'] ?? 0);
    }

    // ------------------------------------------------------------------ admin

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->all('SELECT d.*, (SELECT COUNT(*) FROM discount_redemptions r JOIN orders o ON o.id = r.order_id
                WHERE r.discount_code_id = d.id AND o.status NOT IN ' . self::RELEASED . ') AS used_count,
                (SELECT COALESCE(SUM(r.amount_cents),0) FROM discount_redemptions r JOIN orders o ON o.id = r.order_id
                WHERE r.discount_code_id = d.id AND o.status IN (\'paid\',\'processing\',\'completed\')) AS paid_discount_cents
                FROM discount_codes d ORDER BY d.created_at DESC');
        return array_map([$this, 'present'], $rows);
    }

    /** @param array<string,mixed> $in */
    public function save(?string $id, array $in): array
    {
        $code = self::normalizeCode((string) ($in['code'] ?? ''));
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            throw new ValidationException('کد باید ۳ تا ۴۰ کاراکتر و فقط حروف انگلیسی، عدد، - یا _ باشد.', ['field' => 'code']);
        }
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, ['percent', 'fixed'], true)) throw new ValidationException('نوع تخفیف نامعتبر است.', ['field' => 'type']);
        $value = self::int($in['value'] ?? null, 'value', true);
        if ($type === 'percent' && ($value < 1 || $value > 100)) throw new ValidationException('درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.', ['field' => 'value']);
        if ($type === 'fixed' && $value < 1000) throw new ValidationException('مبلغ تخفیف باید حداقل ۱٬۰۰۰ ریال باشد.', ['field' => 'value']);

        $mobiles = self::mobiles((string) ($in['allowed_mobiles'] ?? ''), true);
        $data = [
            'code' => $code, 'type' => $type, 'value' => $value,
            'max_discount_cents' => $type === 'percent' ? self::int($in['max_discount_cents'] ?? null, 'max_discount_cents') : null,
            'min_order_cents' => self::int($in['min_order_cents'] ?? null, 'min_order_cents') ?? 0,
            'max_uses' => self::int($in['max_uses'] ?? null, 'max_uses'),
            'per_user_limit' => self::int($in['per_user_limit'] ?? null, 'per_user_limit'),
            'allowed_mobiles' => $mobiles ? implode(',', $mobiles) : null,
            'starts_at' => self::date($in['starts_at'] ?? null, 'starts_at'),
            'expires_at' => self::date($in['expires_at'] ?? null, 'expires_at', true),
            'is_active' => filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null,
            'updated_at' => Database::now(),
        ];
        if ($data['starts_at'] && $data['expires_at'] && $data['starts_at'] > $data['expires_at']) {
            throw new ValidationException('تاریخ پایان باید بعد از تاریخ شروع باشد.', ['field' => 'expires_at']);
        }
        $dup = $this->db->first('SELECT id FROM discount_codes WHERE code = :c', ['c' => $code]);
        if ($dup && $dup['id'] !== $id) throw new ValidationException('این کد قبلاً ثبت شده است.', ['field' => 'code']);

        if ($id === null) {
            $id = Database::uuid();
            $this->db->insert('discount_codes', $data + ['id' => $id, 'created_at' => Database::now()]);
        } else {
            if (!$this->db->first('SELECT id FROM discount_codes WHERE id = :id', ['id' => $id])) throw new NotFoundException('کد تخفیف یافت نشد.');
            $set = implode(', ', array_map(fn ($k) => "{$k} = :{$k}", array_keys($data)));
            $this->db->query("UPDATE discount_codes SET {$set} WHERE id = :id", $data + ['id' => $id]);
        }
        foreach ($this->list() as $r) if ($r['id'] === $id) return $r;
        throw new NotFoundException('کد تخفیف یافت نشد.');
    }

    /** Used codes are deactivated (history is kept); unused codes are deleted. */
    public function delete(string $id): string
    {
        $used = (int) ($this->db->first('SELECT COUNT(*) AS n FROM discount_redemptions WHERE discount_code_id = :id', ['id' => $id])['n'] ?? 0);
        if ($used > 0) {
            $this->db->query('UPDATE discount_codes SET is_active = 0, updated_at = :now WHERE id = :id', ['id' => $id, 'now' => Database::now()]);
            return 'deactivated';
        }
        $this->db->query('DELETE FROM discount_codes WHERE id = :id', ['id' => $id]);
        return 'deleted';
    }

    // ------------------------------------------------------------------ helpers

    private function present(array $r): array
    {
        foreach (['value', 'min_order_cents', 'is_active', 'used_count', 'paid_discount_cents'] as $k) $r[$k] = (int) $r[$k];
        foreach (['max_discount_cents', 'max_uses', 'per_user_limit'] as $k) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        $r['allowed_mobiles'] = self::mobiles((string) ($r['allowed_mobiles'] ?? ''));
        $r['is_active'] = (bool) $r['is_active'];
        foreach (['starts_at', 'expires_at'] as $k) {
            $r[$k . '_local'] = empty($r[$k]) ? null : (new \DateTimeImmutable($r[$k], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
        }
        return $r;
    }

    private static function mobileOf(string $m): string
    {
        try { return OtpService::normalizeMobile($m); } catch (\Throwable) { return ''; }
    }

    /** @return list<string> */
    private static function mobiles(string $raw, bool $strict = false): array
    {
        $out = [];
        foreach (preg_split('/[\s,،;]+/u', $raw) ?: [] as $m) {
            if (trim($m) === '') continue;
            $n = self::mobileOf($m);
            if ($n === '') {
                if ($strict) throw new ValidationException("شماره «{$m}» معتبر نیست.", ['field' => 'allowed_mobiles']);
                continue;
            }
            $out[$n] = $n;
        }
        if ($strict && count($out) > 5000) throw new ValidationException('حداکثر ۵۰۰۰ شماره مجاز است.', ['field' => 'allowed_mobiles']);
        return array_values($out);
    }

    private static function int(mixed $v, string $f, bool $required = false): ?int
    {
        if ($v === null || $v === '') {
            if ($required) throw new ValidationException('مقدار تخفیف الزامی است.', ['field' => $f]);
            return null;
        }
        $v = strtr((string) $v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '', '٬' => '']);
        if (!preg_match('/^\d{1,13}$/', $v)) throw new ValidationException('مقدار باید عدد صحیح مثبت باشد.', ['field' => $f]);
        return (int) $v;
    }

    private static function date(mixed $v, string $f, bool $endOfDay = false): ?string
    {
        if ($v === null || trim((string) $v) === '') return null;
        $v = trim((string) $v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        $v = str_replace('T', ' ', $v);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';
        // admin enters local (Tehran) time; stored as UTC like every other timestamp
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, new \DateTimeZone('Asia/Tehran'));
        if (!$dt) throw new ValidationException('تاریخ نامعتبر است.', ['field' => $f]);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function fa(int $n): string
    {
        return strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}
