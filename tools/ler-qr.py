#!/usr/bin/env python3
"""Decodificador de QR suficiente para ler o QR do DANFS-e oficial.

QR limpo, sem rotacao, vindo de PDF vetorial: da para pular a correcao
Reed-Solomon e ler os codewords de dados direto.
"""
import sys
import numpy as np
from PIL import Image

# ── amostragem da matriz ────────────────────────────────────────────
def matriz(png, y0, y1, x0, x1):
    a = np.array(Image.open(png).convert('L'))
    band = (a[y0:y1, x0:x1] < 128)
    ys, xs = np.where(band)
    qr = band[ys.min():ys.max()+1, xs.min():xs.max()+1]

    # o finder tem 7 modulos; o run preto inicial da linha 0 mede-os
    run = 0
    while run < qr.shape[1] and qr[0][run]:
        run += 1
    mp = run / 7.0
    n = int(round(qr.shape[1] / mp))

    m = np.zeros((n, n), dtype=np.uint8)
    for r in range(n):
        for c in range(n):
            yy = min(int((r + 0.5) * mp), qr.shape[0] - 1)
            xx = min(int((c + 0.5) * mp), qr.shape[1] - 1)
            m[r][c] = 1 if qr[yy][xx] else 0
    return m, n, mp

# ── modulos de funcao (nao carregam dados) ──────────────────────────
ALIGN = {1: [], 2: [6,18], 3: [6,22], 4: [6,26], 5: [6,30], 6: [6,34],
         7: [6,22,38], 8: [6,24,42], 9: [6,26,46], 10: [6,28,50]}

def funcao(n, versao):
    f = np.zeros((n, n), dtype=bool)
    for (br, bc) in [(0,0), (0,n-7), (n-7,0)]:              # finders + separador
        for r in range(br-1, br+8):
            for c in range(bc-1, bc+8):
                if 0 <= r < n and 0 <= c < n:
                    f[r][c] = True
    f[6, :] = True; f[:, 6] = True                           # timing
    f[n-8, 8] = True                                         # modulo escuro
    for r in range(0, 9):                                    # format info
        if r < n: f[r][8] = True; f[8][r] = True
    for r in range(n-8, n): f[r][8] = True; f[8][r] = True
    for a in ALIGN.get(versao, []):                          # alignment
        for b in ALIGN.get(versao, []):
            if (a,b) in [(6,6), (6,n-7), (n-7,6)]: continue
            for r in range(a-2, a+3):
                for c in range(b-2, b+3):
                    if 0 <= r < n and 0 <= c < n: f[r][c] = True
    return f

# ── format info ─────────────────────────────────────────────────────
def formato(m, n):
    bits = [m[8][c] for c in (0,1,2,3,4,5,7,8)] + [m[7][8]] + [m[r][8] for r in (5,4,3,2,1,0)]
    v = 0
    for b in bits: v = (v << 1) | int(b)
    v ^= 0x5412
    ec = (v >> 13) & 0b11
    mask = (v >> 10) & 0b111
    return {1:'L', 0:'M', 3:'Q', 2:'H'}[ec], mask

MASKS = [
    lambda i,j: (i+j) % 2 == 0,
    lambda i,j: i % 2 == 0,
    lambda i,j: j % 3 == 0,
    lambda i,j: (i+j) % 3 == 0,
    lambda i,j: (i//2 + j//3) % 2 == 0,
    lambda i,j: (i*j) % 2 + (i*j) % 3 == 0,
    lambda i,j: ((i*j) % 2 + (i*j) % 3) % 2 == 0,
    lambda i,j: ((i+j) % 2 + (i*j) % 3) % 2 == 0,
]

# ── leitura em zigue-zague ──────────────────────────────────────────
def bits_dados(m, n, f, mask):
    out = []
    col = n - 1
    subindo = True
    while col > 0:
        if col == 6: col -= 1                    # coluna de timing
        linhas = range(n-1, -1, -1) if subindo else range(n)
        for r in linhas:
            for c in (col, col-1):
                if f[r][c]: continue
                b = int(m[r][c])
                if MASKS[mask](r, c): b ^= 1
                out.append(b)
        col -= 2
        subindo = not subindo
    return out

# ── estrutura de blocos ─────────────────────────────────────────────
BLOCOS = {  # versao: {ec: [(n_blocos, dados_por_bloco, ec_por_bloco), ...]}
    4: {'L': [(1,80,20)],  'M': [(2,32,18)], 'Q': [(2,24,26)], 'H': [(4,9,16)]},
    5: {'L': [(1,108,26)], 'M': [(2,43,24)],
        'Q': [(2,15,18),(2,16,18)], 'H': [(2,11,22),(2,12,22)]},
    6: {'L': [(1,136,36)], 'M': [(4,27,16)], 'Q': [(4,19,24)], 'H': [(4,15,28)]},
    7: {'L': [(2,98,40)],  'M': [(4,31,18)],
        'Q': [(2,14,18),(4,15,18)], 'H': [(4,13,26),(1,14,26)]},
}

def deinterleave(cw, plano):
    blocos = []
    for (qtd, dados, _) in plano:
        for _ in range(qtd):
            blocos.append([])
    total_dados = sum(q*d for q,d,_ in plano)
    tamanhos = []
    for (qtd, dados, _) in plano:
        tamanhos += [dados]*qtd
    i = 0
    for pos in range(max(tamanhos)):
        for b, tam in enumerate(tamanhos):
            if pos < tam:
                if i < len(cw):
                    blocos[b].append(cw[i]); i += 1
    return [x for b in blocos for x in b]

def decodificar(dados):
    bits = []
    for byte in dados:
        for k in range(7, -1, -1): bits.append((byte >> k) & 1)
    def le(n):
        nonlocal bits
        v = 0
        for _ in range(n):
            v = (v << 1) | bits.pop(0)
        return v
    saida = b''
    while len(bits) >= 4:
        modo = le(4)
        if modo == 0: break
        if modo == 4:                      # byte
            ln = le(8)
            saida += bytes(le(8) for _ in range(ln))
        elif modo == 2:                    # alfanumerico
            ln = le(9)
            T = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:'
            s = ''
            while ln >= 2:
                v = le(11); s += T[v//45] + T[v%45]; ln -= 2
            if ln: s += T[le(6)]
            saida += s.encode()
        elif modo == 1:                    # numerico
            ln = le(10); s = ''
            while ln >= 3:
                s += '%03d' % le(10); ln -= 3
            if ln == 2: s += '%02d' % le(7)
            elif ln == 1: s += '%d' % le(4)
            saida += s.encode()
        else:
            break
    return saida

if __name__ == '__main__':
    png, y0, y1, x0, x1 = sys.argv[1], *map(int, sys.argv[2:6])
    m, n, mp = matriz(png, y0, y1, x0, x1)
    versao = (n - 17) // 4
    ec, mask = formato(m, n)
    print(f'modulos={n} versao={versao} modulo={mp:.2f}px EC={ec} mascara={mask}')
    f = funcao(n, versao)
    bits = bits_dados(m, n, f, mask)
    cw = [int(''.join(map(str, bits[i:i+8])), 2) for i in range(0, len(bits) - 7, 8)]
    print(f'codewords lidos: {len(cw)}')
    plano = BLOCOS[versao][ec]
    dados = deinterleave(cw, plano)
    print('conteudo:', decodificar(dados).decode('utf-8', 'replace'))
