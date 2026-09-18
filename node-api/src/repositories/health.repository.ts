import { pingDatabase } from '../config/database.js';

/**
 * Data-access layer for the health check.
 * Phase 2 is strictly read-only: the only query is `SELECT 1` against the
 * existing hr1_database.
 */
export async function checkDatabase(timeoutMs: number): Promise<{ latencyMs: number }> {
  return pingDatabase(timeoutMs);
}