<?php

namespace GK2\NfseNacional\Domain\Enum;

/**
 * Modelo de DANFS-e entregue ao cliente.
 *
 * OFICIAL — proxy do documento gerado pelo governo (adn.nfse.gov.br/danfse).
 *           Depende do ADN estar disponivel a cada download.
 *
 * LOCAL   — documento gerado pelo modulo, em TCPDF, a partir do XML da NFS-e
 *           ja armazenado em tblnfsenacional.xml_retorno. Nao faz chamada de
 *           API, e usa a logo configurada em danfse_logo.
 *
 * O padrao e OFICIAL: o modelo local so passa a valer quando explicitamente
 * escolhido, e voltar atras e trocar o dropdown — sem deploy.
 *
 * Este enum e a UNICA fonte dos rotulos do dropdown: ConfigFields monta as
 * Options a partir de opcoes(). Ja custou caro manter as duas listas em
 * paralelo — ver ModuleConfig::ensureDanfseModeloValido().
 */
enum DanfseModelo: int
{
    case OFICIAL = 1;
    case LOCAL = 2;

    public function label(): string
    {
        return match ($this) {
            self::OFICIAL => 'Oficial (governo)',
            self::LOCAL   => 'Local (gerado pelo módulo)',
        };
    }

    /**
     * Como o valor aparece nas Options e fica gravado em tbladdonmodules.
     * O WHMCS guarda a string inteira, nao so o numero.
     */
    public function rotuloConfig(): string
    {
        return $this->value . '-' . $this->label();
    }

    /** Lista pronta para o campo 'Options' do dropdown. */
    public static function opcoes(): string
    {
        return implode(',', array_map(static fn(self $m) => $m->rotuloConfig(), self::cases()));
    }

    /**
     * Indica se o PDF deve ser gerado localmente em vez de buscado no ADN.
     */
    public function isLocal(): bool
    {
        return $this === self::LOCAL;
    }
}
