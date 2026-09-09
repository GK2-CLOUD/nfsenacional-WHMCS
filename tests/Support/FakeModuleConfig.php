<?php

namespace GK2\NfseNacional\Tests\Support;

use GK2\NfseNacional\Config\ModuleConfig;

/**
 * Config de teste que não depende do Capsule (WHMCS).
 *
 * Substitui getAll() para retornar um array fixo, permitindo testar
 * getProvedor()/getAmbiente() sem o runtime WHMCS.
 */
final class FakeModuleConfig extends ModuleConfig
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function getAll(): array
    {
        return $this->values;
    }
}
