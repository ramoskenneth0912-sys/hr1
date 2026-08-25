<?php
/**
 * /api/v1/departments — read-only department listing.
 *
 * GET /departments → index (hr/manager)
 */

declare(strict_types=1);

class DepartmentsController
{
    /** GET /departments — list all departments. */
    public static function index(): never
    {
        Auth::requireAdmin();

        $rows = db()->query('SELECT id, code, name FROM departments ORDER BY name')->fetchAll();

        Response::list(array_map(static fn(array $r) => [
            'id'   => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
        ], $rows), count($rows), 'Departments retrieved successfully.');
    }
}
