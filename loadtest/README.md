# Test de charge FANDRIO (k6)

Simule des clients de l'application mobile sur la stack de **production**
(`docker-compose.prod.yml`), dans un projet Docker séparé (`fandrio_loadtest`) :
base, Redis et volumes dédiés. La base de dev n'est jamais touchée.

## Lancer

Pré-requis : Docker Desktop démarré, stack de dev arrêtée (`docker compose down`),
`.env` rempli (`API_KEY`, `APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`…).

```bash
# Depuis fandrio_api/, dans Git Bash
./loadtest/lancer.sh                               # test complet : jusqu'à 200 clients
VUS_MAX=500 DUREE_PALIER=3m ./loadtest/lancer.sh   # plus de clients, paliers plus longs
VUS_MAX=5 DUREE_PALIER=20s ./loadtest/lancer.sh    # validation rapide
./loadtest/lancer.sh --arreter                     # supprime la stack de test (et ses données)
```

Le script : démarre la stack → crée les données (`seed.sql`) → génère un token
JWT par client de test → lance k6 → relève CPU/mémoire des conteneurs.

Durée du test complet : environ 11 min (3 paliers de 2 min + montées).

## Ce qui est simulé

Chaque client virtuel = un client distinct (son propre token), qui répète :

| Étape | Requêtes |
|---|---|
| Accueil | `GET /api/accueil` |
| Recherche | `POST /api/recherche/recherche` |
| Détail voyage | `GET /api/recherche/voyages/{id}` |
| Plan de sièges | `GET /api/sieges/voyages/{id}/plan` |
| Réservation (30 % des parcours) | `POST /api/client/reservation` puis `.../cancel` |
| Dashboard | `GET /api/client/reservation/dashboard` |
| Notifications | `GET /api/notifications`, `GET /api/notifications/unread-count` |

Entre chaque écran : 1 à 2,5 s de « réflexion » (≈ 40 requêtes/min par client).
Ordre de grandeur : 200 clients simultanés ≈ 130 requêtes/s.

Paliers : ¼ de `VUS_MAX` → ½ → 100 %, chacun maintenu `DUREE_PALIER`.

**Non mesuré** : la connexion (`/api/connexion` est limitée à 60/min par IP, k6 ne
peut pas connecter des centaines de clients depuis une seule IP) et le paiement
(PAPI, service externe).

## Lire les résultats

Objectifs (le test est « réussi » s'ils sont tous tenus, sinon k6 les marque ✗) :

| Métrique | Objectif | Signification |
|---|---|---|
| `http_req_failed` | < 1 % | erreurs 5xx / timeouts |
| `http_req_duration{type:lecture}` p(95) | < 500 ms | 95 % des écrans en moins de 0,5 s |
| `http_req_duration{type:ecriture}` p(95) | < 1 s | réservation / annulation |
| `reservations_en_erreur` | < 1 % | réservations échouées hors conflit de siège |
| `erreurs_429` | 0 | rate-limit atteint |

`conflits_siege` n'est **pas** une erreur : deux clients ont visé le même siège,
l'API en a refusé un (comportement attendu).

Fichiers produits dans `loadtest/resultats/` :
- `resume-<date>.json` : toutes les métriques k6 ;
- `docker-stats-<date>.log` : CPU/mémoire de chaque conteneur toutes les 15 s,
  pour identifier le goulot (PHP-FPM `fandrio_app` ? PostgreSQL ? Redis ?).

## Limites

- Lancé sur un PC, le test mesure les capacités **de ce PC** (k6 et l'API se
  partagent le CPU). Pour des chiffres de production, lancer k6 depuis une
  autre machine vers le serveur cible (`BASE_URL`), avec une base de test.
- ⚠️ Ne jamais lancer `seed.sql` ni le test contre la base de production.
