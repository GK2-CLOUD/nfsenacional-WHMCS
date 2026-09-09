<?php

namespace GK2\NfseNacional\Tests\Unit\Fiscal;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Fiscal\NacionalProvider;
use GK2\NfseNacional\Fiscal\NotaControl\NotaControlProvider;
use GK2\NfseNacional\Fiscal\ProviderFactory;
use GK2\NfseNacional\Tests\Support\FakeModuleConfig;
use PHPUnit\Framework\TestCase;

/**
 * Testes da fábrica de provedores fiscais (Etapa 7).
 */
final class ProviderFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        AmbienteGuard::reset();
    }

    public function testDefaultRegistryContemProvidersNativos(): void
    {
        $registry = ProviderFactory::defaultRegistry();

        $this->assertSame(NacionalProvider::class, $registry->classFor('sefin'));
        $this->assertSame(NotaControlProvider::class, $registry->classFor('notacontrol'));
    }

    public function testCreateComProvedorSefin(): void
    {
        $config = new FakeModuleConfig(['provedor' => 'sefin', 'ambiente' => 'homologacao']);
        $guard = new AmbienteGuard($config);

        $factory = new ProviderFactory(null, $config, $guard);

        $this->assertInstanceOf(NacionalProvider::class, $factory->create());
    }

    public function testCreateComProvedorNotaControl(): void
    {
        $config = new FakeModuleConfig(['provedor' => 'notacontrol', 'ambiente' => 'homologacao']);
        $guard = new AmbienteGuard($config);

        $factory = new ProviderFactory(null, $config, $guard);

        $this->assertInstanceOf(NotaControlProvider::class, $factory->create());
    }

    public function testCreateComProvedorDesconhecidoLancaInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $config = new FakeModuleConfig(['provedor' => 'desconhecido', 'ambiente' => 'homologacao']);
        $guard = new AmbienteGuard($config);

        (new ProviderFactory(null, $config, $guard))->create();
    }
}
