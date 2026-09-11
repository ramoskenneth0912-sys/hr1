import { getCurrentUser } from './userContext.js';

// HR1 "useAuth" hook replacement — returns the logged-in employee scoped to
// this session. Auth itself is enforced server-side by the PHP page
// (requireLogin + role guards); this hook only surfaces who the page knows
// the employee to be (name + employee_id passed through data-hr1-user).
export function useAuth() {
    return { user: getCurrentUser() };
}

export default useAuth;