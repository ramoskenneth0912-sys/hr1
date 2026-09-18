import { config as loadEnv } from 'dotenv';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const defaultEnvFile = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../.env');
loadEnv({ path: process.env.ENV_FILE ?? defaultEnvFile });

function required(name: string, fallback: string | number): string {
  const raw = process.env[name];
  return raw !== undefined && raw !== '' ? raw : String(fallback);
}

function int(name: string, fallback: number): number {
  const raw = Number(process.env[name]);
  return Number.isFinite(raw) && raw > 0 ? raw : fallback;
}

function bool(name: string, fallback: boolean): boolean {
  return (process.env[name] ?? (fallback ? 'true' : 'false')).toLowerCase() === 'true';
}

export interface AppEnv {
  nodeEnv: string;
  port: number;
  apiBase: string;
  corsOrigin: string;
  db: {
    host: string;
    port: number;
    name: string;
    user: string;
    pass: string;
    connectionLimit: number;
  };
  health: {
    dbTimeoutMs: number;
  };
  auth: {
    secret: string;
  };
}

export const env: AppEnv = {
  nodeEnv: required('NODE_ENV', 'development'),
  port: int('PORT', 4000),
  apiBase: required('API_BASE', '/api/v1'),
  corsOrigin: required('CORS_ORIGIN', 'http://localhost:5174'),
  db: {
    host: required('DB_HOST', 'localhost'),
    port: int('DB_PORT', 3306),
    name: required('DB_NAME', 'hr1_database'),
    user: required('DB_USER', 'root'),
    pass: process.env.DB_PASS ?? '',
    connectionLimit: int('DB_CONNECTION_LIMIT', 10),
  },
  health: {
    dbTimeoutMs: int('HEALTH_DB_TIMEOUT_MS', 5000),
  },
  auth: {
    secret: required('AUTH_SECRET', ''),
  },
};

if (!env.auth.secret) {
  // eslint-disable-next-line no-console
  console.warn('[env] AUTH_SECRET is not set — Auth.js session signing/encryption will fail at runtime.');
}

export const isProduction = env.nodeEnv === 'production';
export const isTesting = bool('NODE_ENV', false);