import { createApp } from './app.js';
import type { Server } from 'http';

async function runTests() {
  console.log('=== VERIFYING NODE GET /api/v1/admin/goals ENDPOINT ===\n');

  const app = createApp();
  const server: Server = app.listen(0);
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const baseUrl = `http://127.0.0.1:${port}`;

  try {
    // Test 1: list envelope (employee_goals is empty in this database)
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals`);
      const json = (await res.json()) as any;
      console.log('Test 1: GET /api/v1/admin/goals');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Goals retrieved successfully.' ? 'PASS' : 'FAIL');
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log('  Meta page 1:', json.meta?.page === 1 ? 'PASS' : 'FAIL');
      console.log('  Meta limit == total:', json.meta?.limit === json.meta?.total ? 'PASS' : 'FAIL');
      console.log('  Meta total_pages 1:', json.meta?.total_pages === 1 ? 'PASS' : 'FAIL');
      console.log('  Total:', json.meta?.total);
      console.log();
    }

    // Test 2: valid status filter
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals?status=in_progress`);
      const json = (await res.json()) as any;
      console.log('Test 2: valid status filter');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 3: invalid status filter -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals?status=bogus`);
      const json = (await res.json()) as any;
      console.log('Test 3: invalid status -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Validation failed.' ? 'PASS' : 'FAIL');
      console.log('  Error:', json.errors?.status === 'Invalid goal status.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 4: non-integer employee_id -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals?employee_id=abc`);
      const json = (await res.json()) as any;
      console.log('Test 4: employee_id=abc -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Error:', json.errors?.employee_id === 'Employee ID must be an integer.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 5: non-positive employee_id -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals?employee_id=0`);
      const json = (await res.json()) as any;
      console.log('Test 5: employee_id=0 -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Error:', json.errors?.employee_id === 'Employee ID must be positive.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 6: valid employee_id applies the filter
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals?employee_id=18`);
      const json = (await res.json()) as any;
      console.log('Test 6: valid employee_id filter');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log('  (out-of-scope 403 is deferred with the auth layer)');
      console.log();
    }

    // Test 7: show — missing id -> 404 Goal not found.
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals/99999999`);
      const json = (await res.json()) as any;
      console.log('Test 7: GET /api/v1/admin/goals/99999999 (missing)');
      console.log('  Status 404:', res.status === 404 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Goal not found.' ? 'PASS' : 'FAIL');
      console.log('  Errors envelope:', json.errors && Object.keys(json.errors).length === 0 ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 8: show — non-numeric id -> PHP router fall-through 404
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/goals/abc`);
      const json = (await res.json()) as any;
      console.log('Test 8: GET /api/v1/admin/goals/abc (non-numeric)');
      console.log('  Status 404:', res.status === 404 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Endpoint not found.' ? 'PASS' : 'FAIL');
      console.log('  Errors envelope:', json.errors && Object.keys(json.errors).length === 0 ? 'PASS' : 'FAIL');
      console.log('  (employee_goals is empty, so the 200 detail path is verified via shape parity)');
      console.log();
    }

    console.log('=== ALL NODE GET /api/v1/admin/goals TESTS COMPLETED ===');
  } finally {
    server.close();
    process.exit(0);
  }
}

runTests().catch((err) => {
  console.error('Test execution error:', err);
  process.exit(1);
});
