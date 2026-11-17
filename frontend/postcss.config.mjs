// The SPA ships plain CSS copied from the Blade app — there is nothing to
// transform here.
//
// This file must exist: PostCSS searches upward from the project directory for
// a config, so without it a Vercel build of `frontend/` picks up the Laravel
// `postcss.config.js` / `tailwind.config.js` at the repository root and fails
// on `Cannot find module 'tailwindcss'` (those packages are only installed for
// the Blade/Vite build).
export default { plugins: {} };
