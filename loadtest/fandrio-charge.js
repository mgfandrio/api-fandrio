// =============================================================================
// FANDRIO — Test de charge k6 : parcours d'un client de l'application mobile
// =============================================================================
// Lancement : voir loadtest/README.md (script loadtest/lancer.sh).
//
// Chaque utilisateur virtuel (VU) = un client distinct, avec son propre token
// JWT (le rate-limiter de l'API compte par utilisateur). Parcours répété :
//   accueil → recherche → détail voyage → plan de sièges
//   → [30 %] réservation puis annulation (libère le siège)
//   → dashboard → notifications
// avec des temps de réflexion de 1 à 2,5 s entre chaque écran.
//
// Variables (-e NOM=valeur) :
//   BASE_URL          URL de l'API            (défaut : http://nginx)
//   API_KEY           clé X-API-KEY           (obligatoire)
//   VUS_MAX           clients simultanés max  (défaut : 200)
//   DUREE_PALIER      durée de chaque palier  (défaut : 2m)
//   PART_RESERVATION  part des parcours avec réservation (défaut : 0.3)
// =============================================================================

import http from 'k6/http';
import { check, group, sleep, fail } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { SharedArray } from 'k6/data';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';

const BASE_URL = __ENV.BASE_URL || 'http://nginx';
const API_KEY = __ENV.API_KEY;
const VUS_MAX = parseInt(__ENV.VUS_MAX || '200', 10);
const DUREE_PALIER = __ENV.DUREE_PALIER || '2m';
const PART_RESERVATION = parseFloat(__ENV.PART_RESERVATION || '0.3');

// Tokens JWT générés par lancer.sh (un par client de test)
const TOKENS = new SharedArray('tokens', () => JSON.parse(open('./tokens.json')));

// Métriques métier
const reservationsCreees = new Counter('reservations_creees');
const conflitsSiege = new Counter('conflits_siege');        // siège pris entre-temps : attendu sous charge
const erreurs429 = new Counter('erreurs_429');              // rate-limit atteint
const reservationsEnErreur = new Rate('reservations_en_erreur');

const palier = (n) => Math.max(1, Math.ceil(n));

export const options = {
    setupTimeout: '2m',
    scenarios: {
        clients_mobiles: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '1m', target: palier(VUS_MAX / 4) },
                { duration: DUREE_PALIER, target: palier(VUS_MAX / 4) },
                { duration: '1m', target: palier(VUS_MAX / 2) },
                { duration: DUREE_PALIER, target: palier(VUS_MAX / 2) },
                { duration: '1m', target: VUS_MAX },
                { duration: DUREE_PALIER, target: VUS_MAX },
                { duration: '30s', target: 0 },
            ],
            gracefulRampDown: '30s',
        },
    },
    // Objectifs : le test est "réussi" si tous sont tenus
    thresholds: {
        http_req_failed: ['rate<0.01'],                         // < 1 % d'erreurs
        'http_req_duration{type:lecture}': ['p(95)<500'],       // 95 % des lectures < 500 ms
        'http_req_duration{type:ecriture}': ['p(95)<1000'],     // 95 % des écritures < 1 s
        reservations_en_erreur: ['rate<0.01'],                  // hors conflits de siège
        erreurs_429: ['count<1'],
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// -----------------------------------------------------------------------------

export function setup() {
    if (!API_KEY) fail('API_KEY manquante (-e API_KEY=...)');
    if (TOKENS.length < VUS_MAX) {
        fail(`tokens.json contient ${TOKENS.length} tokens pour ${VUS_MAX} VUs : relancer lancer.sh avec plus de clients`);
    }

    // Provinces desservies, pour des recherches qui renvoient des résultats
    const res = http.get(`${BASE_URL}/api/provinces`, { headers: entetes(TOKENS[0]) });
    if (res.status !== 200) fail(`API injoignable ou clé invalide : GET /api/provinces → HTTP ${res.status}`);
    // Réponse : { statut, data: { provinces: [ { id, nom, ... } ] } }
    const liste = res.json('data.provinces') || [];
    const provinces = liste.map((p) => p.id).filter(Boolean);
    if (provinces.length < 2) fail('Moins de 2 provinces : la base de test est-elle initialisée ?');
    return { provinces };
}

export default function (donnees) {
    const token = TOKENS[(__VU - 1) % TOKENS.length];
    const h = entetes(token);

    group('1. Accueil', () => {
        get('/api/accueil', h, 'accueil');
    });
    reflexion();

    let voyages = [];
    group('2. Recherche', () => {
        const arrivee = choisir(donnees.provinces);
        const res = post('/api/recherche/recherche', {
            pro_arrivee: arrivee,
            date_debut: dateDans(1),
            date_fin: dateDans(30),
        }, h, 'recherche', 'lecture');
        if (res.status === 200) voyages = res.json('data.voyages') || [];
    });
    reflexion();

    if (voyages.length === 0) return; // aucun voyage vers cette destination
    const voyageId = choisir(voyages).voyage_id;

    group('3. Détail du voyage', () => {
        get(`/api/recherche/voyages/${voyageId}`, h, 'detail_voyage');
    });
    reflexion();

    let siegesLibres = [];
    group('4. Plan de sièges', () => {
        const res = get(`/api/sieges/voyages/${voyageId}/plan`, h, 'plan_sieges');
        if (res.status === 200) {
            siegesLibres = (res.json('data.plan') || [])
                .filter((s) => s.statut === 'disponible')
                .map((s) => String(s.code));
        }
    });
    reflexion();

    if (Math.random() < PART_RESERVATION && siegesLibres.length > 0) {
        group('5. Réservation + annulation', () => reserverPuisAnnuler(voyageId, siegesLibres, h));
        reflexion();
    }

    group('6. Dashboard', () => {
        get('/api/client/reservation/dashboard', h, 'dashboard');
    });
    reflexion();

    group('7. Notifications', () => {
        get('/api/notifications', h, 'notifications');
        get('/api/notifications/unread-count', h, 'notifications_non_lues');
    });
    reflexion();
}

// -----------------------------------------------------------------------------

function reserverPuisAnnuler(voyageId, siegesLibres, h) {
    const nb = Math.random() < 0.2 && siegesLibres.length > 1 ? 2 : 1;
    const sieges = melanger(siegesLibres).slice(0, nb);

    const res = http.post(`${BASE_URL}/api/client/reservation`, JSON.stringify({
        voyage_id: voyageId,
        nb_voyageurs: sieges.length,
        sieges,
        voyageurs: sieges.map((s) => ({
            nom: 'Charge', prenom: `VU${__VU}`, phone: '0340000000', phone2: '0340000001', siege_numero: s,
        })),
    }), {
        headers: h,
        tags: { name: 'reservation', type: 'ecriture' },
        // 400 "siège plus disponible" = conflit normal entre clients, pas une panne
        responseCallback: http.expectedStatuses(200, 400),
    });
    compter429(res);

    if (res.status === 200) {
        reservationsCreees.add(1);
        reservationsEnErreur.add(false);
        const resId = res.json('data.res_id');
        // Annuler pour libérer les sièges (garde le test rejouable)
        const annul = http.post(`${BASE_URL}/api/client/reservation/${resId}/cancel`, null, {
            headers: h, tags: { name: 'annulation', type: 'ecriture' },
        });
        compter429(annul);
        check(annul, { 'annulation OK': (r) => r.status === 200 });
    } else if (res.status === 400 && String(res.json('message') || '').includes('plus disponible')) {
        conflitsSiege.add(1);
        reservationsEnErreur.add(false);
    } else {
        reservationsEnErreur.add(true);
        console.warn(`Réservation en erreur HTTP ${res.status} : ${String(res.body).slice(0, 200)}`);
    }
}

function entetes(token) {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Accept-Encoding': 'gzip',
        'X-API-KEY': API_KEY,
        Authorization: `Bearer ${token}`,
    };
}

function get(chemin, h, nom) {
    const res = http.get(`${BASE_URL}${chemin}`, { headers: h, tags: { name: nom, type: 'lecture' } });
    compter429(res);
    check(res, { [`${nom} : HTTP 200`]: (r) => r.status === 200 });
    return res;
}

function post(chemin, corps, h, nom, type) {
    const res = http.post(`${BASE_URL}${chemin}`, JSON.stringify(corps), { headers: h, tags: { name: nom, type } });
    compter429(res);
    check(res, { [`${nom} : HTTP 200`]: (r) => r.status === 200 });
    return res;
}

function compter429(res) {
    if (res.status === 429) erreurs429.add(1);
}

function reflexion() {
    sleep(1 + Math.random() * 1.5); // 1 à 2,5 s entre deux écrans
}

function choisir(tableau) {
    return tableau[Math.floor(Math.random() * tableau.length)];
}

function melanger(tableau) {
    return tableau.map((v) => [Math.random(), v]).sort((a, b) => a[0] - b[0]).map((x) => x[1]);
}

function dateDans(jours) {
    const d = new Date(Date.now() + jours * 86400000);
    return d.toISOString().slice(0, 10);
}

export function handleSummary(data) {
    const horodatage = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    return {
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
        [`/scripts/resultats/resume-${horodatage}.json`]: JSON.stringify(data, null, 2),
    };
}
