import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'node:path'

const backendUrl = process.env.TANODBUKID_BACKEND_URL || 'http://127.0.0.1:8001'

const protectedPages = new Set([
  '/profile-setup.html',
  '/dashboard.html',
  '/plant-monitoring.html',
  '/TanimTrack Dashboard/index (1).html',
  '/dist/profile-setup.html',
  '/dist/dashboard.html',
  '/dist/plant-monitoring.html',
  '/dist/TanimTrack Dashboard/index (1).html',
])

function authPageGuard() {
  return async (request, response, next) => {
    let pathname
    try {
      pathname = decodeURIComponent(new URL(request.url || '/', 'http://localhost').pathname)
    } catch {
      response.statusCode = 400
      response.end('Invalid request path.')
      return
    }
    if (!protectedPages.has(pathname)) {
      next()
      return
    }

    try {
      const sessionResponse = await fetch(`${backendUrl}/api/session.php`, {
        headers: {
          ...(request.headers.cookie ? { cookie: request.headers.cookie } : {}),
          ...(request.headers['x-forwarded-proto'] ? { 'x-forwarded-proto': request.headers['x-forwarded-proto'] } : {}),
        },
      })
      const session = await sessionResponse.json()
      if (!sessionResponse.ok || !session.authenticated) {
        response.statusCode = 302
        response.setHeader('Location', '/index.html#signin')
        response.end()
        return
      }
      next()
    } catch {
      response.statusCode = 503
      response.end('Authentication service unavailable. Start the PHP server on port 8001.')
    }
  }
}

const phpApiProxy = {
  '/api': {
    target: backendUrl,
    changeOrigin: true,
    xfwd: true,
  },
  '/uploads': {
    target: backendUrl,
    changeOrigin: true,
    xfwd: true,
  },
}

export default defineConfig({
  plugins: [
    react(),
    {
      name: 'tanodbukid-auth-page-guard',
      configureServer(server) {
        server.middlewares.use(authPageGuard())
      },
      configurePreviewServer(server) {
        server.middlewares.use(authPageGuard())
      },
    },
  ],
  server: {
    host: '0.0.0.0',
    allowedHosts: ['.trycloudflare.com'],
    proxy: phpApiProxy,
  },
  preview: {
    host: '0.0.0.0',
    allowedHosts: ['.trycloudflare.com'],
    proxy: phpApiProxy,
  },
  build: {
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html'),
        dashboard: resolve(__dirname, 'dashboard.html'),
        profile: resolve(__dirname, 'profile-setup.html'),
        plantMonitoring: resolve(__dirname, 'plant-monitoring.html'),
        home: resolve(__dirname, 'TanimTrack Dashboard/index (1).html'),
      },
    },
  },
})
