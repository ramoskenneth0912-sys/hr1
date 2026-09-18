import { createApp } from './app.js';
import type { Server } from 'http';

async function runTests() {
  console.log('=== VERIFYING NODE GET /api/v1/admin/performance/reviews ENDPOINT ===\n');

  const app = createApp();
  const server: Server = app.listen(0);
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const baseUrl = `http://127.0.0.1:${port}`;

  try {
    // Test 1: list envelope (performance_reviews is empty in this database)
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews`);
      const json = (await res.json()) as any;
      console.log('Test 1: GET /api/v1/admin/performance/reviews');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log(
        '  Message:',
        json.message === 'Performance reviews retrieved successfully.' ? 'PASS' : 'FAIL'
      );
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log('  Meta page 1:', json.meta?.page === 1 ? 'PASS' : 'FAIL');
      console.log('  Meta limit == total:', json.meta?.limit === json.meta?.total ? 'PASS' : 'FAIL');
      console.log('  Meta total_pages 1:', json.meta?.total_pages === 1 ? 'PASS' : 'FAIL');
      console.log('  Total:', json.meta?.total);
      console.log();
    }

    // Test 2: valid status filter
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?status=finalized`);
      const json = (await res.json()) as any;
      console.log('Test 2: valid status filter');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 3: invalid status filter -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?status=bogus`);
      const json = (await res.json()) as any;
      console.log('Test 3: invalid status -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Validation failed.' ? 'PASS' : 'FAIL');
      console.log(
        '  Error:',
        json.errors?.status ===
          'status must be one of: drafted, assigned, self_assessment, manager_review, finalized, acknowledged.'
          ? 'PASS'
          : 'FAIL'
      );
      console.log();
    }

    // Test 4: non-whole-number period_id -> 422 (PHP intOrError: /^\d+$/ only)
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?period_id=abc`);
      const json = (await res.json()) as any;
      console.log('Test 4: period_id=abc -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Error:', json.errors?.period_id === 'period_id must be a whole number.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 5: negative period_id -> 422 (leading '-' fails the digit-only regex)
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?period_id=-1`);
      const json = (await res.json()) as any;
      console.log('Test 5: period_id=-1 -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Error:', json.errors?.period_id === 'period_id must be a whole number.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 6: valid period_id (whole number, including 0) applies the filter
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?period_id=0`);
      const json = (await res.json()) as any;
      console.log('Test 6: period_id=0 (valid whole number per PHP intOrError)');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 7: non-whole-number department_id -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?department_id=abc`);
      const json = (await res.json()) as any;
      console.log('Test 7: department_id=abc -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log(
        '  Error:',
        json.errors?.department_id === 'department_id must be a whole number.' ? 'PASS' : 'FAIL'
      );
      console.log();
    }

    // Test 8: non-whole-number employee_id -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/performance/reviews?employee_id=abc`);
      const json = (await res.json()) as any;
      console.log('Test 8: employee_id=abc -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Error:', json.errors?.employee_id === 'employee_id must be a whole number.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 9: valid employee_id filter, combined with valid department_id
    {
      const res = await fetch(
        `${baseUrl}/api/v1/admin/performance/reviews?employee_id=18&department_id=1`
      );
      const json = (await res.json()) as any;
      console.log('Test 9: valid employee_id + department_id filters');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log('  (manager scope 403/filter is deferred with the auth layer)');
      console.log();
    }

    console.log('=== ALL NODE GET /api/v1/admin/performance/reviews TESTS COMPLETED ===');
  } finally {
    server.close();
    process.exit(0);
  }
}

runTests().catch((err) => {
  console.error('Test execution error:', err);
  process.exit(1);
});
