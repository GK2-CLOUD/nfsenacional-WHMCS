<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Normaliza a unidade federativa para a sigla de 2 letras.
 *
 * O campo `state` do WHMCS e texto livre: um cliente tem "Paraná",
 * outro tem "PR", outro "parana". O DANFS-e mostra sigla.
 *
 * Entrada nao reconhecida volta como veio (em caixa alta), pela mesma
 * regra de Codigos: nunca inventar.
 */
class Uf
{
    private const NOMES = [
        'acre' => 'AC',
        'alagoas' => 'AL',
        'amapa' => 'AP',
        'amazonas' => 'AM',
        'bahia' => 'BA',
        'ceara' => 'CE',
        'distrito federal' => 'DF',
        'espirito santo' => 'ES',
        'goias' => 'GO',
        'maranhao' => 'MA',
        'mato grosso' => 'MT',
        'mato grosso do sul' => 'MS',
        'minas gerais' => 'MG',
        'para' => 'PA',
        'paraiba' => 'PB',
        'parana' => 'PR',
        'pernambuco' => 'PE',
        'piaui' => 'PI',
        'rio de janeiro' => 'RJ',
        'rio grande do norte' => 'RN',
        'rio grande do sul' => 'RS',
        'rondonia' => 'RO',
        'roraima' => 'RR',
        'santa catarina' => 'SC',
        'sao paulo' => 'SP',
        'sergipe' => 'SE',
        'tocantins' => 'TO',
    ];

    private const SIGLAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    public static function sigla(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        $upper = mb_strtoupper($v, 'UTF-8');
        if (in_array($upper, self::SIGLAS, true)) {
            return $upper;
        }

        $chave = self::normalizar($v);
        if (isset(self::NOMES[$chave])) {
            return self::NOMES[$chave];
        }

        return $upper;
    }

    /**
     * Minusculas, sem acento e com espacos colapsados, para que
     * "Paraná", "PARANA" e "parana " caiam na mesma chave.
     */
    private static function normalizar(string $v): string
    {
        $mapa = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','î'=>'i','ì'=>'i','ï'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
            'ú'=>'u','û'=>'u','ù'=>'u','ü'=>'u',
            'ç'=>'c',
        ];

        $v = mb_strtolower($v, 'UTF-8');
        $v = strtr($v, $mapa);
        $v = preg_replace('/\s+/', ' ', $v);

        return trim($v);
    }
}
