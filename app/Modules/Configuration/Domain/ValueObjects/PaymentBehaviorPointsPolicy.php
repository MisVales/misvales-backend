<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Enums\PaymentBehavior;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use JsonSerializable;

/**
 * Política versionada de puntos por comportamiento de pago (C06/C07).
 *
 * Contiene exactamente una regla por cada comportamiento reconocido.
 * No permite duplicados ni comportamientos faltantes.
 */
final readonly class PaymentBehaviorPointsPolicy implements JsonSerializable
{
    /** @var array<string, PaymentBehaviorRule> Indexado por behavior value */
    private array $rules;

    /**
     * @param PaymentBehaviorRule[] $rules
     *
     * @throws ConfigurationException Si faltan comportamientos o hay duplicados.
     */
    public function __construct(array $rules)
    {
        $indexed = [];
        foreach ($rules as $rule) {
            if (isset($indexed[$rule->behavior->value])) {
                throw ConfigurationException::valueInvalid(
                    "La política contiene el comportamiento «{$rule->behavior->value}» duplicado."
                );
            }
            $indexed[$rule->behavior->value] = $rule;
        }

        $expected = PaymentBehavior::cases();
        foreach ($expected as $behavior) {
            if (! isset($indexed[$behavior->value])) {
                throw ConfigurationException::valueInvalid(
                    "La política no contiene una regla para el comportamiento «{$behavior->value}»."
                );
            }
        }

        if (count($indexed) !== count($expected)) {
            throw ConfigurationException::valueInvalid(
                'La política contiene comportamientos no reconocidos.'
            );
        }

        $this->rules = $indexed;
    }

    /**
     * Construye la política desde la representación JSON persistida.
     *
     * @param string $json
     *
     * @throws ConfigurationException Si el JSON no es válido o la estructura es incorrecta.
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var list<array{behavior: string, generates_points: bool, reduces_points: bool}> $data */
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigurationException::valueInvalid(
                'La política de comportamiento no es un JSON válido: ' . $e->getMessage()
            );
        }

        if (! is_array($data)) {
            throw ConfigurationException::valueInvalid(
                'La política de comportamiento debe ser un arreglo de reglas.'
            );
        }

        $rules = [];
        foreach ($data as $item) {
            if (! is_array($item)
                || ! isset($item['behavior'], $item['generates_points'], $item['reduces_points'])) {
                throw ConfigurationException::valueInvalid(
                    'Cada regla debe contener behavior, generates_points y reduces_points.'
                );
            }
            $rules[] = PaymentBehaviorRule::fromArray($item);
        }

        return new self($rules);
    }

    /**
     * Obtiene la regla para un comportamiento específico.
     */
    public function ruleFor(PaymentBehavior $behavior): PaymentBehaviorRule
    {
        return $this->rules[$behavior->value];
    }

    /**
     * @return PaymentBehaviorRule[]
     */
    public function rules(): array
    {
        return array_values($this->rules);
    }

    public function toJson(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{behavior: string, generates_points: bool, reduces_points: bool}>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn (PaymentBehaviorRule $rule): array => $rule->jsonSerialize(),
            array_values($this->rules),
        );
    }
}
