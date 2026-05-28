# ── Stage 1: build ────────────────────────────────────────────────────────────
FROM node:20-alpine AS build

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

# Inject backend URLs at build time if provided; defaults to empty (entered at login)
ARG VITE_FRAPPE_BASE_URL=""
ENV VITE_FRAPPE_BASE_URL=$VITE_FRAPPE_BASE_URL
ARG VITE_LARAVEL_BASE_URL=""
ENV VITE_LARAVEL_BASE_URL=$VITE_LARAVEL_BASE_URL

RUN npm run build

# ── Stage 2: serve ────────────────────────────────────────────────────────────
FROM nginx:alpine AS serve

# Copy built SPA
COPY --from=build /app/dist /usr/share/nginx/html

# nginx config: serve SPA with hash-router support
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
