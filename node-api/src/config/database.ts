import mysql, { type Pool, type PoolOptions } from 'mysql2/promise';
import { env } from './env.js';

/**
 * MySQL connection pool bound to the EXISTING hr1_database.
 *
 * Phase 2 is read-only: the configured user is a dedicated `hr1_node` account
 * granted SELECT only (never DDL/DML). No tables are created or modified here.
 */
let pool: Pool | null = null;

function buildPool(): Pool {
  const options: PoolOptions = {
    host: env.db.host,
    port: env.db.port,
    database: env.db.name,
    user: env.db.user,
    password: env.db.pass,
    waitForConnections: true,
    connectionLimit: env.db.connectionLimit,
    queueLimit: 0,
    enableKeepAlive: true,
    charset: 'utf8mb4',
    timezone: 'Z',
    dateStrings: true,
  };

  return mysql.createPool(options);
}

/** Lazily-created shared pool. */
export function getPool(): Pool {
  if (!pool) pool = buildPool();
  return pool;
}

/** Execute `SELECT 1` to verify the connection is alive. Returns latency in ms. */
export async function pingDatabase(timeoutMs: number): Promise<{ latencyMs: number }> {
  const started = performance.now();
  const [rows] = await getPool().query('SELECT 1 AS ok');
  const latencyMs = Math.round((performance.now() - started) * 100) / 100;
  if (!Array.isArray(rows) || rows.length !== 1 || (rows[0] as { ok?: number }).ok !== 1) {
    throw new Error('Unexpected SELECT 1 result.');
  }
  return { latencyMs };
}

/** Close the pool (used on graceful shutdown / tests). */
export async function closePool(): Promise<void> {
  if (pool) {
    await pool.end();
    pool = null;
  }
}