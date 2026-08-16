<?php

namespace App\Http\Requests;

trait AllowsPartialDrafts
{
    /**
     * Convierte las reglas 'required' en 'nullable' cuando se trata de un autoguardado (borrador).
     * Esto permite guardar parcialmente un formulario sin romper las validaciones estrictas
     * que solo deben aplicarse al momento del envío definitivo.
     *
     * @param array<string, mixed> $rules
     * @param array<int, string> $except Campos que siempre deben ser requeridos (ej. lock_version)
     * @return array<string, mixed>
     */
    protected function applyDraftRules(array $rules, array $except = []): array
    {
        if (! $this->header('X-Autosave') && ! $this->boolean('is_draft_autosave')) {
            return $rules;
        }

        foreach ($rules as $field => &$fieldRules) {
            if (in_array($field, $except, true)) {
                continue;
            }

            if (is_array($fieldRules)) {
                $fieldRules = array_filter($fieldRules, fn($rule) => $rule !== 'required');
                if (!in_array('nullable', $fieldRules)) {
                    array_unshift($fieldRules, 'nullable');
                }
            } elseif (is_string($fieldRules)) {
                $stringRules = explode('|', $fieldRules);
                $stringRules = array_filter($stringRules, fn($rule) => $rule !== 'required');
                if (!in_array('nullable', $stringRules)) {
                    array_unshift($stringRules, 'nullable');
                }
                $fieldRules = implode('|', $stringRules);
            }
        }

        return $rules;
    }
}
