<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Client\Domain\Addresses\AddressNormalizer;
use App\Modules\Client\Domain\Clients\CurpNormalizer;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Portfolio\PortfolioBalance;
use PHPUnit\Framework\TestCase;

final class ClientDomainTest extends TestCase
{
    public function test_curp_normalization_is_stable_without_claiming_external_validation(): void
    {
        $normalizer = new CurpNormalizer;

        self::assertSame('ABCD900101HDFRRN09', $normalizer->normalize('  abcd900101hdfrrn09  '));
    }

    public function test_curp_rejects_internal_spaces_and_non_alphanumeric_characters(): void
    {
        $this->expectException(ClientDomainException::class);
        $this->expectExceptionMessage('forma aceptable');

        (new CurpNormalizer)->normalize('ABCD 00101HDFRRN09');
    }

    public function test_address_canonicalization_handles_case_spaces_accents_and_punctuation(): void
    {
        $normalizer = new AddressNormalizer;
        $first = $normalizer->normalize($this->address('  Avenida   Constitución,  ', 'Centro'));
        $second = $normalizer->normalize($this->address('avenida constitucion', 'CENTRO'));

        self::assertSame($first->canonical, $second->canonical);
        self::assertSame(AddressNormalizer::VERSION, $first->version);
        self::assertSame('Avenida Constitución,', $first->display['street']);
    }

    public function test_address_keeps_different_interior_numbers_distinct(): void
    {
        $normalizer = new AddressNormalizer;
        $first = $normalizer->normalize([...$this->address('Calle Uno', 'Centro'), 'interior_number' => 'A']);
        $second = $normalizer->normalize([...$this->address('Calle Uno', 'Centro'), 'interior_number' => 'B']);

        self::assertNotSame($first->fingerprintInput(), $second->fingerprintInput());
    }

    public function test_portfolio_balance_uses_four_decimal_exact_arithmetic(): void
    {
        self::assertSame('100.1000', PortfolioBalance::calculate([
            ['entry_type' => 'VOUCHER', 'amount' => '150.1250'],
            ['entry_type' => 'INSTALLMENT', 'amount' => '25.0125'],
            ['entry_type' => 'PAYMENT', 'amount' => '25.0125'],
            ['entry_type' => 'NOTE', 'amount' => null],
        ]));
    }

    /** @return array{street:string,exterior_number:string,interior_number:?string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string} */
    private function address(string $street, string $neighborhood): array
    {
        return [
            'street' => $street,
            'exterior_number' => '101',
            'interior_number' => null,
            'neighborhood' => $neighborhood,
            'postal_code' => '64000',
            'municipality' => 'Monterrey',
            'city' => 'Monterrey',
            'state' => 'Nuevo León',
        ];
    }
}
