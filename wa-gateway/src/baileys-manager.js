import fs from 'fs/promises';
import path from 'path';

import {
  default as makeWASocket,
  DisconnectReason,
  useMultiFileAuthState,
  Browsers,
  fetchLatestBaileysVersion,
} from '@whiskeysockets/baileys';
import { clearTenantQr, setTenantQr } from './qr-store.js';

const sessions = new Map();
const manualDisconnectSessionKeys = new Set();

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

const detectMessageType = (message = {}) => {
  if (typeof message.conversation === 'string') {
    return 'text';
  }

  if (message.extendedTextMessage) {
    return 'text';
  }

  const firstKey = Object.keys(message)[0];
  return firstKey ?? 'unknown';
};

const normalizeTimestamp = (messageTimestamp) => {
  if (typeof messageTimestamp === 'number') {
    return messageTimestamp;
  }

  if (typeof messageTimestamp === 'string') {
    return messageTimestamp;
  }

  if (typeof messageTimestamp?.toString === 'function') {
    return messageTimestamp.toString();
  }

  return Date.now();
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
  const { version } = await fetchLatestBaileysVersion();

  const socket = makeWASocket({
    auth: state,
    browser: Browsers.macOS('SaaS Agent'),
    printQRInTerminal: false,
    version,
  });

  socket.ev.on('creds.update', saveCreds);
  socket.ev.on('connection.update', async (update) => {
    const qrCandidate = typeof update.qr === 'string'
      ? update.qr.trim()
      : (update.qr ? String(update.qr).trim() : '');

    if (qrCandidate !== '') {
      setTenantQr(tenantId, {
        provider,
        qr_code: qrCandidate,
        expires_in_seconds: 60,
        generated_at: new Date().toISOString(),
      });
    } else if (update.connection === 'open') {
      clearTenantQr(tenantId);
    }

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
        session_payload: qrCandidate !== ''
          ? { qr_code: qrCandidate }
          : { connection: update.connection ?? null },
      });
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error('failed forwarding Baileys status:', error);
    }

    if (update.connection === 'close') {
      const statusCode = update.lastDisconnect?.error?.output?.statusCode;
      const wasManualDisconnect = manualDisconnectSessionKeys.has(sessionKey);
      if (wasManualDisconnect) {
        manualDisconnectSessionKeys.delete(sessionKey);
      }
      const shouldReconnect = !wasManualDisconnect && statusCode !== DisconnectReason.loggedOut;
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

  socket.ev.on('messages.upsert', async (event) => {
    if (!event || event.type !== 'notify' || !Array.isArray(event.messages)) {
      return;
    }

    for (const msg of event.messages) {
      if (!msg?.key || msg.key.fromMe) {
        continue;
      }

      const remoteJid = typeof msg.key.remoteJid === 'string' ? msg.key.remoteJid : '';
      if (remoteJid === '' || remoteJid.endsWith('@broadcast') || remoteJid === 'status@broadcast') {
        continue;
      }

      const providerMessageId = typeof msg.key.id === 'string' ? msg.key.id : '';
      if (providerMessageId === '') {
        continue;
      }

      const messageType = detectMessageType(msg.message);
      const payload = {
        key: msg.key,
        message: msg.message ?? {},
        pushName: msg.pushName ?? null,
        messageTimestamp: normalizeTimestamp(msg.messageTimestamp),
      };

      try {
        await callbackClient.sendInboundMessage({
          tenant_id: tenantId,
          provider,
          account_provider_ref: accountProviderRef,
          session_provider_ref: sessionProviderRef,
          provider_message_id: providerMessageId,
          from: remoteJid,
          to: String(socket.user?.id ?? ''),
          message_type: messageType,
          message_timestamp: normalizeTimestamp(msg.messageTimestamp),
          payload,
          meta: { upsert_type: event.type },
        });
      } catch (error) {
        // eslint-disable-next-line no-console
        console.error('failed forwarding inbound message:', error);
      }
    }
  });

  sessions.set(sessionKey, {
    socket,
    authDir,
    tenantId,
    provider,
    accountProviderRef,
    sessionProviderRef,
    sessionKey,
  });

  return { started: true };
};

export const disconnectTenantSessions = async ({ tenantId, provider, accountProviderRef } = {}) => {
  const normalizedTenantId = Number.parseInt(String(tenantId ?? ''), 10);
  if (!Number.isInteger(normalizedTenantId) || normalizedTenantId <= 0) {
    return { disconnected: 0 };
  }

  let disconnected = 0;
  for (const [key, context] of sessions.entries()) {
    if (context.tenantId !== normalizedTenantId) {
      continue;
    }

    if (typeof provider === 'string' && provider.trim() !== '' && context.provider !== provider.trim()) {
      continue;
    }

    if (
      typeof accountProviderRef === 'string'
      && accountProviderRef.trim() !== ''
      && context.accountProviderRef !== accountProviderRef.trim()
    ) {
      continue;
    }

    manualDisconnectSessionKeys.add(key);
    sessions.delete(key);
    clearTenantQr(normalizedTenantId);
    disconnected += 1;

    try {
      context.socket.end(undefined);
    } catch (_error) {
      // noop: socket may already be closed
    }

    try {
      await fs.rm(context.authDir, { recursive: true, force: true });
    } catch (_error) {
      // noop: auth dir cleanup best-effort
    }
  }

  return { disconnected };
};

export const sendTenantMessage = async ({
  tenantId,
  to,
  messageType,
  payload,
  sessionProviderRef = null,
} = {}) => {
  const normalizedTenantId = Number.parseInt(String(tenantId ?? ''), 10);
  if (!Number.isInteger(normalizedTenantId) || normalizedTenantId <= 0) {
    throw new Error('tenant_id wajib integer positif.');
  }

  if (typeof to !== 'string' || to.trim() === '') {
    throw new Error('to wajib string.');
  }

  if (messageType !== 'text') {
    throw new Error('message_type yang didukung saat ini hanya text.');
  }

  const text = typeof payload?.text === 'string' ? payload.text.trim() : '';
  if (text === '') {
    throw new Error('payload.text wajib diisi untuk text message.');
  }

  const preferredSessionRef = typeof sessionProviderRef === 'string' && sessionProviderRef.trim() !== ''
    ? sessionProviderRef.trim()
    : null;

  const contexts = Array.from(sessions.values()).filter((ctx) => ctx.tenantId === normalizedTenantId);
  const context = preferredSessionRef
    ? contexts.find((ctx) => ctx.sessionProviderRef === preferredSessionRef)
    : contexts[0];

  if (!context?.socket) {
    throw new Error('Sesi WhatsApp aktif tidak ditemukan untuk tenant.');
  }

  const sendResult = await context.socket.sendMessage(to.trim(), { text });
  const providerMessageId = typeof sendResult?.key?.id === 'string' ? sendResult.key.id : null;

  return {
    provider_message_id: providerMessageId,
    tenant_id: normalizedTenantId,
    to: to.trim(),
    message_type: 'text',
    session_provider_ref: context.sessionProviderRef,
  };
};
