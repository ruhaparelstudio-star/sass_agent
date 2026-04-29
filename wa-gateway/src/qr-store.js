const qrByTenant = new Map();

const normalizeTenantId = (tenantId) => {
  const parsed = Number.parseInt(String(tenantId), 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
};

export const setTenantQr = (tenantId, qr) => {
  const normalizedTenantId = normalizeTenantId(tenantId);
  if (normalizedTenantId === null) {
    return;
  }

  qrByTenant.set(normalizedTenantId, {
    tenant_id: normalizedTenantId,
    provider: qr.provider,
    qr_code: qr.qr_code,
    expires_in_seconds: qr.expires_in_seconds,
    generated_at: qr.generated_at,
  });
};

export const clearTenantQr = (tenantId) => {
  const normalizedTenantId = normalizeTenantId(tenantId);
  if (normalizedTenantId === null) {
    return;
  }

  qrByTenant.delete(normalizedTenantId);
};

export const getTenantQr = (tenantId) => {
  const normalizedTenantId = normalizeTenantId(tenantId);
  if (normalizedTenantId === null) {
    return null;
  }

  return qrByTenant.get(normalizedTenantId) ?? null;
};
