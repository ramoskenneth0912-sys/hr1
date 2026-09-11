// Module-level store for the logged-in employee context passed by the PHP page.
// Kept intentionally tiny and non-reactive: the user does not change while a
// page is mounted. main.jsx parses `data-hr1-user` on the mount and calls
// setCurrentUser() before rendering the widget.

let currentUser = null;

export function setCurrentUser(user) {
    currentUser = user || null;
}

export function getCurrentUser() {
    return currentUser;
}

export default { setCurrentUser, getCurrentUser };