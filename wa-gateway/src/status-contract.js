const allowedAccountStatuses = new Set(['disconnected', 'connecting', 'connected']);
const allowedSessionStatuses = new Set(['pending', 'active', 'closed']);

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const requireString = (value, key) => {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`${key} is required and must be a non-empty string.`);
  }

  return value.trim();
};

const requireInteger = (value, key) => {
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${key} is required and must be a positive integer.`);
  }

  return value;
};

const requireObject = (value, key) => {
  if (!isObject(value)) {
    throw new Error(`${key} is required and must be an object.`);
  }

  return value;
};

export const validateStatusPayload = (payload) => {
  const source = requireObject(payload, 'payload');

  const tenantId = requireInteger(source.tenant_id, 'tenant_id');
  const provider = requireString(source.provider, 'provider');
  const accountProviderRef = requireString(source.account_provider_ref, 'account_provider_ref');
  const accountStatus = requireString(source.account_status, 'account_status');
  const sessionProviderRef = requireString(source.session_provider_ref, 'session_provider_ref');
  const sessionStatus = requireString(source.session_status, 'session_status');
  const accountPayload = requireObject(source.account_payload, 'account_payload');
  const sessionPayload = requireObject(source.session_payload, 'session_payload');

  if (!allowedAccountStatuses.has(accountStatus)) {
    throw new Error('account_status is invalid.');
  }

  if (!allowedSessionStatuses.has(sessionStatus)) {
    throw new Error('session_status is invalid.');
  }

  return {
    tenant_id: tenantId,
    provider,
    account_provider_ref: accountProviderRef,
    account_status: accountStatus,
    account_phone: typeof source.account_phone === 'string' ? source.account_phone : null,
    account_payload: accountPayload,
    account_meta: isObject(source.account_meta) ? source.account_meta : undefined,
    session_provider_ref: sessionProviderRef,
    session_status: sessionStatus,
    session_payload: sessionPayload,
    session_meta: isObject(source.session_meta) ? source.session_meta : undefined,
  };
};

