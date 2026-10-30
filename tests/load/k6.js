// Load test of the production image: people browsing, and a shop using the REST API, at the same time.
//
//   docker run --rm --network host -v "$PWD/tests/load:/load" grafana/k6 run \
//     -e BASE_URL=http://127.0.0.1:8090 -e API_KEY=erp_… -e EMAIL=admin@example.com -e PASSWORD=admin12345 /load/k6.js
//
// Run it against the production image (APP_ENV=prod), never the development server: the profiler
// and debug mode would be measured instead of the application. See docs/wydajnosc.md.
import http from 'k6/http';
import { check, fail } from 'k6';
import { randomItem, uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8090';
const API_KEY = __ENV.API_KEY || fail('API_KEY is required (app:api-key:create … --scope=catalog:read --scope=orders:write)');
const VUS = Number(__ENV.VUS || 10);
const DURATION = __ENV.DURATION || '1m';

export const options = {
    scenarios: {
        // Staff in the browser: server-rendered pages, a session in Redis
        browse: { executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'browse' },
        // The shop searching the catalog: Elasticsearch behind the API
        api_search: { executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'apiSearch' },
        // The shop placing orders: a transaction, a lock, the outbox
        api_orders: { executor: 'constant-arrival-rate', rate: 5, timeUnit: '1s', duration: DURATION, preAllocatedVUs: 5, exec: 'apiOrders' },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{scenario:browse}': ['p(95)<800'],
        'http_req_duration{scenario:api_search}': ['p(95)<500'],
        'http_req_duration{scenario:api_orders}': ['p(95)<500'],
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const WORDS = ['kabel', 'kabl', 'hdmi', 'zarowka', 'mysz', 'ladowarka', 'sluchawki', 'dysk ssd', 'klawiatura', 'hub usb', 'monitor', 'kab'];
const api = { headers: { Authorization: `Bearer ${API_KEY}`, 'Content-Type': 'application/json' } };

// One sign-in per virtual user; the session cookie stays in its jar
export function browse() {
    if (!__ITER) {
        const login = http.get(`${BASE_URL}/login`);
        const token = login.html().find('input[name="_csrf_token"]').attr('value');
        const signedIn = http.post(`${BASE_URL}/login`, { _username: __ENV.EMAIL, _password: __ENV.PASSWORD, _csrf_token: token }, { headers: { Origin: BASE_URL }, tags: { name: 'login' } });
        check(signedIn, { 'signed in': (r) => r.status === 200 && !r.url.endsWith('/login') });
    }

    for (const [path, name] of [['/', 'dashboard'], ['/catalog/products', 'products'], ['/sales/orders', 'orders']]) {
        const page = http.get(`${BASE_URL}${path}`, { tags: { name } });
        check(page, { [`${name} 200`]: (r) => r.status === 200 });
    }
}

export function apiSearch() {
    const response = http.get(`${BASE_URL}/api/products?q=${encodeURIComponent(randomItem(WORDS))}`, { ...api, tags: { name: 'api products' } });
    check(response, { 'search 200': (r) => r.status === 200 });
}

export function apiOrders() {
    const body = JSON.stringify({
        customerNip: __ENV.CUSTOMER_NIP || '5250000009',
        warehouse: 'MG',
        note: 'k6',
        // A service: has no stock, so the test cannot run the warehouse dry
        lines: [{ sku: __ENV.ORDER_SKU || 'USL-DOSTAWA', quantity: '1' }],
    });
    const response = http.post(`${BASE_URL}/api/orders`, body, { headers: { ...api.headers, 'Idempotency-Key': uuidv4() }, tags: { name: 'api orders' } });
    check(response, { 'order 201': (r) => r.status === 201 });
}
