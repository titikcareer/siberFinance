# ============================================================
# Tahap 1: build aplikasi Lit + Vite -> dist/ (static assets)
# ============================================================
FROM node:20-alpine AS build
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ============================================================
# Tahap 2: nginx menyajikan dist/ dan meneruskan /api/ ke PHP-FPM
# ============================================================
FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /var/www/html
EXPOSE 80