#!/usr/bin/env bash
# Ciclo de calibracao do DANFS-e GK2.
#
# O TCPDF vive no WHMCS, nao aqui — entao renderizar exige o servidor. Este
# script sobe o que mudou para o homolog, gera o PDF de uma nota real, traz
# de volta e recorta cabecalho/corpo/rodape a 600dpi para inspecao visual.
#
#   tools/ciclo-danfse.sh [id_da_nfse]     (padrao: 43)
set -euo pipefail

ID="${1:-43}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
REMOTO=homolog@homolog.gk2.cloud
DESTINO=/home/homolog/public_html/modules/addons/nfsenacional
SAIDA="${CICLO_SAIDA:-${TMPDIR:-/tmp}/ciclo-danfse}"
mkdir -p "$SAIDA"

scp -q -P 2200 "$REPO/templates/danfse/DANFSe-GK2.html" \
    "$REMOTO:$DESTINO/templates/danfse/DANFSe-GK2.html"
scp -q -P 2200 "$REPO"/src/NfseNacional/Danfse/*.php "$REMOTO:$DESTINO/src/NfseNacional/Danfse/"

ssh -p 2200 "$REMOTO" "php /home/homolog/teste-real.php $ID" | tail -1
scp -q -P 2200 "$REMOTO:/home/homolog/danfse-real-$ID.pdf" "$SAIDA/atual.pdf"

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
