<?php

namespace GK2\NfseNacional\Domain\Enum;

/**
 * Modelo de DANFS-e entregue ao cliente.
 *
 * OFICIAL — proxy do documento gerado pelo governo (adn.nfse.gov.br/danfse).
 *           Depende do ADN estar disponivel a cada download.
 *
 * GK2     — documento gerado localmente pelo modulo, em TCPDF, a partir do
 *           XML da NFS-e ja armazenado em tblnfsenacional.xml_retorno.
 *           Nao faz chamada de API.
 *
 * O padrao e OFICIAL: o modelo GK2 so passa a valer quando explicitamente
 * escolhido, e voltar atras e trocar o dropdown — sem deploy.
 */
enum DanfseModelo: int
{
    case OFICIAL = 1;
    case GK2 = 2;

    public function label(): string
    {
        return match ($this) {
            self::OFICIAL => 'Oficial (governo)',
            self::GK2     => 'GK2',
        };
    }

    /**
     * Indica se o PDF deve ser gerado localmente em vez de buscado no ADN.
     */
    public function isLocal(): bool
    {
        return $this === self::GK2;
    }
}
