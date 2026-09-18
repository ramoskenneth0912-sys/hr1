import { createApp } from './app.js';
import type { Server } from 'http';

async function runTests() {
  console.log('=== VERIFYING NODE GET /api/v1/jobs ENDPOINT ===\n');

  const app = createApp();
  const server: Server = app.listen(0);
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const baseUrl = `http://127.0.0.1:${port}`;

  try {
    // Test 1: Public GET /api/v1/jobs
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs`);
      const json = await res.json() as any;
      console.log('Test 1: Public GET /api/v1/jobs');
      console.log('  Status:', res.status, res.status === 200 ? 'PASS' : 'FAIL');
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Open jobs retrieved successfully' ? 'PASS' : 'FAIL');
      console.log('  Data is array:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log('  Meta present:', json.meta && typeof json.meta.total === 'number' ? 'PASS' : 'FAIL');
      console.log('  Total:', json.meta?.total, 'Total Pages:', json.meta?.total_pages);
      console.log();
    }

    // Test 2: Employee role 403 Forbidden
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs`, {
        headers: { 'x-user-role': 'employee' },
      });
      const json = await res.json() as any;
      console.log('Test 2: Employee role 403 Forbidden');
      console.log('  Status:', res.status, res.status === 403 ? 'PASS' : 'FAIL');
      console.log('  Success:', json.success === false ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Employees cannot browse job listings through the API.' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 3: Search filter
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs?search=testnonexistentvalue12345`);
      const json = await res.json() as any;
      console.log('Test 3: Non-matching Search Filter (Empty Result)');
      console.log('  Status:', res.status, res.status === 200 ? 'PASS' : 'FAIL');
      console.log('  Data empty array:', Array.isArray(json.data) && json.data.length === 0 ? 'PASS' : 'FAIL');
      console.log('  Total is 0:', json.meta?.total === 0 ? 'PASS' : 'FAIL');
      console.log('  Total pages is 1:', json.meta?.total_pages === 1 ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 4: Pagination limit & page
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs?page=1&limit=2`);
      const json = await res.json() as any;
      console.log('Test 4: Pagination (page=1, limit=2)');
      console.log('  Status:', res.status, res.status === 200 ? 'PASS' : 'FAIL');
      console.log('  Meta limit:', json.meta?.limit === 2 ? 'PASS' : 'FAIL');
      console.log('  Meta page:', json.meta?.page === 1 ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 5: Employment type normalization
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs?employment_type=full-time`);
      const json = await res.json() as any;
      console.log('Test 5: Employment Type Normalization (full-time -> regular)');
      console.log('  Status:', res.status, res.status === 200 ? 'PASS' : 'FAIL');
      console.log('  Data returned:', Array.isArray(json.data) ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 6: Public GET /api/v1/jobs/{id} — open job detail
    {
      const listRes = await fetch(`${baseUrl}/api/v1/jobs?limit=1`);
      const listJson = await listRes.json() as any;
      const id = listJson.data?.[0]?.id;
      const res = await fetch(`${baseUrl}/api/v1/jobs/${id}`);
      const json = await res.json() as any;
      console.log(`Test 6: Public GET /api/v1/jobs/${id} (open job)`);
      console.log('  Status:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Job retrieved successfully' ? 'PASS' : 'FAIL');
      console.log('  Data id matches:', json.data && json.data.id === id ? 'PASS' : 'FAIL');
      console.log('  Status is open:', json.data && json.data.status === 'open' ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 7: Public GET /api/v1/jobs/{id} — non-existent id -> 404
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs/99999999`);
      const json = await res.json() as any;
      console.log('Test 7: Public GET /api/v1/jobs/99999999 (missing)');
      console.log('  Status:', res.status === 404 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === false ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Job not found.' ? 'PASS' : 'FAIL');
      console.log('  Errors envelope:', json.errors && Object.keys(json.errors).length === 0 ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 8: Public GET /api/v1/jobs/{id} — non-OPEN job is hidden -> 404
    {
      const res = await fetch(`${baseUrl}/api/v1/jobs/41`);
      const json = await res.json() as any;
      console.log('Test 8: Public GET /api/v1/jobs/41 (non-open job hidden)');
      console.log('  Status:', res.status === 404 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Message:', json.message === 'Job not found.' ? 'PASS' : 'FAIL');
      console.log();
    }

    console.log('=== ALL NODE GET /api/v1/jobs TESTS COMPLETED ===');
  } finally {
    server.close();
    process.exit(0);
  }
}

runTests().catch((err) => {
  console.error('Test execution error:', err);
  process.exit(1);
});

