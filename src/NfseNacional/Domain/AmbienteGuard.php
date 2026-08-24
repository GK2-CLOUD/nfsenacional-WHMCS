<?php

namespace GK2\NfseNacional\Domain;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\Enum\Ambiente;

/**
 * Trava central de ambiente do modulo NFS-e Nacional.
 *
 * Garante que TODAS as operacoes do modulo respeitem o ambiente
 * configurado no addon. Uma vez resolvido, o ambiente e imutavel
 * durante o ciclo de vida da requisicao.
 *
 * Regras:
 * - Producao e homologacao NUNCA se misturam.
 * - Notas emitidas em homologacao so podem ser consultadas/canceladas em homologacao.
 * - Notas emitidas em producao so podem ser consultadas/canceladas em producao.
 * - O cron so processa pendentes do ambiente ativo.
 * - A numeracao DPS e isolada por ambiente.
 */
class AmbienteGuard
{
    private Ambiente $ambiente;

    private static ?self $instance = null;

    public function __construct(?ModuleConfig $config = null)
    {
        $config = $config ?? new ModuleConfig();
        $this->ambiente = $config->getAmbiente();
    }

    /**
     * Singleton para garantir consistencia dentro da mesma requisicao.
     */
    public static function getInstance(?ModuleConfig $config = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Reseta a instancia (util para testes).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ─── Leitura ─────────────────────────────────────────────────

    /**
     * Retorna o ambiente ativo (imutavel durante a requisicao).
     */
    public function getAmbiente(): Ambiente
    {
        return $this->ambiente;
    }

    public function isProducao(): bool
    {
        return $this->ambiente === Ambiente::PRODUCAO;
    }

    public function isHomologacao(): bool
    {
        return $this->ambiente === Ambiente::HOMOLOGACAO;
    }

    /**
     * Retorna o valor string do ambiente para uso em queries e persistencia.
     */
    public function value(): string
    {
        return $this->ambiente->value;
    }

    /**
     * Retorna label amigavel (para logs e UI).
     */
    public function label(): string
    {
        return $this->ambiente->label();
    }

    // ─── Validacao ───────────────────────────────────────────────

    /**
     * Valida que um registro pertence ao ambiente ativo.
     *
     * Impede que uma nota emitida em homologacao seja operada
     * quando o ambiente ativo e producao, e vice-versa.
     *
     * @param string|null $ambienteRegistro Ambiente gravado no registro (coluna `ambiente`)
     * @throws AmbienteMismatchException Se o ambiente do registro divergir do ativo
     */
    public function assertMesmoAmbiente(?string $ambienteRegistro): void
    {
        if ($ambienteRegistro === null) {
            return;
        }

        if ($ambienteRegistro !== $this->ambiente->value) {
            throw new AmbienteMismatchException(
                "Operacao bloqueada: nota emitida em [{$ambienteRegistro}] "
                . "mas o ambiente ativo e [{$this->ambiente->value}]. "
                . 'Altere o ambiente do modulo ou opere sobre notas do ambiente correto.',
            );
        }
    }

    /**
     * Valida que o ambiente fornecido coincide com o ativo.
     *
     * Uso: antes de qualquer chamada a API, para garantir que
     * o endpoint sera do ambiente correto.
     *
     * @throws AmbienteMismatchException
     */
    public function assertAmbiente(Ambiente $esperado): void
    {
        if ($esperado !== $this->ambiente) {
            throw new AmbienteMismatchException(
                "Ambiente esperado [{$esperado->value}] difere do ativo [{$this->ambiente->value}].",
            );
        }
    }
}
