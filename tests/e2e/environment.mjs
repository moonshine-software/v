// Fixed values shared by the setup, the config and the specs.
export const BASE_URL = 'http://127.0.0.1:18765';
export const HOSTED_URL = 'http://127.0.0.1:18766';
export const API_PORTS = { blog: 19101, shop: 19102, oauth: 19201, session: 19202, code: 19203, links: 19204 };
export const API_DIRECTORIES = {
    blog: 'examples/blog-api',
    shop: 'examples/shop-api',
    oauth: 'examples/oauth-idp-api',
    session: 'examples/session-api',
    code: 'tests/e2e/fixtures/oauth-code',
    links: 'tests/e2e/fixtures/link-pagination',
};
export const ADMIN = { username: 'e2e-admin', password: 'e2e-password' };
// Hard-coded users of examples/oauth-idp-api and examples/session-api.
export const UPSTREAM = {
    oauth: { username: 'admin', password: '123456' },
    session: { username: 'admin', password: '1234567' },
};
export const STATE_FILE = new URL('./.artifacts/state.json', import.meta.url);
