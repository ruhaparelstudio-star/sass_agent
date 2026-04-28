import express from 'express';

import { createCallbackClient } from './callback-client.js';
import { validateStatusPayload } from './status-contract.js';

export const createApp = ({ callbackClient = createCallbackClient() } = {}) => {
  const app = express();
  app.use(express.json());

  app.get('/health', (_req, res) => {
    return res.status(200).json({
      service: 'wa-gateway',
      status: 'ok',
      timestamp: new Date().toISOString(),
    });
  });

  app.get('/qr', (req, res) => {
    const tenantIdRaw = req.query.tenant_id;
    const tenantId = Number.parseInt(String(tenantIdRaw ?? '1'), 10);

    return res.status(200).json({
      tenant_id: Number.isInteger(tenantId) && tenantId > 0 ? tenantId : 1,
      provider: 'meta',
      qr_code: 'dummy-wa-qr-v1',
      expires_in_seconds: 60,
      generated_at: new Date().toISOString(),
    });
  });

  app.post('/callbacks/status', async (req, res) => {
    try {
      const validatedPayload = validateStatusPayload(req.body);
      const result = await callbackClient.sendStatus(validatedPayload);

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

  return app;
};
