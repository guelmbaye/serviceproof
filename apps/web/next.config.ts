import type { NextConfig } from "next";

const config: NextConfig = {
  reactStrictMode: true,
  // Required by infra/docker/web.prod.Dockerfile: it emits a self-contained
  // server bundle with only the packages actually imported, instead of
  // shipping the whole of node_modules into the runtime image.
  output: "standalone",
  // The console renders live operational state; nothing here is cacheable.
  poweredByHeader: false,
  async headers() {
    return [
      {
        source: "/(.*)",
        headers: [
          { key: "X-Frame-Options", value: "DENY" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "same-origin" },
        ],
      },
    ];
  },
};

export default config;
