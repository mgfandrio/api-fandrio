# =============================================================================
# PgBouncer : file d'attente de connexions devant PostgreSQL
# =============================================================================
# Chaque processus PHP (workers Octane, queue, scheduler, Reverb) de chaque
# serveur API garde une connexion ouverte. PostgreSQL accepte 100 connexions
# par défaut et chacune coûte de la mémoire. PgBouncer accepte des milliers de
# connexions clientes et les fait passer par quelques dizaines de connexions
# PostgreSQL (mode transaction : une connexion serveur par transaction).
#
# PgBouncer >= 1.21 : les requêtes préparées de PDO fonctionnent en mode
# transaction (max_prepared_statements).
# =============================================================================
FROM alpine:3.20

RUN apk add --no-cache pgbouncer bash \
    && addgroup -S pgb && adduser -S -G pgb -H pgb \
    && mkdir -p /etc/pgbouncer /var/run/pgbouncer \
    && chown -R pgb:pgb /etc/pgbouncer /var/run/pgbouncer

COPY entrypoint.sh /usr/local/bin/pgbouncer-entrypoint.sh
RUN chmod +x /usr/local/bin/pgbouncer-entrypoint.sh

USER pgb
EXPOSE 6432
ENTRYPOINT ["/usr/local/bin/pgbouncer-entrypoint.sh"]
