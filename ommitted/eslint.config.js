import js from '@eslint/js'

export default [
  js.configs.recommended,
  {
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module'
    },
    rules: {
      'no-unused-vars': ['warn'],
      'no-console': 'off',
      'semi': ['error', 'never'],
      'quotes': ['error', 'single']
    },
    ignores: [
      'node_modules/',
      'assets/build/'
    ]
  }
]
