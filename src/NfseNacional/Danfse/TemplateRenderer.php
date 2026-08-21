<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Preenche o template HTML do DANFS-e GK2.
 *
 * Ordem obrigatoria: blocos condicionais primeiro, tokens depois. Ao
 * contrario, um valor que por acaso contivesse "{{/se_homologacao}}"
 * bagunçaria o casamento do bloco.
 *
 * Todo valor e escapado como HTML. Os dados vem do XML e do cadastro do
 * WHMCS — a descricao do servico, em particular, e texto livre digitado
 * pelo operador e pode conter & ou <, que quebrariam a grade da tabela.
 * Quebras de linha viram <br> depois do escape, nunca antes.
 */
class TemplateRenderer
{
    private const ARQUIVO = 'DANFSe-GK2.html';
    private const LOGO    = 'gk2-logo.png';

    /** Marcador do src da logo — trocado pelo caminho absoluto no servidor. */
    private const PLACEHOLDER_LOGO = '__LOGO__';

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? dirname(__DIR__, 3) . '/templates/danfse', '/');
    }

    /**
     * @param array<string,string> $tokens    Saida de TokenMapper::map()
     * @param array<string,bool>   $condicoes Ex.: ['se_homologacao' => true]
     *
     * @throws \RuntimeException se sobrar token nao substituido
     */
    public function render(array $tokens, array $condicoes = []): string
    {
        $html = $this->carregarTemplate();

        foreach ($condicoes as $tag => $mostrar) {
            $html = self::aplicarCondicional($html, $tag, $mostrar);
        }

        $html = $this->resolverLogo($html);
        $html = strtr($html, $this->prepararTokens($tokens));

        $restantes = self::tokensRestantes($html);
        if ($restantes !== []) {
            throw new \RuntimeException(
                'Template do DANFS-e com token nao substituido: ' . implode(', ', $restantes)
            );
        }

        return $html;
    }

    /**
     * Remove ou revela um bloco {{#tag}}...{{/tag}}.
     *
     * O bloco oculto vira STRING VAZIA. Nunca um comentario HTML: se a
     * substituicao cair dentro de outro comentario, o "-->" fecha o
     * comentario externo e o resto do cabecalho vira texto visivel na
     * pagina. Ja aconteceu — ver guia TCPDF §4.
     */
    public static function aplicarCondicional(string $html, string $tag, bool $mostrar): string
    {
        $re = '/\{\{#' . preg_quote($tag, '/') . '\}\}(.*?)\{\{\/' . preg_quote($tag, '/') . '\}\}/s';

        return preg_replace_callback($re, fn($m) => $mostrar ? $m[1] : '', $html);
    }

    /**
     * Tokens ainda presentes no HTML, incluindo blocos condicionais que
     * ninguem tratou. Vazio significa render completo.
     *
     * @return list<string>
     */
    public static function tokensRestantes(string $html): array
    {
        preg_match_all('/\{\{[#\/]?[a-z_0-9]+\}\}/i', $html, $m);

        return array_values(array_unique($m[0]));
    }

    public function caminhoLogo(): string
    {
        return $this->dir . '/' . self::LOGO;
    }

    public function caminhoTemplate(): string
    {
        return $this->dir . '/' . self::ARQUIVO;
    }

    // ─────────────────────────────────────────────────────────────────

    private function carregarTemplate(): string
    {
        $caminho = $this->caminhoTemplate();

        if (!is_readable($caminho)) {
            throw new \RuntimeException('Template do DANFS-e nao encontrado: ' . $caminho);
        }

        $html = file_get_contents($caminho);
        if ($html === false || trim($html) === '') {
            throw new \RuntimeException('Template do DANFS-e vazio: ' . $caminho);
        }

        return $html;
    }

    /**
     * O TCPDF resolve <img> pelo sistema de arquivos, nao por URL relativa
     * a pagina — caminho relativo simplesmente nao carrega (guia §8).
     * Logo ausente nao impede a emissao do documento fiscal: some a imagem,
     * o resto sai.
     */
    private function resolverLogo(string $html): string
    {
        $logo = $this->caminhoLogo();
        $src = is_readable($logo) ? $logo : '';

        return str_replace(self::PLACEHOLDER_LOGO, htmlspecialchars($src, ENT_QUOTES, 'UTF-8'), $html);
    }

    /**
     * @param array<string,string> $tokens
     * @return array<string,string> mapa pronto para strtr
     */
    private function prepararTokens(array $tokens): array
    {
        $mapa = [];

        foreach ($tokens as $chave => $valor) {
            $seguro = htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $mapa['{{' . $chave . '}}'] = nl2br($seguro, false);
        }

        return $mapa;
    }
}
