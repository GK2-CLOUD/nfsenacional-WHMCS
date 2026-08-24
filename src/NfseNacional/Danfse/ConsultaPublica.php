<?php

namespace GK2\NfseNacional\Danfse;

/**
 * URL de consulta publica da NFS-e — o conteudo do QR Code do DANFS-e.
 *
 * PROCEDENCIA: obtida decodificando o QR do DANFS-e oficial da NFS-e 597
 * (tools/ler-qr.py). Nao foi deduzida nem copiada de documentacao — o
 * bitmap do documento do governo devolveu, literalmente:
 *
 *   https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=41152002214322136000122000000000059726082129342319
 *
 * Os 50 digitos batem com a chave de acesso da propria nota, o que
 * confirma a leitura.
 *
 * NAO CONFUNDIR com o endpoint de download do DANFS-e:
 *
 *   /ConsultaPublica/Download/DANFSe?chave=<token binario, double base64>
 *
 * Esse `chave` e um token de 56 bytes gerado internamente pelo governo e
 * NAO e derivavel da chave de acesso (ver CLAUDE.md, secao "Portal publico
 * do governo"). Sao dois enderecos diferentes com um parametro de mesmo
 * nome e significados distintos — a confusao entre os dois foi o que
 * manteve o conteudo do QR em aberto durante todo o projeto.
 */
class ConsultaPublica
{
    private const BASE = 'https://www.nfse.gov.br/ConsultaPublica';

    /**
     * Significado de tpc nao documentado publicamente; preservado como
     * lido no QR oficial. Nao alterar sem reler um DANFS-e do governo.
     */
    private const TPC = '1';

    /**
     * URL de consulta publica para uma chave de acesso (50 digitos).
     *
     * Devolve string vazia para chave ausente ou malformada — QR vazio
     * simplesmente nao e desenhado, e a chave impressa no documento
     * continua permitindo a consulta manual.
     */
    public static function url(?string $chaveAcesso): string
    {
        $chave = preg_replace('/\D/', '', (string) $chaveAcesso);

        if (strlen($chave) !== 50) {
            return '';
        }

        return self::BASE . '?tpc=' . self::TPC . '&chave=' . $chave;
    }
}
