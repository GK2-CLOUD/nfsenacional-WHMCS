#!/usr/bin/env bash
# Roda as cinco suites do DANFS-e GK2. Sem dependencia de WHMCS nem de rede:
# tudo sai da NFS-e 597 em fixtures/, conferida contra o DANFS-e oficial.
set -uo pipefail
cd "$(dirname "$0")"
falhou=0
for t in t1 t2 t3-codigos t4-qr t5-service t6-discriminacao; do
    linha=$(php "$t.php" 2>&1 | grep -E 'passaram' | tail -1)
    printf '  %-12s %s\n' "$t" "${linha:-SEM SAIDA}"
    case "$linha" in *' 0 falharam') ;; *) falhou=1 ;; esac
done
exit $falhou
