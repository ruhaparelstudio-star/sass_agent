const requireEnv = (key, fallback = '') => {
  const value = process.env[key] ?? fallback;
  return typeof value === 'string' ? value : String(value);
};

export const config = {
  port: Number.parseInt(requireEnv('PORT', '8081'), 10),
  laravelBaseUrl: requireEnv('LARAVEL_BASE_URL', 'http://nginx'),
  internalSecret: requireEnv('WA_INTERNAL_SECRET', ''),
  internalAuthToken: requireEnv('LARAVEL_INTERNAL_AUTH_TOKEN', ''),
};
