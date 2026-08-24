#!/usr/bin/env python3
"""
Varre tamanhos de discriminacao e classifica o que abre cada pagina de
continuacao do DANFS-e.

    tools/varrer-paginacao.py <n_inicial> <n_final>

Cada secao do documento e um <tr nobr="true"> da tabela container, entao a
quebra de pagina tem que cair ENTRE secoes. Se cair no meio, a pagina de
continuacao abre com conteudo solto — foi o que acontecia antes do nobr: com
18 a 20 itens a pagina 2 abria com a faixa laranja do valor liquido, tendo
deixado o rotulo dela na pagina 1.

Roda o gerador no servidor WHMCS (tools/danfse-multipagina.php, que precisa do
TCPDF), traz o PDF e mede. Saida esperada: "faixa de secao" em todas.
"""
import subprocess, sys, glob, os
from PIL import Image
import numpy as np
REMOTO='homolog@homolog.gk2.cloud'
GERADOR='/home/homolog/public_html/modules/addons/nfsenacional/tools/danfse-multipagina.php'
PDF_REMOTO='/home/homolog/danfse-multipagina.pdf'
S=os.environ.get('VARREDURA_SAIDA', os.path.join(os.environ.get('TMPDIR','/tmp'), 'varredura-danfse'))
os.makedirs(S, exist_ok=True); px=25.4/300
def topo_da_pagina(n):
    subprocess.run(['ssh','-p','2200',REMOTO,
                    f'php {GERADOR} {n} 43 {PDF_REMOTO}'],capture_output=True)
    subprocess.run(['scp','-q','-P','2200',f'{REMOTO}:{PDF_REMOTO}',f'{S}/sw.pdf'])
    for f in glob.glob(f'{S}/sw-*.png'): os.remove(f)
    subprocess.run(['pdftoppm','-r','300','-png',f'{S}/sw.pdf',f'{S}/sw'],capture_output=True)
    pgs=sorted(glob.glob(f'{S}/sw-*.png'))
    saida=[]
    for p in pgs[1:]:
        im=np.array(Image.open(p).convert('RGB'))
        faixa=im[int(5.0/px):, int(20/px):int(190/px)]   # abaixo do traco da moldura
        tinta=np.where((faixa.mean(axis=2)<235).any(axis=1))[0]
        y=tinta.min()+int(0.6/px)               # dentro da 1a linha de tinta
        linha=faixa[y]
        escuro=(linha.mean(axis=1)<110).mean()
        laranja=((linha[:,0]>190)&(linha[:,1]>70)&(linha[:,1]<130)&(linha[:,2]<80)).mean()
        if escuro>=0.85:    t='faixa de secao'
        elif laranja>=0.85: t='faixa laranja'
        else:             t=f'SOLTO (escuro {escuro:.0%}, laranja {laranja:.0%})'
        saida.append(t)
    return len(pgs), saida
for n in range(int(sys.argv[1]), int(sys.argv[2])+1):
    p,t=topo_da_pagina(n)
    print(f'  {n:2d} -> {p} pag | topo das seguintes: {"; ".join(t)}', flush=True)
