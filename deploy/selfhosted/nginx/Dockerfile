# GIMS nginx image (Ubuntu VM, Docker).
#
# Build context is the repository root (see docker-compose.yml):
#   docker build -f deploy/selfhosted/nginx/Dockerfile -t gims-nginx .
#
# nginx only serves static assets from public/ and forwards PHP to the app
# container. Uploaded files are private and are never served from this image;
# they are streamed by Laravel through authorized API endpoints.

FROM nginx:stable-alpine

# Fail fast if the base image's default site is ever re-introduced.
RUN rm -f /etc/nginx/conf.d/default.conf

COPY deploy/selfhosted/nginx/default.conf /etc/nginx/conf.d/gims.conf

# Static assets only. index.php lives here too but is executed by the app
# container; nginx uses the same absolute path for SCRIPT_FILENAME.
COPY public/ /var/www/html/public/

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=6 \
    CMD wget -q -O /dev/null http://127.0.0.1/healthz || exit 1

STOPSIGNAL SIGQUIT
