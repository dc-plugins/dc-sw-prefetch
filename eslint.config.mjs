import js from '@eslint/js';

export default [
	{
		// Vendored Partytown and node_modules are never touched.
		ignores: [ 'assets/partytown/**', 'node_modules/**', 'vendor/**' ],
	},
	{
		// Authored inline JS extracted to static files for linting and maintainability.
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				// Browser globals.
				window: 'readonly',
				document: 'readonly',
				navigator: 'readonly',
				location: 'readonly',
				console: 'readonly',
				setTimeout: 'readonly',
				clearTimeout: 'readonly',
				setInterval: 'readonly',
				clearInterval: 'readonly',
				fetch: 'readonly',
				URL: 'readonly',
				URLSearchParams: 'readonly',
				MutationObserver: 'readonly',
				IntersectionObserver: 'readonly',
				SharedArrayBuffer: 'readonly',
				HTMLIFrameElement: 'readonly',
				Image: 'readonly',
				NodeFilter: 'readonly',
				Object: 'readonly',
				JSON: 'readonly',
				Promise: 'readonly',
				// WordPress / jQuery globals.
				jQuery: 'readonly',
				$: 'readonly',
				ajaxurl: 'readonly',
				wp: 'readonly',
				// Partytown globals.
				partytown: 'readonly',
			},
		},
		rules: {
			...js.configs.recommended.rules,
			// Style.
			'no-var': 'warn',
			'prefer-const': 'warn',
			'eqeqeq': [ 'error', 'always' ],
			'semi': [ 'error', 'always' ],
			// Safety.
			'no-unused-vars': [ 'warn', { vars: 'all', args: 'after-used', ignoreRestSiblings: true } ],
			'no-undef': 'error',
			'no-eval': 'error',
			'no-implied-eval': 'error',
			// Complexity.
			'no-unreachable': 'error',
			'no-constant-condition': 'warn',
			// Allow empty catch blocks — several intentional fire-and-forget try/catch
			// patterns (e.g. SharedArrayBuffer probe, URL parsing guard).
			'no-empty': [ 'error', { allowEmptyCatch: true } ],
		},
	},
];
