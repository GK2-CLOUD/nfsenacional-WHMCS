<?php

namespace GK2\NfseNacional\Tests\Unit\Fiscal;

use GK2\NfseNacional\Fiscal\NacionalProvider;
use GK2\NfseNacional\Fiscal\NotaControl\NotaControlProvider;
use GK2\NfseNacional\Fiscal\ProviderInterface;
use GK2\NfseNacional\Fiscal\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Testes do registro de provedores fiscais (Etapa 7).
 */
final class ProviderRegistryTest extends TestCase
{
    public function testRegisterOk(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('sefin', NacionalProvider::class);

        $this->assertTrue($registry->has('sefin'));
    }

    public function testRegisterClasseSemInterfaceLancaInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $registry = new ProviderRegistry();
        $registry->register('invalido', \stdClass::class);
    }

    public function testHas(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('sefin', NacionalProvider::class);

        $this->assertTrue($registry->has('sefin'));
        $this->assertFalse($registry->has('desconhecido'));
    }

    public function testKeys(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('sefin', NacionalProvider::class);
        $registry->register('notacontrol', NotaControlProvider::class);

        $this->assertSame(['sefin', 'notacontrol'], $registry->keys());
    }

    public function testClassFor(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('sefin', NacionalProvider::class);

        $this->assertSame(NacionalProvider::class, $registry->classFor('sefin'));
        $this->assertTrue(is_subclass_of($registry->classFor('sefin'), ProviderInterface::class));
    }

    public function testClassForChaveDesconhecidaLancaInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProviderRegistry())->classFor('nao-existe');
    }
}
