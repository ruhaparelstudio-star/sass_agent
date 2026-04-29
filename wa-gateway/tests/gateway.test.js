import test from 'node:test';
import assert from 'node:assert/strict';

import { createApp } from '../src/app.js';
import { createCallbackClient } from '../src/callback-client.js';

const startServer = (app) =>
  new Promise((resolve) => {
    const server = app.listen(0, '127.0.0.1', () => resolve(server));
  });

const stopServer = (server) =>
  new Promise((resolve, reject) => {
    server.close((error) => (error ? reject(error) : resolve()));
  });

test('GET /health returns 200 and expected shape', async () => {
  const app = createApp({
    callbackClient: {
      sendStatus: async () => ({}),
    },
  });
  const server = await startServer(app);

  try {
    const address = server.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/health`);
    const body = await response.json();

    assert.equal(response.status, 200);
    assert.equal(body.service, 'wa-gateway');
    assert.equal(body.status, 'ok');
    assert.ok(typeof body.timestamp === 'string');
  } finally {
    await stopServer(server);
  }
});

test('GET /qr returns unavailable when no QR exists for tenant', async () => {
  const app = createApp({
    callbackClient: {
      sendStatus: async () => ({}),
    },
  });
  const server = await startServer(app);

  try {
    const address = server.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/qr?tenant_id=7`);
    const body = await response.json();

    assert.equal(response.status, 404);
    assert.equal(body.status, 'unavailable');
  } finally {
    await stopServer(server);
  }
});

test('GET /qr returns available QR after status callback with pending session', async () => {
  const app = createApp({
    callbackClient: {
      sendStatus: async () => ({}),
    },
  });
  const server = await startServer(app);

  try {
    const address = server.address();
    await fetch(`http://127.0.0.1:${address.port}/callbacks/status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenant_id: 7,
        provider: 'meta',
        account_provider_ref: 'acct-001',
        account_status: 'connecting',
        account_payload: { event: 'account.connecting' },
        session_provider_ref: 'sess-001',
        session_status: 'pending',
        session_payload: { qr_code: 'REAL-QR-PAYLOAD-123' },
      }),
    });

    const response = await fetch(`http://127.0.0.1:${address.port}/qr?tenant_id=7`);
    const body = await response.json();

    assert.equal(response.status, 200);
    assert.equal(body.tenant_id, 7);
    assert.equal(body.provider, 'meta');
    assert.equal(body.qr_code, 'REAL-QR-PAYLOAD-123');
    assert.equal(body.expires_in_seconds, 60);
    assert.ok(typeof body.generated_at === 'string');
  } finally {
    await stopServer(server);
  }
});

test('callback client sends account and session payload with internal secret header', async () => {
  const calls = [];
  const fakeHttpClient = {
    post: async (url, body, options) => {
      calls.push({ url, body, options });
      return { data: { ok: true } };
    },
  };

  const client = createCallbackClient({
    httpClient: fakeHttpClient,
    baseUrl: 'http://laravel.test',
    internalSecret: 'test-secret',
  });

  await client.sendStatus({
    tenant_id: 11,
    provider: 'meta',
    account_provider_ref: 'acct-001',
    account_status: 'connected',
    account_phone: '+6200001',
    account_payload: { event: 'account.connected' },
    session_provider_ref: 'sess-001',
    session_status: 'active',
    session_payload: { event: 'session.active' },
  });

  assert.equal(calls.length, 2);
  assert.equal(calls[0].url, 'http://laravel.test/api/internal/whatsapp/accounts/upsert');
  assert.equal(calls[1].url, 'http://laravel.test/api/internal/whatsapp/sessions/upsert');
  assert.equal(calls[0].options.headers['X-Internal-Secret'], 'test-secret');
  assert.equal(calls[1].options.headers['X-Internal-Secret'], 'test-secret');
});

test('POST /callbacks/status rejects invalid payload before forwarding', async () => {
  let forwarded = false;
  const app = createApp({
    callbackClient: {
      sendStatus: async () => {
        forwarded = true;
      },
    },
  });
  const server = await startServer(app);

  try {
    const address = server.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/callbacks/status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenant_id: 1,
      }),
    });
    const body = await response.json();

    assert.equal(response.status, 422);
    assert.equal(body.status, 'error');
    assert.equal(forwarded, false);
  } finally {
    await stopServer(server);
  }
});
