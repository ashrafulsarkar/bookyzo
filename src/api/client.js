/**
 * Thin wrapper around wp.apiFetch (loaded as a WP script dependency, see
 * BYZO_Admin_Assets — the @wordpress/api-fetch package handles the REST
 * nonce header automatically once wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(...))
 * is configured, which BYZO_Admin_Assets does via wp_add_inline_script).
 *
 * Every call is scoped to the bookyzo/v1 namespace so screen code never
 * repeats the namespace string.
 */

const NAMESPACE = '/bookyzo/v1';

function request(path, options = {}) {
  return window.wp.apiFetch({
    path: `${NAMESPACE}${path}`,
    ...options,
  });
}

export const api = {
  get: (path) => request(path, { method: 'GET' }),
  post: (path, data) => request(path, { method: 'POST', data }),
  put: (path, data) => request(path, { method: 'PUT', data }),
  delete: (path) => request(path, { method: 'DELETE' }),
};
