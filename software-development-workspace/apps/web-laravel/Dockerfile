# Stage 1: build
FROM node:20-alpine AS builder

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

COPY . .

# Build with the API URL pointing to the backend container
ARG VITE_LARAVEL_API_URL=
RUN VITE_LARAVEL_API_URL=$VITE_LARAVEL_API_URL npm run build

# Stage 2: serve with nginx
FROM nginx:alpine

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=builder /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
