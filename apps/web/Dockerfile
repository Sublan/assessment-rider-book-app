# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Build: TypeScript + Vite, then throw the toolchain away.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS build

WORKDIR /app

# Copy manifests first so `npm ci` is cached until a dependency actually changes.
COPY package.json package-lock.json ./
COPY apps/web/package.json ./apps/web/package.json
RUN npm ci --no-audit --no-fund

COPY apps/web ./apps/web
RUN npm run build:web

# ---------------------------------------------------------------------------
# Serve: static files + an /api proxy to PHP-FPM. Nothing Node stays behind.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine

COPY apps/web/docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/web/dist /usr/share/nginx/html

EXPOSE 80
