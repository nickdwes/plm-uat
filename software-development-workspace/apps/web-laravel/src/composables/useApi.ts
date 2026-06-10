import { useOrganization } from './useOrganization';
import { getToken } from '../stores/auth';

interface ApiOptions {
  org?: string | number;
  headers?: Record<string, string>;
  body?: any;
}

export function useApi() {
  const { organization } = useOrganization();

  const api = async (
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    endpoint: string,
    options: ApiOptions = {}
  ) => {
    const orgId = options.org || organization.value;
    if (!orgId) {
      throw new Error('Organization ID is required');
    }

    const token = getToken();
    if (!token) {
      throw new Error('No authentication token found');
    }

    const url = endpoint.startsWith('http') ? endpoint : endpoint;
    const headers: Record<string, string> = {
      'Authorization': `Bearer ${token}`,
      'X-Organization': String(orgId),
      'Accept': 'application/json',
      ...options.headers,
    };

    if (options.body && (method === 'POST' || method === 'PATCH' || method === 'PUT')) {
      headers['Content-Type'] = 'application/json';
    }

    const config: RequestInit = {
      method,
      headers,
    };

    if (options.body && (method === 'POST' || method === 'PATCH' || method === 'PUT')) {
      config.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, config);

    if (!response.ok) {
      const error = await response.json().catch(() => ({ message: response.statusText }));
      throw new Error(error.message || `API error: ${response.status}`);
    }

    const data = await response.json();
    return data;
  };

  return {
    api,
    async get(endpoint: string, options: ApiOptions = {}) {
      return api('GET', endpoint, options);
    },
    async post(endpoint: string, body: any, options: ApiOptions = {}) {
      return api('POST', endpoint, { ...options, body });
    },
    async patch(endpoint: string, body: any, options: ApiOptions = {}) {
      return api('PATCH', endpoint, { ...options, body });
    },
    async put(endpoint: string, body: any, options: ApiOptions = {}) {
      return api('PUT', endpoint, { ...options, body });
    },
    async delete(endpoint: string, options: ApiOptions = {}) {
      return api('DELETE', endpoint, options);
    },
  };
}
