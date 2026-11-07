#!/usr/bin/env bash
# =============================================================================
# FANDRIO — Lance le test de charge de bout en bout (Git Bash / Linux / macOS)
# =============================================================================
#   ./loadtest/lancer.sh                 test complet (200 clients max)
#   VUS_MAX=50 DUREE_PALIER=1m ./loadtest/lancer.sh    test réduit
#   ./loadtest/lancer.sh --arreter       arrête et SUPPRIME la stack de test
#
# Démarre la stack de PRODUCTION (docker-compose.prod.yml) sous un projet
# Docker séparé "fandrio_loadtest" : base, Redis et volumes dédiés, ta base de
# dev n'est jamais touchée. Puis : données de test, tokens JWT, test k6.
# =============================================================================
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash : ne pas convertir les chemins des conteneurs

cd "$(dirname "$0")/.."
RACINE="$(pwd)"
RACINE_DOCKER="$( (pwd -W 2>/dev/null) || pwd )" # chemin compris par Docker Desktop sous Windows

PROJET=fandrio_loadtest
COMPOSE="docker compose -p $PROJET -f docker-compose.prod.yml"
VUS_MAX="${VUS_MAX:-200}"
DUREE_PALIER="${DUREE_PALIER:-2m}"
K6_IMAGE="${K6_IMAGE:-grafana/k6:latest}"

if [ "${1:-}" = "--arreter" ]; then
    echo "=== Arrêt et suppression de la stack de test (volumes compris) ==="
    $COMPOSE down -v
    exit 0
fi

# Les noms de conteneurs (fandrio_app, ...) sont fixes : la stack de dev ne doit pas tourner
for c in fandrio_app fandrio_postgres fandrio_nginx; do
    projet_existant=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$c" 2>/dev/null || true)
    if [ -n "$projet_existant" ] && [ "$projet_existant" != "$PROJET" ]; then
        echo "ARRÊT : le conteneur $c appartient au projet '$projet_existant'."
        echo "        Arrêtez d'abord la stack de dev : docker compose down"
        exit 1
    fi
done

API_KEY=$(grep -E '^API_KEY=' .env | head -1 | cut -d= -f2- | tr -d '"\r')
DB_USER=$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2- | tr -d '"\r'); DB_USER="${DB_USER:-fandrio}"
DB_NAME=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"\r'); DB_NAME="${DB_NAME:-fandrio}"
[ -n "$API_KEY" ] || { echo "ARRÊT : API_KEY absente du .env"; exit 1; }

echo "=== 1/5 Démarrage de la stack de production (projet $PROJET) ==="
$COMPOSE up -d --build

echo "=== 2/5 Attente de l'API ==="
for i in $(seq 1 90); do
    if docker exec fandrio_app php artisan --version >/dev/null 2>&1 \
       && docker exec fandrio_postgres pg_isready -U "$DB_USER" >/dev/null 2>&1 \
       && [ "$(docker exec fandrio_postgres psql -U "$DB_USER" -d "$DB_NAME" -tAc "select count(*) from pg_tables where schemaname='fandrio_app' and tablename='voyages'" 2>/dev/null)" = "1" ]; then
        break
    fi
    sleep 2
done

echo "=== 3/5 Données de test ==="
DEJA=$(docker exec fandrio_postgres psql -U "$DB_USER" -d "$DB_NAME" -tAc "select count(*) from fandrio_app.utilisateurs where util_email like 'charge%@test.fandrio'")
if [ "$DEJA" -lt "$VUS_MAX" ]; then
    docker exec -i fandrio_postgres psql -U "$DB_USER" -d "$DB_NAME" -v confirmer_base_de_test=1 < loadtest/seed.sql
else
    echo "Déjà présentes ($DEJA clients de test)."
fi

echo "=== 4/5 Tokens JWT des $VUS_MAX clients de test ==="
docker exec fandrio_app php artisan tinker --execute="echo json_encode(App\Models\Utilisateurs\Utilisateur::where('util_email', 'like', 'charge%@test.fandrio')->orderBy('util_id')->limit($VUS_MAX)->get()->map(fn(\$u) => Tymon\JWTAuth\Facades\JWTAuth::fromUser(\$u))->values());" \
    | tr -d '\r' | grep -E '^\[' > loadtest/tokens.json
echo "$(grep -o '"eyJ' loadtest/tokens.json | wc -l) tokens générés."

mkdir -p loadtest/resultats
HORODATAGE=$(date +%Y%m%d-%H%M%S)
STATS="loadtest/resultats/docker-stats-$HORODATAGE.log"

echo "=== 5/5 Test k6 : jusqu'à $VUS_MAX clients simultanés, paliers de $DUREE_PALIER ==="
echo "    (CPU/mémoire des conteneurs relevés toutes les 15 s dans $STATS)"
( while true; do date +%T >> "$STATS"; docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' >> "$STATS"; sleep 15; done ) &
PID_STATS=$!
trap 'kill $PID_STATS 2>/dev/null || true' EXIT

docker run --rm -i \
    --network "${PROJET}_fandrio" \
    -v "$RACINE_DOCKER/loadtest:/scripts" \
    -e BASE_URL=http://nginx \
    -e API_KEY="$API_KEY" \
    -e VUS_MAX="$VUS_MAX" \
    -e DUREE_PALIER="$DUREE_PALIER" \
    "$K6_IMAGE" run /scripts/fandrio-charge.js || CODE=$?

echo
echo "Résumé JSON et relevés : $RACINE/loadtest/resultats/"
echo "Stack de test toujours démarrée. Pour la supprimer : ./loadtest/lancer.sh --arreter"
exit "${CODE:-0}"
