# ServiceProof — operations console (Next.js)
#
# Dev-oriented image: the console is bind-mounted so a judge can change a
# label and see it immediately. For production, swap the final stage for
# `next build` + `next start` on a slim runtime.

FROM node:22-alpine

WORKDIR /app

RUN apk add --no-cache libc6-compat

COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund

COPY . .

ENV NEXT_TELEMETRY_DISABLED=1
EXPOSE 3000

CMD ["npm", "run", "dev"]
