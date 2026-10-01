<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Admin;

use Terrarium\Application\DTO\ValidateConfigurationInput;
use Terrarium\Application\Exceptions\NotFoundException;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Application\UseCases\Configurator\ValidateConfigurationUseCase;
use Terrarium\Infrastructure\Persistence\Repositories\PresetRepository;

final class PresetService
{
    public function __construct(
        private readonly PresetRepository $presets,
        private readonly ValidateConfigurationUseCase $validator,
        private readonly string $uploadDir
    ) {}

    /** @param array<string, mixed> $in */
    public function save(?string $id, array $in): array
    {
        if ($id !== null && $this->presets->find($id) === null) {
            throw new NotFoundException('تراریوم آماده یافت نشد.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new ValidationException('نام تراریوم را وارد کنید (حداکثر ۱۵۰ حرف).', ['field' => 'name']);
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 1000) {
            throw new ValidationException('توضیحات بیش از حد طولانی است.', ['field' => 'description']);
        }
        $img = trim((string) ($in['image_url'] ?? ''));
        $img = $img === '' ? null : AdminService::safeImageUrl($img);
        $cfg = is_array($in['configuration'] ?? null) ? $in['configuration'] : [];
        $dto = ValidateConfigurationInput::fromArray($cfg);
        $active = filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        // A preset must be buildable; inactive drafts may be saved with warnings.
        $res = $this->validator->execute($dto);
        if ($active && !$res['is_valid']) {
            $msgs = array_map(fn ($v) => $v->message ?? (string) $v, $res['violations']);
            throw new ValidationException('این ترکیب سازگار نیست: ' . implode(' | ', array_slice($msgs, 0, 3)) . ' (برای ذخیره پیش‌نویس، «فعال» را خاموش کنید)', ['violations' => $msgs]);
        }

        $norm = fn (array $items) => array_values(array_map(fn ($x) => ['id' => $x['id'], 'quantity' => $x['quantity']], $items));
        $config = ['glass_size_id' => $dto->glassSizeId, 'plants' => $norm($dto->plants), 'stones' => $norm($dto->stones), 'figures' => $norm($dto->figures)];

        $id = $this->presets->save($id, [
            'name' => $name, 'description' => $desc !== '' ? $desc : null, 'image_url' => $img,
            'configuration' => $config, 'sort_order' => (int) ($in['sort_order'] ?? 0), 'is_active' => $active,
        ]);
        return (array) $this->presets->find($id);
    }

    public function delete(string $id): void
    {
        if (!$this->presets->delete($id)) {
            throw new NotFoundException('تراریوم آماده یافت نشد.');
        }
    }

    /**
     * Saves an uploaded image sent as a data URL (JSON body; no multipart needed).
     * The image is decoded and checked with getimagesizefromstring; the file name is random.
     */
    public function upload(string $dataUrl): string
    {
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            throw new ValidationException('فقط تصویر JPG، PNG یا WEBP قابل قبول است.', ['field' => 'image']);
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 4 * 1024 * 1024) {
            throw new ValidationException('حجم تصویر حداکثر ۴ مگابایت است.', ['field' => 'image']);
        }
        $info = @getimagesizefromstring($bin);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            throw new ValidationException('فایل ارسالی تصویر معتبری نیست.', ['field' => 'image']);
        }
        $dir = $this->uploadDir . '/' . gmdate('Y-m');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('پوشه public/uploads قابل نوشتن نیست. به IIS_IUSRS دسترسی Modify بدهید.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $bin) === false) {
            throw new \RuntimeException('ذخیره تصویر ممکن نشد. دسترسی نوشتن public/uploads را بررسی کنید.');
        }
        return '/uploads/' . gmdate('Y-m') . '/' . $name;
    }
}
