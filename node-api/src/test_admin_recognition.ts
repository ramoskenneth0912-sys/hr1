import { createApp } from './app.js';
import type { Server } from 'http';

async function runTests() {
  console.log('=== VERIFYING NODE GET /api/v1/admin/recognition ENDPOINT ===\n');

  const app = createApp();
  const server: Server = app.listen(0);
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const baseUrl = `http://127.0.0.1:${port}`;

  try {
    // Test 1: list envelope
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition`);
      const json = (await res.json()) as any;
      console.log('Test 1: GET /api/v1/admin/recognition');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Recognition records retrieved successfully.' ? 'PASS' : 'FAIL');
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log('  Meta page/limit/total:', json.meta?.page, json.meta?.limit, json.meta?.total);
      console.log();
    }

    // Test 2: field shape
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition`);
      const json = (await res.json()) as any;
      const first = json.data?.[0];
      console.log('Test 2: Field shapes');
      console.log('  id is number:', typeof first?.id === 'number' ? 'PASS' : `FAIL (${typeof first?.id})`);
      console.log(
        '  recipient_employee_id is number:',
        typeof first?.recipient_employee_id === 'number' ? 'PASS' : `FAIL (${typeof first?.recipient_employee_id})`
      );
      console.log('  given_by is string:', typeof first?.given_by === 'string' ? 'PASS' : `FAIL (${typeof first?.given_by})`);
      console.log('  recipient_name is string:', typeof first?.recipient_name === 'string' ? 'PASS' : 'FAIL');
      console.log('  given_by (username fallback):', first?.given_by);
      console.log();
    }

    // Test 3: status filter
    {
      const published = (await (await fetch(`${baseUrl}/api/v1/admin/recognition?status=published`)).json()) as any;
      const draft = (await (await fetch(`${baseUrl}/api/v1/admin/recognition?status=draft`)).json()) as any;
      console.log('Test 3: status filter');
      console.log('  published total === 1:', published.meta?.total === 1 ? 'PASS' : `FAIL (${published.meta?.total})`);
      console.log('  draft total === 0:', draft.meta?.total === 0 ? 'PASS' : `FAIL (${draft.meta?.total})`);
      console.log();
    }

    // Test 4: invalid status -> 422
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition?status=bogus`);
      const json = (await res.json()) as any;
      console.log('Test 4: status=bogus -> 422');
      console.log('  Status 422:', res.status === 422 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Error:', json.errors?.status === 'Invalid recognition status.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 5: recipient_employee_id validation
    {
      const nonInt = await fetch(`${baseUrl}/api/v1/admin/recognition?recipient_employee_id=abc`);
      const nonIntJson = (await nonInt.json()) as any;
      const zero = await fetch(`${baseUrl}/api/v1/admin/recognition?recipient_employee_id=0`);
      const zeroJson = (await zero.json()) as any;
      console.log('Test 5: recipient_employee_id validation');
      console.log('  abc -> 422:', nonInt.status === 422 ? 'PASS' : `FAIL (${nonInt.status})`);
      console.log(
        '  abc error:',
        nonIntJson.errors?.recipient_employee_id === 'Recipient employee ID must be an integer.' ? 'PASS' : 'FAIL'
      );
      console.log('  0 -> 422:', zero.status === 422 ? 'PASS' : `FAIL (${zero.status})`);
      console.log(
        '  0 error:',
        zeroJson.errors?.recipient_employee_id === 'Recipient employee ID must be positive.' ? 'PASS' : 'FAIL'
      );
      console.log();
    }

    // Test 6: recipient filter (live row recipient id)
    {
      const list = (await (await fetch(`${baseUrl}/api/v1/admin/recognition`)).json()) as any;
      const recipientId = list.data?.[0]?.recipient_employee_id;
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition?recipient_employee_id=${recipientId}`);
      const json = (await res.json()) as any;
      console.log(`Test 6: recipient_employee_id=${recipientId} filter`);
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Matches row:', json.meta?.total === 1 && json.data?.[0]?.recipient_employee_id === recipientId ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 7: pagination
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition?page=2&limit=1`);
      const json = (await res.json()) as any;
      const clamped = (await (await fetch(`${baseUrl}/api/v1/admin/recognition?limit=500`)).json()) as any;
      console.log('Test 7: pagination');
      console.log('  page=2 limit=1 -> 200:', res.status === 200 ? 'PASS' : 'FAIL');
      console.log('  page=2 meta.page === 2:', json.meta?.page === 2 ? 'PASS' : 'FAIL');
      console.log('  limit=500 clamped to 100:', clamped.meta?.limit === 100 ? 'PASS' : `FAIL (${clamped.meta?.limit})`);
      console.log();
    }

    // Test 8: show — existing id -> 200 detail matches list shape
    {
      const list = (await (await fetch(`${baseUrl}/api/v1/admin/recognition`)).json()) as any;
      const id = list.data?.[0]?.id;
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition/${id}`);
      const json = (await res.json()) as any;
      console.log(`Test 8: GET /api/v1/admin/recognition/${id} (existing)`);
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log(
        '  Message:',
        json.message === 'Recognition record retrieved successfully.' ? 'PASS' : 'FAIL'
      );
      console.log('  data.id matches:', json.data?.id === id ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 9: show — missing id -> 404 Recognition record not found.
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition/99999999`);
      const json = (await res.json()) as any;
      console.log('Test 9: GET /api/v1/admin/recognition/99999999 (missing)');
      console.log('  Status 404:', res.status === 404 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Recognition record not found.' ? 'PASS' : 'FAIL');
      console.log('  Errors envelope:', json.errors && Object.keys(json.errors).length === 0 ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 10: show — non-numeric id -> PHP router fall-through 404
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/recognition/abc`);
      const json = (await res.json()) as any;
      console.log('Test 10: GET /api/v1/admin/recognition/abc (non-numeric)');
      console.log('  Status 404:', res.status === 404 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Endpoint not found.' ? 'PASS' : 'FAIL');
      console.log('  Errors envelope:', json.errors && Object.keys(json.errors).length === 0 ? 'PASS' : 'FAIL');
      console.log();
    }

    console.log('=== ALL NODE GET /api/v1/admin/recognition TESTS COMPLETED ===');
  } finally {
    server.close();
    process.exit(0);
  }
}

runTests().catch((err) => {
  console.error('Test execution error:', err);
  process.exit(1);
});
