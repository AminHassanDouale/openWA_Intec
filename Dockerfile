# OpenWA - Dockerfile
# Multi-stage build for production-ready image

# ===== Stage 1: Builder =====
FROM node:22-slim AS builder

WORKDIR /app

# Install build dependencies
RUN apt-get update && apt-get install -y \
    python3 \
    make \
    g++ \
    && rm -rf /var/lib/apt/lists/*

# Copy package files
COPY package*.json ./

# Install all dependencies (including devDependencies for build)
RUN npm ci

# Copy source code
COPY . .

# Build the API
RUN npm run build

# Build the dashboard (served by the API at the root path in production)
RUN cd dashboard && npm ci && npm run build

# ===== Stage 2: Production =====
FROM node:22-slim AS production

# Install the full runtime dependency set for headless Chrome (Chrome for
# Testing, installed later, needs more libs than Debian's chromium package).
RUN apt-get update && apt-get install -y \
    ca-certificates \
    fonts-liberation \
    dumb-init \
    xdg-utils \
    wget \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libatspi2.0-0 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libexpat1 \
    libgbm1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxkbcommon0 \
    libxrandr2 \
    libxrender1 \
    libxshmfence1 \
    libxss1 \
    libxtst6 \
    && rm -rf /var/lib/apt/lists/*

# Use the version-pinned Chrome that whatsapp-web.js/puppeteer is built against
# (installed below), NOT Debian's rolling `chromium` package — the rolling
# version drifts and breaks the library's injected sendMessage/getChat code.
ENV PUPPETEER_CACHE_DIR=/opt/puppeteer

# Create app user for security
RUN groupadd -r openwa && useradd -r -g openwa openwa

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install production dependencies only
RUN npm ci --omit=dev && npm cache clean --force

# Download the exact Chrome build puppeteer (and thus whatsapp-web.js) pins, so
# the WhatsApp Web engine runs on a browser version it is tested against. Symlink
# the resolved binary to a stable path and point Puppeteer at it explicitly.
RUN npx puppeteer browsers install chrome \
    && ln -sf "$(node -e "console.log(require('puppeteer').executablePath())")" /usr/local/bin/chrome-pinned
ENV PUPPETEER_EXECUTABLE_PATH=/usr/local/bin/chrome-pinned

# Copy built application from builder stage
COPY --from=builder /app/dist ./dist

# Copy the built dashboard so the API can serve it at the root path
COPY --from=builder /app/dashboard/dist ./dashboard/dist

# Create data directories with proper permissions
RUN mkdir -p ./data/sessions ./data/media && \
    chown -R openwa:openwa /app

# Note: Running as root to allow Docker socket access for orchestration
# For production with stricter security, consider using a Docker socket proxy
# USER openwa

# Expose port
EXPOSE 2785

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD node -e "require('http').get('http://localhost:2785/api/health', (r) => process.exit(r.statusCode === 200 ? 0 : 1))"

# Start with dumb-init to handle signals properly
ENTRYPOINT ["dumb-init", "--"]
CMD ["node", "dist/main"]
