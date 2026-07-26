<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Application\Services;

use App\Modules\Configuration\Application\Services\ConfigurationValueValidator;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigurationValueValidatorTest extends TestCase
{
    private ConfigurationValueValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConfigurationValueValidator;
    }

    public function test_validates_integers_correctly(): void
    {
        // Positivo (válido)
        $this->validator->validate(ConfigurationKey::PAYMENT_DAYS_AFTER_CUT, '5');

        // Letras (inválido)
        $this->expectException(ConfigurationException::class);
        $this->validator->validate(ConfigurationKey::PAYMENT_DAYS_AFTER_CUT, 'abc');
    }

    public function test_enforces_specific_rules_for_points_multiplier(): void
    {
        // Debe ser > 0
        $this->validator->validate(ConfigurationKey::POINTS_MULTIPLIER, '1');

        $this->expectException(ConfigurationException::class);
        $this->validator->validate(ConfigurationKey::POINTS_MULTIPLIER, '0');
    }

    public function test_enforces_specific_rules_for_points_divisor(): void
    {
        // Es un money, debe ser > 0
        $this->validator->validate(ConfigurationKey::POINTS_DIVISOR_AMOUNT, '100.00');

        $this->expectException(ConfigurationException::class);
        $this->validator->validate(ConfigurationKey::POINTS_DIVISOR_AMOUNT, '0.00');
    }

    public function test_enforces_specific_rules_for_token_ttl(): void
    {
        // Máximo 5
        $this->validator->validate(ConfigurationKey::MODIFICATION_TOKEN_TTL_MINUTES, '3');
        $this->validator->validate(ConfigurationKey::MODIFICATION_TOKEN_TTL_MINUTES, '5');

        $this->expectException(ConfigurationException::class);
        $this->validator->validate(ConfigurationKey::MODIFICATION_TOKEN_TTL_MINUTES, '6');
    }

    public function test_validates_typed_objects(): void
    {
        $this->expectNotToPerformAssertions();

        $json = '{"start_offset_days": -5, "start_time": "00:00:00", "end_offset_days": 0, "end_time": "23:59:59", "timezone": "America/Monterrey"}';
        $this->validator->validate(ConfigurationKey::EARLY_PAYMENT_PERIOD, $json);
    }
}
