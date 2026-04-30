import express from 'express';
import QRCode from 'qrcode';

import { connectTenantSession, disconnectTenantSessions, sendTenantMessage } from './baileys-manager.js';
import { createCallbackClient } from './callback-client.js';
import { clearTenantQr, getTenantQr, setTenantQr } from './qr-store.js';
import { validateStatusPayload } from './status-contract.js';

export const createApp = ({
  callbackClient = createCallbackClient(),
  sendTenantMessageFn = sendTenantMessage,
  connectTenantSessionFn = connectTenantSession,
  disconnectTenantSessionsFn = disconnectTenantSessions,
} = {}) => {
  const app = express();
  app.use(express.json());

  app.get('/health', (_req, res) => {
    return res.status(200).json({
      service: 'wa-gateway',
      status: 'ok',
      timestamp: new Date().toISOString(),
    });
  });

  app.get('/qr', async (req, res) => {
    const tenantIdRaw = req.query.tenant_id;
    const tenantId = Number.parseInt(String(tenantIdRaw ?? '1'), 10);
    const qr = getTenantQr(tenantId);

    if (!qr) {
      return res.status(404).json({
        status: 'unavailable',
        message: 'QR belum tersedia untuk tenant ini.',
      });
    }

    const qrImageDataUrl = await QRCode.toDataURL(qr.qr_code, {
      width: 320,
      margin: 1,
    });

    return res.status(200).json({
      ...qr,
      qr_image_data_url: qrImageDataUrl,
    });
  });

  app.post('/callbacks/status', async (req, res) => {
    try {
      const validatedPayload = validateStatusPayload(req.body);
      const result = await callbackClient.sendStatus(validatedPayload);
      const qrCandidate = validatedPayload.session_payload?.qr_code;

      if (validatedPayload.session_status === 'pending' && typeof qrCandidate === 'string' && qrCandidate.trim() !== '') {
        setTenantQr(validatedPayload.tenant_id, {
          provider: validatedPayload.provider,
          qr_code: qrCandidate.trim(),
          expires_in_seconds: 60,
          generated_at: new Date().toISOString(),
        });
      } else if (validatedPayload.session_status !== 'pending') {
        clearTenantQr(validatedPayload.tenant_id);
      }

      return res.status(200).json({
        status: 'forwarded',
        result,
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
      const statusCode = message.includes('required') || message.includes('invalid') ? 422 : 500;

      return res.status(statusCode).json({
        status: 'error',
        message,
      });
    }
  });

  app.post('/sessions/connect', async (req, res) => {
    const source = req.body ?? {};
    const tenantId = Number.parseInt(String(source.tenant_id ?? ''), 10);
    const provider = typeof source.provider === 'string' && source.provider.trim() !== '' ? source.provider.trim() : 'meta';
    const accountProviderRef = typeof source.account_provider_ref === 'string' && source.account_provider_ref.trim() !== ''
      ? source.account_provider_ref.trim()
      : `acct-${tenantId}`;
    const sessionProviderRef = typeof source.session_provider_ref === 'string' && source.session_provider_ref.trim() !== ''
      ? source.session_provider_ref.trim()
      : `sess-${tenantId}-${Date.now()}`;

    if (!Number.isInteger(tenantId) || tenantId <= 0) {
      return res.status(422).json({
        status: 'error',
        message: 'tenant_id wajib integer positif.',
      });
    }

    try {
      const result = await connectTenantSessionFn({
        tenantId,
        provider,
        accountProviderRef,
        sessionProviderRef,
        callbackClient,
      });

      return res.status(200).json({
        status: 'ok',
        ...result,
        tenant_id: tenantId,
        provider,
        account_provider_ref: accountProviderRef,
        session_provider_ref: sessionProviderRef,
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
      return res.status(500).json({
        status: 'error',
        message,
      });
    }
  });

  app.post('/sessions/disconnect', async (req, res) => {
    const source = req.body ?? {};
    const tenantId = Number.parseInt(String(source.tenant_id ?? ''), 10);
    const provider = typeof source.provider === 'string' && source.provider.trim() !== '' ? source.provider.trim() : null;
    const accountProviderRef = typeof source.account_provider_ref === 'string' && source.account_provider_ref.trim() !== ''
      ? source.account_provider_ref.trim()
      : null;

    if (!Number.isInteger(tenantId) || tenantId <= 0) {
      return res.status(422).json({
        status: 'error',
        message: 'tenant_id wajib integer positif.',
      });
    }

    try {
      const result = await disconnectTenantSessionsFn({
        tenantId,
        provider,
        accountProviderRef,
      });

      return res.status(200).json({
        status: 'ok',
        tenant_id: tenantId,
        provider,
        account_provider_ref: accountProviderRef,
        ...result,
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
      return res.status(500).json({
        status: 'error',
        message,
      });
    }
  });

  app.post('/outbound', async (req, res) => {
    const source = req.body ?? {};
    const tenantId = Number.parseInt(String(source.tenant_id ?? ''), 10);
    const to = typeof source.to === 'string' ? source.to : '';
    const messageType = typeof source.message_type === 'string' ? source.message_type : '';
    const payload = source.payload ?? {};
    const sessionProviderRef = typeof source.session_provider_ref === 'string' ? source.session_provider_ref : null;

    if (!Number.isInteger(tenantId) || tenantId <= 0) {
      return res.status(422).json({
        status: 'error',
        message: 'tenant_id wajib integer positif.',
      });
    }

    try {
      const result = await sendTenantMessageFn({
        tenantId,
        to,
        messageType,
        payload,
        sessionProviderRef,
      });

      return res.status(200).json({
        status: 'sent',
        ...result,
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
      const statusCode = message.includes('wajib') || message.includes('didukung') ? 422 : 500;
      return res.status(statusCode).json({
        status: 'error',
        message,
      });
    }
  });

  return app;
};
