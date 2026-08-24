<?php

namespace GK2\NfseNacional\Domain\Entity;

/**
 * Value object que representa uma DPS (Declaracao de Prestacao de Servicos)
 * antes de ser enviada a API Nacional.
 *
 * Contem todos os dados necessarios para montar o payload de emissao.
 */
class Dps
{
    public function __construct(
        public readonly int $numero,
        public readonly string $serie,
        public readonly string $competencia,
        public readonly array $prestador,
        public readonly array $tomador,
        public readonly array $servico,
        public readonly array $valores,
        public readonly array $tributos,
        public readonly ?int $invoiceId = null,
    ) {
    }

    /**
     * Cria uma DPS a partir de um array de dados.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            numero: (int) ($data['numero'] ?? 0),
            serie: $data['serie'] ?? 'DPS',
            competencia: $data['competencia'] ?? date('Y-m-d'),
            prestador: $data['prestador'] ?? [],
            tomador: $data['tomador'] ?? [],
            servico: $data['servico'] ?? [],
            valores: $data['valores'] ?? [],
            tributos: $data['tributos'] ?? [],
            invoiceId: $data['invoice_id'] ?? null,
        );
    }

    /**
     * Converte para array.
     */
    public function toArray(): array
    {
        return [
            'numero' => $this->numero,
            'serie' => $this->serie,
            'competencia' => $this->competencia,
            'prestador' => $this->prestador,
            'tomador' => $this->tomador,
            'servico' => $this->servico,
            'valores' => $this->valores,
            'tributos' => $this->tributos,
            'invoice_id' => $this->invoiceId,
        ];
    }

    /**
     * Retorna o valor total dos servicos.
     */
    public function getValorServicos(): float
    {
        return (float) ($this->valores['valor_servicos'] ?? 0.0);
    }
}
