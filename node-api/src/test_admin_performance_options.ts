import { createApp } from './app.js';
import type { Server } from 'http';

async function runTests() {
  console.log('=== VERIFYING NODE GET /api/v1/admin/performance/options ENDPOINT ===\n');

  const app = createApp();
  const server: Server = app.listen(0);
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const baseUrl = `http://127.0.0.1:${port}`;

  try {
    // Test 1: missing employee_id -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/options`);
      const json = (await res.json()) as any;
      console.log('Test 1: missing employee_id -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Validation failed.' ? 'PASS' : 'FAIL');
      console.log(
        '  Error:',
        json.errors?.employee_id === 'employee_id query parameter is required.' ? 'PASS' : 'FAIL'
      );
      console.log();
    }

    // Test 2: employee_id=0 -> 422 (same required message, PHP has no separate "positive" message here)
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/options?employee_id=0`);
      const json = (await res.json()) as any;
      console.log('Test 2: employee_id=0 -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log(
        '  Error:',
        json.errors?.employee_id === 'employee_id query parameter is required.' ? 'PASS' : 'FAIL'
      );
      console.log();
    }

    // Test 3: employee_id=abc -> PHP (int)"abc" === 0 -> 422 required (not "must be an integer")
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/options?employee_id=abc`);
      const json = (await res.json()) as any;
      console.log('Test 3: employee_id=abc -> 422 (PHP (int) cast parity, not integer-format validation)');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log(
        '  Error:',
        json.errors?.employee_id === 'employee_id query parameter is required.' ? 'PASS' : 'FAIL'
      );
      console.log();
    }

    // Test 4: employee_id=18abc -> PHP (int)"18abc" === 18 -> 200 (truncating cast parity)
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/options?employee_id=18abc`);
      const json = (await res.json()) as any;
      console.log('Test 4: employee_id=18abc -> 200 (PHP (int) truncating-cast parity)');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  data.employee_id === 18:', json.data?.employee_id === 18 ? 'PASS' : `FAIL (${json.data?.employee_id})`);
      console.log();
    }

    // Test 5: valid employee_id -> 200 envelope + shape
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/options?employee_id=18`);
      const json = (await res.json()) as any;
      console.log('Test 5: GET /api/v1/admin/performance/options?employee_id=18');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Options retrieved successfully.' ? 'PASS' : 'FAIL');
      console.log('  data.employee_id === 18:', json.data?.employee_id === 18 ? 'PASS' : 'FAIL');
      console.log('  data.goals is array:', Array.isArray(json.data?.goals) ? 'PASS' : 'FAIL');
      console.log('  data.ratings is array:', Array.isArray(json.data?.ratings) ? 'PASS' : 'FAIL');
      console.log('  ratings count === 15:', json.data?.ratings?.length === 15 ? 'PASS' : `FAIL (${json.data?.ratings?.length})`);
      const firstRating = json.data?.ratings?.[0];
      console.log(
        '  rating shape (scale_code/value/label):',
        typeof firstRating?.scale_code === 'string' &&
          typeof firstRating?.value === 'number' &&
          typeof firstRating?.label === 'string'
          ? 'PASS'
          : 'FAIL'
      );
      console.log();
    }

    console.log('=== ALL NODE GET /api/v1/admin/performance/options TESTS COMPLETED ===');
  } finally {
    server.close();
    process.exit(0);
  }
}

runTests().catch((err) => {
  console.error('Test execution error:', err);
  process.exit(1);
});
