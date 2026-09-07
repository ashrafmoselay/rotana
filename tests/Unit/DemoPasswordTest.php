<?php

namespace Tests\Unit;

use App\Support\DemoPassword;
use Tests\TestCase;

class DemoPasswordTest extends TestCase
{
    public function test_it_prefers_non_empty_configured_value(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'demo-password-');
        file_put_contents($path, "DEMO_PASSWORD=FromEnv123456\n");

        $this->assertSame('Configured-Password-2026', DemoPassword::resolve('Configured-Password-2026', $path));

        @unlink($path);
    }

    public function test_it_falls_back_to_dotenv_when_configured_value_is_blank(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'demo-password-');
        file_put_contents($path, "APP_ENV=local\nDEMO_PASSWORD=FromEnv123456\n");

        $this->assertSame('FromEnv123456', DemoPassword::resolve('', $path));

        @unlink($path);
    }

    public function test_it_supports_quoted_dotenv_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'demo-password-');
        file_put_contents($path, "DEMO_PASSWORD=\"QuotedPass123!\"\n");

        $this->assertSame('QuotedPass123!', DemoPassword::resolve('', $path));

        @unlink($path);
    }
}
