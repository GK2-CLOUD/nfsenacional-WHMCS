#!/usr/bin/env bash
# Ciclo de calibracao do DANFS-e GK2.
#
# O TCPDF vive no WHMCS, nao aqui — entao renderizar exige o servidor. Este
# script sobe o que mudou para o servidor, gera o PDF de uma nota real, traz
# de volta e recorta cabecalho/corpo/rodape a 600dpi para inspecao visual.
#
#   DANFSE_REMOTO=usuario@host DANFSE_WHMCS=/caminho/do/whmcs \
#       tools/ciclo-danfse.sh [id_da_nfse]     (padrao: 43)
#
# O destino e a porta SSH vem do ambiente — cada instalacao tem os seus, e
# nao ha por que fixar os de uma delas aqui.
set -euo pipefail

ID="${1:-43}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
REMOTO="${DANFSE_REMOTO:?defina DANFSE_REMOTO=usuario@host}"
PORTA="${DANFSE_PORTA:-22}"
WHMCS="${DANFSE_WHMCS:?defina DANFSE_WHMCS=/caminho/do/whmcs}"
DESTINO="$WHMCS/modules/addons/nfsenacional"
SAIDA="${CICLO_SAIDA:-${TMPDIR:-/tmp}/ciclo-danfse}"
mkdir -p "$SAIDA"

scp -q -P "$PORTA" "$REPO/templates/danfse/DANFSe-GK2.html" \
    "$REMOTO:$DESTINO/templates/danfse/DANFSe-GK2.html"
scp -q -P "$PORTA" "$REPO"/src/NfseNacional/Danfse/*.php "$REMOTO:$DESTINO/src/NfseNacional/Danfse/"

ssh -p "$PORTA" "$REMOTO" "php $DESTINO/tools/danfse-render.php $ID /tmp/danfse-$ID.pdf" | tail -1
scp -q -P "$PORTA" "$REMOTO:/tmp/danfse-$ID.pdf" "$SAIDA/atual.pdf"

pdftoppm -r 600 -f 1 -l 1 -png "$SAIDA/atual.pdf" "$SAIDA/pg" 2>/dev/null
python3 - "$SAIDA" <<'PY'
import sys
from PIL import Image
d = sys.argv[1]; px = 600/25.4
im = Image.open(f'{d}/pg-1.png')
for nome, (y0, y1) in {'cabecalho': (0, 55), 'corpo': (55, 250), 'rodape': (240, 297)}.items():
    im.crop((0, int(y0*px), im.width, min(int(y1*px), im.height))).save(f'{d}/{nome}.png')
print(f'  recortes em {d}: cabecalho / corpo / rodape')
PY
