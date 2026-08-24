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

    /** Marcadores da logo — trocados na hora do render. */
    private const PLACEHOLDER_LOGO   = '__LOGO__';
    private const PLACEHOLDER_LARG   = '__LOGO_W__';
    private const PLACEHOLDER_ALT    = '__LOGO_H__';

    /**
     * Caixa da logo, em px do HTML.
     *
     * O TCPDF converte px a 72dpi (medido: 0,3526mm/px), entao 44x142px sao
     * 15,5 x 50,1mm. A altura e a que a logo original usava; a largura foi
     * escolhida para caber com folga na coluna esquerda do cabecalho, que
     * tem 46% dos 194,5mm uteis.
     *
     * A logo e encaixada NA CAIXA preservando a proporcao — quem instala o
     * modulo poe a marca que tiver, em qualquer formato, e o cabecalho nao
     * se deforma. Uma logo pequena e ampliada ate a caixa; por isso o campo
     * de configuracao recomenda pelo menos 280x150px.
     */
    private const LOGO_ALTURA_MAX  = 44;
    private const LOGO_LARGURA_MAX = 142;

    /** Tipos que o TCPDF desenha sem depender de extensao extra. */
    private const LOGO_TIPOS = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF];

    private string $dir;
    private ?string $logoConfigurada;

    /** @var array{src:string,w:int,h:int}|null */
    private ?array $logo = null;
    private bool $logoCalculada = false;

    /**
     * @param string|null $logo Caminho da logo no sistema de arquivos. Vazio
     *                          ou ilegivel: o cabecalho sai com a razao
     *                          social no lugar da imagem.
     */
    public function __construct(?string $dir = null, ?string $logo = null)
    {
        $this->dir = rtrim($dir ?? dirname(__DIR__, 3) . '/templates/danfse', '/');
        $this->logoConfigurada = $logo;
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

        /* So o renderer sabe se o arquivo da logo resolveu, entao ele mesmo
           decide estas duas — nao o chamador. */
        $condicoes['se_logo']     = $this->logo() !== null;
        $condicoes['se_sem_logo'] = !$condicoes['se_logo'];

        foreach ($condicoes as $tag => $mostrar) {
            $html = self::aplicarCondicional($html, $tag, $mostrar);
        }

        $html = $this->resolverLogo($html);
        $html = strtr($html, $this->prepararTokens($tokens));

        $restantes = self::tokensRestantes($html);
        if ($restantes !== []) {
            throw new \RuntimeException(
                'Template do DANFS-e com token nao substituido: ' . implode(', ', $restantes),
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

        return preg_replace_callback($re, fn ($m) => $mostrar ? $m[1] : '', $html);
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

    /**
     * Logo em uso, ja validada e dimensionada. Null quando nao ha.
     *
     * @return array{src:string,w:int,h:int}|null
     */
    public function logo(): ?array
    {
        if (!$this->logoCalculada) {
            $this->logo = $this->calcularLogo();
            $this->logoCalculada = true;
        }

        return $this->logo;
    }

    /**
     * Alguem configurou uma logo e ela nao serve — arquivo inexistente,
     * ilegivel, remoto ou que nao e imagem. Vale um aviso no log de quem
     * chama; aqui dentro o render apenas segue sem imagem.
     */
    public function logoConfiguradaInvalida(): bool
    {
        return trim((string) $this->logoConfigurada) !== '' && $this->logo() === null;
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
        $logo = $this->logo();

        if ($logo === null) {
            // O bloco {{#se_logo}} ja saiu; os marcadores nao sobrevivem a ele.
            return $html;
        }

        return str_replace(
            [self::PLACEHOLDER_LOGO, self::PLACEHOLDER_LARG, self::PLACEHOLDER_ALT],
            [htmlspecialchars($logo['src'], ENT_QUOTES, 'UTF-8'), (string) $logo['w'], (string) $logo['h']],
            $html,
        );
    }

    /**
     * Valida o caminho configurado e encaixa a imagem na caixa da logo.
     *
     * Deliberadamente so aceita arquivo local: uma URL faria o TCPDF buscar
     * na rede a cada DANFS-e — lento, quebra quando o host esta fora, e
     * transforma um campo de configuracao em requisicao de saida do
     * servidor. Quem quiser usar a logo do site copia o arquivo.
     *
     * @return array{src:string,w:int,h:int}|null
     */
    private function calcularLogo(): ?array
    {
        $caminho = trim((string) $this->logoConfigurada);

        if ($caminho === '' || str_contains($caminho, '://')) {
            return null;
        }

        if (!is_file($caminho) || !is_readable($caminho)) {
            return null;
        }

        $info = @getimagesize($caminho);

        if ($info === false || !in_array($info[2], self::LOGO_TIPOS, true)) {
            return null;
        }

        [$largura, $altura] = $info;

        if ($largura < 1 || $altura < 1) {
            return null;
        }

        $escala = min(self::LOGO_ALTURA_MAX / $altura, self::LOGO_LARGURA_MAX / $largura);

        return [
            'src' => $caminho,
            'w'   => max(1, (int) round($largura * $escala)),
            'h'   => max(1, (int) round($altura * $escala)),
        ];
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
