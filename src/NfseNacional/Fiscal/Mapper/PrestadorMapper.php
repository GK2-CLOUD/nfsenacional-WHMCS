<?php

namespace GK2\NfseNacional\Fiscal\Mapper;

use GK2\NfseNacional\Config\ModuleConfig;

/**
 * Mapeia dados do prestador (empresa emissora) a partir das
 * configuracoes do addon.
 *
 * Retorna array com chaves usadas pelo DpsPayloadBuilder
 * para gerar os elementos XML <prest> conforme XSD v1.01:
 * - CNPJ ou CPF (choice)
 * - IM (inscricao municipal, opcional)
 * - regTrib > opSimpNac + regEspTrib (obrigatorio)
 */
class PrestadorMapper
{
    private ModuleConfig $config;

    public function __construct(?ModuleConfig $config = null)
    {
        $this->config = $config ?? new ModuleConfig();
    }

    /**
     * Retorna os dados do prestador.
     */
    public function map(): array
    {
        $opSimpNac = $this->getOpSimpNac();
        return [
            // Normaliza CNPJ do prestador: remove apenas pontuação, preserva letras se alfanumérico
            'cnpj' => (function ($v) {
                $norm = strtoupper(trim(preg_replace('/[.\\/\-\s]+/', '', (string) $v)));
                return preg_match('/[A-Z]/', $norm) ? $norm : preg_replace('/\D/', '', $v);
            })($this->config->getCnpjPrestador()),
            'inscricaoMunicipal' => trim($this->config->getInscricaoMunicipal()),
            'opSimpNac' => $opSimpNac,
            'regApTribSN' => in_array($opSimpNac, ['2', '3'], true) ? $this->config->getRegApTribSN() : '',
            'regEspTrib' => $this->config->getRegEspTrib(),
        ];
    }

    /**
     * Retorna o codigo opSimpNac baseado na configuracao.
     *
     * 1 - Não Optante
     * 2 - Optante MEI
     * 3 - Optante ME/EPP
     */
    private function getOpSimpNac(): string
    {
        $regimeTrib = $this->config->get('regime_tributario', '1');
        $regime = (int) preg_replace('/\D.*/', '', $regimeTrib);
        $optante = $this->config->isOptanteSimplesNacional();

        // regime_tributario: 1=Simples, 2=Simples Excesso, 3=Normal, 4=MEI
        if ($regime === 4) {
            return '2'; // MEI
        }
        if ($optante && in_array($regime, [1, 2], true)) {
            return '3'; // ME/EPP
        }
        return '1'; // Não Optante
    }
}
