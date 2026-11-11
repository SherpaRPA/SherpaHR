FROM node:22.23.3-bookworm-slim AS frontend
WORKDIR /build
COPY frontend/package*.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM php:8.4-cli-bookworm
RUN php -r 'exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);'
WORKDIR /app
COPY --chown=www-data:www-data backend/ backend/
COPY --from=frontend /build/dist/project-desk/browser/ public/
RUN mkdir var && chown -R www-data:www-data var
USER www-data
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "backend/router.php"]
