# syntax=docker/dockerfile:1

FROM node:22-alpine AS build
WORKDIR /app
RUN apk add --no-cache python3 make g++ sqlite-dev
COPY server/package.json server/package-lock.json ./
RUN npm install --include=dev
COPY server/tsconfig.json server/tsconfig.build.json ./
COPY server/src ./src
RUN npm install -g typescript
RUN npm run build

FROM node:22-alpine AS production

WORKDIR /app

ENV NODE_ENV=production

RUN apk add --no-cache python3 make g++ sqlite-dev
COPY server/package.json server/package-lock.json ./
RUN npm install --omit=dev

COPY --from=build /app/dist ./dist

EXPOSE 8787

CMD ["node", "dist/index.js"]
