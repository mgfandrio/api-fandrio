# FANDRIO — Passer de 1 serveur à plusieurs serveurs

Tout est prêt dans le dépôt, mais **rien n'est activé** : la préprod tourne sur un seul VPS (`docker-compose.prod.yml` + `docker-compose.octane.yml`). Ce guide décrit la bascule, à faire seulement quand la préprod est validée et que les indicateurs ci-dessous le demandent.

## 1. Quand basculer

Ajouter des serveurs API quand, sur le VPS unique, l'un de ces signes dure aux heures de pointe :

- le processeur reste au-dessus de 80 % (`docker stats`, colonne CPU de `fandrio_app`) ;
- le test de charge (`loadtest/`) montre un temps de réponse à 95 % au-dessus de 1,5 s pour la charge attendue ;
- PostgreSQL refuse des connexions (`too many clients already` dans les logs).

Le dernier cas seul se règle sans nouveau serveur : activer PgBouncer (étape 0).

## 2. Architecture cible

```
                 Internet
                    │
        ┌───────────▼────────────┐
        │  Répartiteur (nginx)   │  api.fandrio.mg / ws.fandrio.mg, HTTPS
        └─────┬────────────┬─────┘
   réseau     │            │
   privé  ┌───▼────┐   ┌───▼────┐
          │ API 1  │   │ API 2  │   … ajoutez-en autant que nécessaire
          │ nginx  │   │ nginx  │   Octane (requêtes), Reverb (WebSockets),
          │ Octane │   │ Octane │   files de tâches ; planificateur sur API 1
          │ Reverb │   │ Reverb │   seulement
          │ queue  │   │ queue  │
          └───┬────┘   └───┬────┘
              │            │
          ┌───▼────────────▼───┐
          │ Serveur de données │   PostgreSQL derrière PgBouncer (6432),
          │ PgBouncer  Redis   │   Redis (6379) — réseau privé uniquement
          └────────────────────┘
```

Les serveurs API ne gardent rien : base, cache, sessions JWT révoquées, limites de requêtes, files de tâches et verrous du planificateur sont sur le serveur de données. Logos et photos de profil sont sur Cloudinary. On peut donc ajouter ou retirer un serveur API à tout moment.

## 3. Ce qui est déjà en place dans le code

| Point | Où |
|---|---|
| Tâches planifiées exécutées une seule fois même si deux serveurs les lancent (`onOneServer`) | `app/Console/Kernel.php` |
| Adresse IP réelle des clients derrière nginx et le répartiteur (limites de requêtes par client) | `app/Http/Middleware/TrustProxies.php`, variable `TRUSTED_PROXIES` |
| PgBouncer en mode transaction, requêtes préparées prises en charge | `docker/pgbouncer/` |
| `search_path` et fuseau horaire posés sur le rôle PostgreSQL (indispensable derrière PgBouncer) | `docker/postgres/init/02-parametres-role.sh` |
| Reverb relayé entre serveurs par Redis (`REVERB_SCALING_ENABLED`) | `deploy/multi-serveurs/docker-compose.api.yml` |
| Fichiers par rôle de serveur, répartiteur, variables d'exemple | `deploy/multi-serveurs/` |

## 4. Étape 0 — PgBouncer sur le VPS unique (à faire d'abord, sur la préprod)

Permet de valider PgBouncer seul, sans changer de serveur.

1. Poser les paramètres sur le rôle (base existante ; automatique sur une base neuve). Remplacer `fandrio` par `DB_USERNAME` / `DB_DATABASE` si différents :
   ```bash
   docker exec -it fandrio_postgres psql -U fandrio -d fandrio -c \
     "ALTER ROLE fandrio IN DATABASE fandrio SET search_path = fandrio_app, public;" -c \
     "ALTER ROLE fandrio IN DATABASE fandrio SET timezone = 'Indian/Antananarivo';"
   ```
2. Relancer avec PgBouncer :
   ```bash
   docker compose -f docker-compose.prod.yml -f docker-compose.octane.yml \
     -f docker-compose.pgbouncer.yml up -d --build
   ```
3. Vérifier (section 9), puis relancer le test de charge. **Retour arrière** : la même commande sans `-f docker-compose.pgbouncer.yml`.

## 5. Étape 1 — Préparer les serveurs

- 1 serveur de données (4 Go de RAM minimum), 2 serveurs API (2 à 4 cœurs), 1 répartiteur (petit serveur, ou le service de répartition de l'hébergeur).
- Tous dans le **même réseau privé** de l'hébergeur (adresses en 10.x ou 192.168.x).
- Pare-feu : depuis Internet, seuls les ports 80 et 443 du répartiteur (et SSH). Les ports 6432 et 6379 du serveur de données ne sont ouverts qu'aux serveurs API ; les ports 80 et 8080 des serveurs API, qu'au répartiteur.
- Docker et Git installés partout ; le dépôt cloné à l'identique sur le serveur de données et les serveurs API.

## 6. Étape 2 — Serveur de données

```bash
cp deploy/multi-serveurs/env.donnees.example deploy/multi-serveurs/.env.donnees
# Renseigner IP_PRIVEE, DB_PASSWORD, REDIS_PASSWORD (mots de passe longs)
docker compose --env-file deploy/multi-serveurs/.env.donnees \
  -f deploy/multi-serveurs/docker-compose.donnees.yml up -d --build
```

La base démarre vide avec le schéma (`fandrioSQL_v1.0.sql`) et les paramètres du rôle.

## 7. Étape 3 — Copier les données (coupure courte)

Prévoir 15 à 30 minutes, de nuit. L'app affiche des erreurs pendant la coupure.

1. Sur l'ancien VPS, arrêter l'API pour figer les données :
   `docker compose -f docker-compose.prod.yml -f docker-compose.octane.yml stop app queue scheduler reverb`
2. Exporter :
   `docker exec fandrio_postgres pg_dump -U fandrio -d fandrio -Fc -f /tmp/fandrio.dump && docker cp fandrio_postgres:/tmp/fandrio.dump .`
3. Copier `fandrio.dump` sur le serveur de données (`scp`), puis l'importer dans la base neuve (le schéma de démarrage est remplacé) :
   ```bash
   docker cp fandrio.dump fandrio_postgres:/tmp/
   docker exec fandrio_postgres pg_restore -U fandrio -d fandrio --clean --if-exists --no-owner /tmp/fandrio.dump
   ```
4. Compter quelques lignes des deux côtés (`SELECT count(*) FROM fandrio_app.reservations;`, idem voyages, utilisateurs) : les nombres doivent être identiques.

Redis n'a pas besoin d'être copié : il ne contient que du cache et des tâches en cours (laisser l'ancienne file se vider avant l'arrêt, `docker logs fandrio_queue`).

## 8. Étape 4 — Serveurs API puis répartiteur

Sur **chaque** serveur API :

```bash
# .env : copie exacte de celui de l'ancien VPS (APP_KEY, JWT_SECRET, API_KEY, PAPI, Cloudinary…)
cp deploy/multi-serveurs/env.api.example deploy/multi-serveurs/.env.api
# Renseigner IP_PRIVEE (de CE serveur), IP_DONNEES, REDIS_PASSWORD
# Sur API 1 seulement : COMPOSE_PROFILES=planificateur
docker compose --env-file deploy/multi-serveurs/.env.api \
  -f deploy/multi-serveurs/docker-compose.api.yml up -d --build
```

**Important** : le `.env` principal est identique sur tous les serveurs API, avec les mêmes `REVERB_APP_*`. `APP_KEY` en particulier doit être la même partout (elle déchiffre les clés PAPI des compagnies), ainsi que `JWT_SECRET` (sinon un client connecté via API 1 serait refusé par API 2).

Sur le répartiteur : installer nginx, copier `deploy/multi-serveurs/nginx-repartiteur.conf` dans `/etc/nginx/conf.d/`, remplacer les adresses et domaines, obtenir les certificats (`certbot --nginx`), `nginx -t && systemctl reload nginx`. Enfin, faire pointer les DNS `api.` et `ws.` vers le répartiteur.

## 9. Vérifications

| Vérification | Commande ou geste | Attendu |
|---|---|---|
| Chaque serveur API répond | `curl http://IP_PRIVEE_API/up` depuis le répartiteur | `200` |
| L'API publique répond | `curl -I https://api.fandrio.mg/up` | `200` |
| Les deux serveurs reçoivent du trafic | `docker logs --tail 20 fandrio_nginx` sur chaque serveur API | des requêtes des deux côtés |
| Base via PgBouncer, bon fuseau | `docker exec fandrio_app php artisan tinker --execute="echo DB::selectOne('select now() as n, current_setting(\'search_path\') as s')->n;"` | heure de Madagascar |
| Planificateur unique | `docker logs fandrio_scheduler` sur API 1 ; aucun conteneur `fandrio_scheduler` ailleurs | tâches chaque minute |
| Sièges en temps réel entre serveurs | deux téléphones sur le même voyage : un sélectionne un siège | l'autre le voit aussitôt |
| Limites de requêtes par client | dans les logs, les adresses vues sont celles des clients, pas 10.x / 172.x | IP publiques variées |
| Réservation et paiement | une réservation complète en préprod | billet confirmé, notification reçue |

Puis relancer le test de charge depuis une machine extérieure (`loadtest/`, `BASE_URL=https://api.fandrio.mg`) et comparer avec la mesure sur un seul serveur.

## 10. Retour arrière

Tant que l'ancien VPS n'est pas supprimé : remettre les DNS sur lui et le relancer (`docker compose -f docker-compose.prod.yml -f docker-compose.octane.yml up -d`). **Attention** : les réservations faites entre-temps sur les nouveaux serveurs ne sont pas sur l'ancien. Au-delà de quelques heures, faire l'inverse de l'étape 3 (export depuis le serveur de données, import sur l'ancien VPS).

## 11. Exploitation au quotidien

- **Nouvelle version** : serveur API par serveur API. Passer le serveur en `down` dans le répartiteur, `git pull`, relancer la commande de l'étape 4, vérifier `/up`, le remettre dans le répartiteur, passer au suivant. Aucune coupure.
- **Migration SQL** : une seule fois, sur le serveur de données (`docker exec -i fandrio_postgres psql -U fandrio -d fandrio < database/SQLRequetes/xxx.sql`), avant de déployer le code qui en dépend.
- **Ajouter un serveur API** : étape 4 sur la nouvelle machine, ouvrir son accès aux ports 6432/6379 du serveur de données, ajouter ses deux lignes dans le répartiteur, `reload`.
- **Logs** : chaque serveur API garde les siens (`storage/logs` du conteneur). En cas d'erreur, regarder les deux.
- **Connexions PostgreSQL** : chaque processus PHP de chaque serveur ouvre une connexion vers PgBouncer, qui n'en garde que `PGBOUNCER_POOL_SIZE` vers PostgreSQL. Si les logs PgBouncer montrent des attentes (`query_wait_timeout`), augmenter `PGBOUNCER_POOL_SIZE` en restant sous `PG_MAX_CONNECTIONS`.
- **Photos de chauffeurs** : elles sont enregistrées sur le disque du serveur qui les reçoit (l'app ne les utilise pas aujourd'hui). À déplacer sur Cloudinary avant de les utiliser en multi-serveurs.
