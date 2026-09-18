import { departmentsRepository, type DepartmentDto } from '../repositories/departments.repository.js';

export interface DepartmentsListResult {
  items: DepartmentDto[];
  total: number;
}

/**
 * Full department listing (parity with PHP `DepartmentsController::index()`).
 * PHP uses Response::list($items, ..., 1, count($items), count($items)), so the
 * limit/meta page size equals the item count — there is no real pagination.
 *
 * TODO: HR/manager guard (Auth::requireAdmin()) stays deferred until Node
 * authentication is migrated.
 */
export async function listDepartments(): Promise<DepartmentsListResult> {
  const items = await departmentsRepository.listAll();
  return { items, total: items.length };
}

export type { DepartmentDto } from '../repositories/departments.repository.js';