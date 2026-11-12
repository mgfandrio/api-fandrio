// =============================================================================
// FANDRIO — débit maximal d'UN écran (diagnostic)
// =============================================================================
// Contrairement à fandrio-charge.js (parcours réaliste avec temps de réflexion),
// ce script enchaîne les requêtes sans pause sur une seule URL, pour mesurer ce
// que l'API peut encaisser au maximum sur cet écran et repérer ce qui coûte.
//
//   docker run --rm -i --network fandrio_loadtest_fandrio -v "<chemin>/loadtest:/scripts" \
//     -e API_KEY=... -e CHEMIN=/api/moi -e VUS=20 -e DUREE=20s grafana/k6 run /scripts/debit-max.js
// =============================================================================
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const BASE_URL = __ENV.BASE_URL || 'http://nginx';
const CHEMIN = __ENV.CHEMIN || '/api/moi';
const TOKENS = new SharedArray('tokens', () => JSON.parse(open('./tokens.json')));

export const options = {
    vus: Number(__ENV.VUS || 20),
    duration: __ENV.DUREE || '20s',
    summaryTrendStats: ['avg', 'med', 'p(95)', 'max'],
};

export default function () {
    const res = http.get(`${BASE_URL}${CHEMIN}`, {
        headers: {
            Accept: 'application/json',
            'X-API-KEY': __ENV.API_KEY,
            Authorization: `Bearer ${TOKENS[(__VU - 1) % TOKENS.length]}`,
        },
    });
    check(res, { 'HTTP 200': (r) => r.status === 200 });
}
