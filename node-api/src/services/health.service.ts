import { env } from '../config/env.js';
import { checkDatabase } from '../repositories/health.repository.js';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import type { HealthStatus, DbStatus } from '../types/api.js';

function serviceVersion(): string {
  try {
    const pkg = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../package.json');
    const parsed = JSON.parse(readFileSync(pkg, 'utf8')) as { version?: string };
    return parsed.version ?? '0.0.0';
  } catch {
    return 'unknown';
  }
}

const VERSION = serviceVersion();

/**
 * Health check: verifies the Node/Express process is alive and the existing
 * MySQL database is reachable. Never throws — DB problems are reported in the
 * payload (HTTP 200 with db.status='down', or HTTP 503, see controller).
 */
export async function getHealth(): Promise<HealthStatus> {
  let db: DbStatus;
  try {
    const { latencyMs } = await checkDatabase(env.health.dbTimeoutMs);
    db = { status: 'up', latencyMs };
  } catch (err) {
    db = {
      status: 'down',
      latencyMs: 0,
      error: err instanceof Error ? err.message : String(err),
    };
  }

  return {
    service: 'hr1-node-api',
    version: VERSION,
    environment: env.nodeEnv,
    uptimeSeconds: Math.round(process.uptime()),
    time: new Date().toISOString(),
    db,
  };
}