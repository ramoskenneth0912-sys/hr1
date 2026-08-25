/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        "./*.php",
        "./auth/**/*.php",
        "./includes/**/*.php",
        "./modules/**/*.php",
        "./public/**/*.php",
        "./api/**/*.php",
        "./assets/**/*.js",
    ],
    // Preflight is DISABLED so Tailwind's global reset cannot alter the
    // existing HR1 design (body, headings, buttons, forms, tables, etc).
    // The `container` plugin is disabled because HR1 already uses its own
    // `.container` layout class (style.css) — Tailwind must not emit one.
    corePlugins: {
        preflight: false,
        container: false,
    },
    plugins: [],
};
