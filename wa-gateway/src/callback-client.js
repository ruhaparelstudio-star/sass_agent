import axios from 'axios';

import { config } from './config.js';
import { validateStatusPayload } from './status-contract.js';

const buildHeaders = ({ internalSecret, internalAuthToken }) => {
  const headers = {
    'Content-Type': 'application/json',
  };

  if (internalSecret !== '') {
    headers['X-Internal-Secret'] = internalSecret;
  }

  if (internalAuthToken !== '') {
    headers.Authorization = `Bearer ${internalAuthToken}`;
  }

  return headers;
};

const toAccountBody = (validated) => ({
  tenant_id: validated.tenant_id,
  provider: validated.provider,
  provider_ref: validated.account_provider_ref,
  phone: validated.account_phone,
  status: validated.account_status,
  payload: validated.account_payload,
  ...(validated.account_meta ? { meta: validated.account_meta } : {}),
});

const toSessionBody = (validated) => ({
  tenant_id: validated.tenant_id,
  wa_account_provider_ref: validated.account_provider_ref,
  provider: validated.provider,
  provider_ref: validated.session_provider_ref,
  status: validated.session_status,
  payload: validated.session_payload,
  ...(validated.session_meta ? { meta: validated.session_meta } : {}),
});

export const createCallbackClient = ({
  httpClient = axios,
  baseUrl = config.laravelBaseUrl,
  internalSecret = config.internalSecret,
  internalAuthToken = config.internalAuthToken,
} = {}) => {
  const headers = buildHeaders({ internalSecret, internalAuthToken });

  return {
    async sendStatus(payload) {
      const validated = validateStatusPayload(payload);

      const accountResponse = await httpClient.post(
        `${baseUrl}/api/internal/whatsapp/accounts/upsert`,
        toAccountBody(validated),
        { headers }
      );

      const sessionResponse = await httpClient.post(
        `${baseUrl}/api/internal/whatsapp/sessions/upsert`,
        toSessionBody(validated),
        { headers }
      );

      return {
        account: accountResponse.data,
        session: sessionResponse.data,
      };
    },
  };
};
