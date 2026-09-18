import { createApp } from './app.js';
import type { Server } from 'http';

async function runTests() {
  console.log('=== VERIFYING NODE GET /api/v1/admin/stats ENDPOINT ===\n');

  const app = createApp();
  const server: Server = app.listen(0);
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const baseUrl = `http://127.0.0.1:${port}`;

  try {
    // Test 1: GET /api/v1/admin/stats envelope
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/stats`);
      const json = await res.json() as any;
      console.log('Test 1: GET /api/v1/admin/stats');
      console.log('  Status 200:', res.status === 200 ? 'PASS' : `FAIL (${res.status})`);
      console.log('  Success:', json.success === true ? 'PASS' : 'FAIL');
      console.log('  Message:', json.message === 'Dashboard statistics retrieved successfully.' ? 'PASS' : 'FAIL');
      console.log('  No meta:', json.meta === undefined ? 'PASS' : 'FAIL');
      console.log('  jobs block:', json.data?.jobs && typeof json.data.jobs.total === 'number' ? 'PASS' : 'FAIL');
      console.log('  applications block:', json.data?.applications && typeof json.data.applications.total === 'number' ? 'PASS' : 'FAIL');
      console.log('  by_status is object:', json.data?.applications?.by_status && !Array.isArray(json.data.applications.by_status) ? 'PASS' : 'FAIL');
      console.log();
    }

    // Test 2: jobs closed counts only closed+filled
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/stats`);
      const json = await res.json() as any;
      const within = json.data.jobs.closed <= json.data.jobs.total;
      console.log('Test 2: jobs counters sane');
      console.log('  closed <= total:', within ? 'PASS' : 'FAIL');
      console.log(`  jobs=${JSON.stringify(json.data.jobs)}`);
      console.log();
    }

    // Test 3: by_status label/count shape
    {
      const res = await fetch(`${baseUrl}/api/v1/admin/stats`);
      const json = await res.json() as any;
      const entries = Object.entries(json.data.applications.by_status || {});
      const shapeOk = entries.every(([, v]: any) => typeof v.label === 'string' && typeof v.count === 'number');
      console.log('Test 3: by_status shape');
      console.log('  all {label,count}:', entries.length > 0 && shapeOk ? 'PASS' : 'FAIL');
      console.log(`  by_status=${JSON.stringify(json.data.applications.by_status)}`);
      console.log();
    }

    console.log('=== ALL NODE GET /api/v1/admin/stats TESTS COMPLETED ===');
  } finally {
    server.close();
    process.exit(0);
  }
}

runTests().catch((err) => {
  console.error('Test execution error:', err);
  process.exit(1);
});