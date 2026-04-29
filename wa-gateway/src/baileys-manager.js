import fs from 'fs/promises';
import path from 'path';

import {
  default as makeWASocket,
  DisconnectReason,
  useMultiFileAuthState,
  Browsers,
} from '@whiskeysockets/baileys';

const sessions = new Map();

const toAuthDir = (tenantId, sessionRef) => path.join('/tmp', 'wa-gateway-auth', String(tenantId), String(sessionRef));

const toStatus = (connection) => {
  if (connection === 'open') {
    return 'active';
  }

  if (connection === 'close') {
    return 'closed';
  }

  return 'pending';
};

export const connectTenantSession = async ({
  tenantId,
  provider,
  accountProviderRef,
  sessionProviderRef,
  callbackClient,
}) => {
  const sessionKey = `${tenantId}:${sessionProviderRef}`;
  if (sessions.has(sessionKey)) {
    return { started: false, reason: 'already_connected' };
  }

  const authDir = toAuthDir(tenantId, sessionProviderRef);
  await fs.mkdir(authDir, { recursive: true });
  const { state, saveCreds } = await useMultiFileAuthState(authDir);

  const socket = makeWASocket({
    auth: state,
    browser: Browsers.macOS('SaaS Agent'),
    printQRInTerminal: false,
  });

  socket.ev.on('creds.update', saveCreds);
  socket.ev.on('connection.update', async (update) => {
    try {
      const status = toStatus(update.connection);
      const accountStatus = status === 'active' ? 'connected' : status === 'closed' ? 'disconnected' : 'connecting';

      await callbackClient.sendStatus({
        tenant_id: tenantId,
        provider,
        account_provider_ref: accountProviderRef,
        account_status: accountStatus,
        account_payload: {
          event: 'baileys.connection.update',
          connection: update.connection ?? null,
        },
        session_provider_ref: sessionProviderRef,
        session_status: status,
        session_payload: update.qr
          ? { qr_code: update.qr }
          : { connection: update.connection ?? null },
      });
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error('failed forwarding Baileys status:', error);
    }

    if (update.connection === 'close') {
      const statusCode = update.lastDisconnect?.error?.output?.statusCode;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
      sessions.delete(sessionKey);

      if (shouldReconnect) {
        setTimeout(() => {
          connectTenantSession({
            tenantId,
            provider,
            accountProviderRef,
            sessionProviderRef,
            callbackClient,
          }).catch((error) => {
            // eslint-disable-next-line no-console
            console.error('failed reconnecting Baileys session:', error);
          });
        }, 1000);
      }
    }
  });

  sessions.set(sessionKey, { socket, authDir });

  return { started: true };
};
