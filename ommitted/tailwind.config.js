/** @type {import('tailwindcss').Config} */
const colors = require('tailwindcss/colors')

module.exports = {
  darkMode: 'class',
  content: [
    './system/**/*.php',
    './user/**/*.php',
    './assets/**/*.{html,js,ts}'
  ],

  theme: {
    extend: {
      colors: {
        ...colors
      },
      fontFamily: {
        sans: [
          'Inter var',
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          '"Helvetica Neue"',
          'Arial',
          '"Noto Sans"',
          'sans-serif'
        ]
      },
      boxShadow: {
        s: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
        m: '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
        l: '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)'
      }
    }
  },

  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography')
  ]
}
