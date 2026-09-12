// Design tokens aligned with HR1's CSS variables so the ESS modules inherit
// the existing Employee Portal look and feel (style.css :root).
export const V = '#7B2CBF';      // --purple (brand)
export const TX = '#1B2559';     // --text-dark (headings / primary text)
export const TX2 = '#A3AED0';    // --muted (secondary text)
export const BD = '#E9EDF7';     // --border
export const SUCCESS = '#05CD99';// --success
export const WARNING = '#FFB547';// --warning
export const INFO = '#00B5D8';   // --teal / info
export const DANGER = '#EE5D50'; // --danger

// Existing HR1 base URL convention. The React widget code is served from
// PHP pages under BASE_URL, so links are built relative to the app root.
export const BASE_URL = '/HR1';

export default { V, TX, TX2, BD, SUCCESS, WARNING, INFO, DANGER, BASE_URL };