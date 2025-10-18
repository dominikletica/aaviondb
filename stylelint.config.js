export default {
  extends: ['stylelint-config-standard', 'stylelint-config-prettier'],
  rules: {
    'color-hex-length': 'short',
    'selector-class-pattern': null,
    'no-descending-specificity': null
  },
  ignoreFiles: [
    'node_modules/**/*',
    'system/assets/build/**/*'
  ]
}
