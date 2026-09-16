import js from '@eslint/js';

export default [
  js.configs.recommended,
  {
    files: ['public/assets/app.js', 'public/assets/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        window: 'readonly',
        document: 'readonly',
        location: 'readonly',
        fetch: 'readonly',
        localStorage: 'readonly',
        EventSource: 'readonly',
        Event: 'readonly',
        URLSearchParams: 'readonly',
        setInterval: 'readonly',
        setTimeout: 'readonly',
        console: 'readonly',
        alert: 'readonly',
      },
    },
    rules: {
      // null-only loose equality (`== null`) is a deliberate, common idiom
      // for "null or undefined" — keep it strict everywhere else.
      eqeqeq: ['error', 'always', { null: 'ignore' }],
      'no-var': 'error',
      // 'all': only flag a destructuring declaration if every member of it
      // could be const — several spots here destructure { status, data }
      // together and only reassign one of them.
      'prefer-const': ['error', { destructuring: 'all' }],
      camelcase: ['error', { properties: 'never' }],
    },
  },
];
