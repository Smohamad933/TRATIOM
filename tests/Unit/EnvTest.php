<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Terrarium\Support\Env;

final class EnvTest extends TestCase
{
    public function test_parses_types_quotes_and_persian_text_without_corruption(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        // «م» is encoded as D9 85 — byte 0x85 must not be treated as a line break.
        file_put_contents($file, "# comment\nT_NAME=\"فروشگاه تراریوم\"\nT_BOOL=true\nT_NULL=null\nT_NUM=42 # inline\nT_SINGLE='a b'\n");
        Env::load($file);
        unlink($file);

        $this->assertSame('فروشگاه تراریوم', Env::get('T_NAME'));
        $this->assertTrue(Env::get('T_BOOL'));
        $this->assertSame(null, Env::get('T_NULL', 'x'));
        $this->assertSame('a b', Env::get('T_SINGLE'));
        $this->assertSame('fallback', Env::get('T_MISSING', 'fallback'));
    }
}
