<?php

namespace GK2\NfseNacional\Fiscal\Mapper;

use GK2\NfseNacional\Config\ModuleConfig;

/**
 * Mapeia itens da fatura WHMCS para a estrutura de servico
 * exigida pela API NFS-e Nacional.
 *
 * Trata:
 * - Composicao da descricao fiscal
 * - Codigo nacional do servico (NBS)
 * - Codigo municipal do servico
 * - CNAE
 * - Exclusao de Late Fees (se configurado)
 * - Personalizacao por grupo de produto (se habilitado)
 */
class ServicoMapper
{
    private ModuleConfig $config;

    public function __construct(?ModuleConfig $config = null)
    {
        $this->config = $config ?? new ModuleConfig();
    }

    /**
     * Separador entre itens da fatura na discriminacao.
     *
     * O xDescServ nao aceita quebra de linha (E999), entao a discriminacao
     * inteira e uma linha so. Mas ela tem DOIS niveis: os itens da fatura, e
     * as linhas que o WHMCS ja poe dentro da descricao de cada item (opcoes
     * configuraveis, sobretudo). Antes os dois usavam "\n" e viravam o mesmo
     * " | ", achatando a hierarquia — a opcao configuravel aparecia no mesmo
     * nivel do produto, sem como distinguir depois.
     *
     * Com dois separadores o DANFS-e GK2 remonta os niveis, e o DANFS-e do
     * governo, que mostra a linha crua, tambem fica legivel.
     *
     * Danfse\Formato::discriminacao() precisa dos mesmos dois valores para
     * desfazer isto — nao ha como importar daqui sem arrastar o WHMCS para
     * dentro da camada de DANFS-e, entao eles estao duplicados la e ha um
     * teste que compara os dois arquivos.
     */
    private const SEP_ITEM = ' • ';

    /** Separador entre as linhas de um mesmo item. */
    private const SEP_LINHA = ' | ';

    /**
     * Tipos de item sempre excluídos da NFS-e (independente de configuração).
     */
    private const TIPOS_SEMPRE_EXCLUIDOS = [
        'PaymentGateway', // tarifas de boleto, cartão, etc.
        'Credit',         // créditos negativos de item
        'Tax',            // impostos sobre a fatura
    ];

    /**
     * Mapeia os itens da fatura para a estrutura de servico.
     *
     * @param array $invoice Dados da fatura (retorno de GetInvoice)
     * @return array Dados do servico no formato da API Nacional
     */
    public function map(array $invoice): array
    {
        $items           = $invoice['items']['item'] ?? [];
        $excluirLateFee  = $this->config->isExcluirLateFee();
        $descricaoPartes = [];
        $valorTotal      = 0.0;

        foreach ($items as $item) {
            $tipo = $item['type'] ?? '';

            if (in_array($tipo, self::TIPOS_SEMPRE_EXCLUIDOS, true)) {
                continue;
            }

            // Late Fee: excluir somente se configurado
            if ($tipo === 'LateFee' && $excluirLateFee) {
                continue;
            }

            $valor = (float) ($item['amount'] ?? 0);

            if ($valor <= 0) {
                continue;
            }

            $valorTotal += $valor;

            $parte = $this->discriminarItem((string) ($item['description'] ?? ''), $valor);

            if ($parte !== '') {
                $descricaoPartes[] = $parte;
            }
        }

        // Subtrair desconto da fatura se configurado
        if ($this->config->isDescontarDesconto()) {
            $desconto = (float) ($invoice['discount'] ?? 0);
            if ($desconto > 0) {
                $valorTotal -= $desconto;
            }
        }

        // Subtrair crédito de conta aplicado se configurado
        if ($this->config->isDescontarCredito()) {
            $credito = (float) ($invoice['credit'] ?? 0);
            if ($credito > 0) {
                $valorTotal -= $credito;
            }
        }

        $valorTotal = max(0.0, $valorTotal);

        // Obter codigos fiscais globais
        $codigosFiscais = $this->getCodigosFiscais($items);

        $discriminacao = implode(self::SEP_ITEM, $descricaoPartes);
        if (empty($discriminacao)) {
            $discriminacao = 'Servicos de tecnologia - Fatura #' . ($invoice['invoiceid'] ?? '');
        }

        return [
            'codigoServicoNacional' => $codigosFiscais['codigo_servico_nacional'],
            'codigoServico' => $codigosFiscais['codigo_servico'],
            'codigoMunicipal' => $codigosFiscais['codigo_municipal'],
            'cnae' => $codigosFiscais['cnae'],
            'discriminacao' => $this->sanitizeDiscriminacao($discriminacao),
            'valorServicos' => round($valorTotal, 2),
            'codigoMunicipioIncidencia' => $this->config->getCodigoMunicipioPrestador(),
        ];
    }

    /**
     * Retorna os codigos fiscais globais configurados no addon.
     */
    private function getCodigosFiscais(array $items): array
    {
        return [
            'codigo_servico_nacional' => $this->config->getCodigoServicoNacional(),
            'codigo_servico' => $this->config->getCodigoServico(),
            'codigo_municipal' => $this->config->getCodigoMunicipal(),
            'cnae' => $this->config->getCnae(),
        ];
    }

    /**
     * Monta a discriminacao de um item: a descricao do WHMCS com o valor do
     * item colado na primeira linha, e as demais linhas atras de SEP_LINHA.
     *
     * O valor vai na primeira linha porque e ela que nomeia o produto; as
     * seguintes sao detalhe dele.
     */
    private function discriminarItem(string $descricao, float $valor): string
    {
        $linhas = preg_split('/\r\n|\r|\n/', strip_tags($descricao)) ?: [];
        $linhas = array_values(array_filter(array_map('trim', $linhas), static fn ($l) => $l !== ''));

        if ($linhas === []) {
            return '';
        }

        $linhas[0] .= ' - R$ ' . number_format($valor, 2, ',', '.');

        return implode(self::SEP_LINHA, $linhas);
    }

    /**
     * Sanitiza a discriminacao do servico para o campo fiscal.
     */
    private function sanitizeDiscriminacao(string $text): string
    {
        // Remover tags HTML
        $text = strip_tags($text);

        // Rede de seguranca: discriminarItem() ja tirou as quebras de linha.
        // Se sobrar alguma, ela e limite de linha DENTRO de um item.
        $text = str_replace(["\r\n", "\r", "\n"], self::SEP_LINHA, $text);

        // Remover caracteres de controle
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);

        // Limitar tamanho (2000 caracteres é um limite comum)
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 1997) . '...';
        }

        return trim($text);
    }
}
