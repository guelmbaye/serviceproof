import type { Metadata, Viewport } from "next";

import "./globals.css";

export const metadata: Metadata = {
  // Next resolves the favicon, apple touch icon and social card from
  // icon.png, apple-icon.png and opengraph-image.png sitting next to this
  // file — no <link> tags to keep in sync by hand.
  title: {
    default: "ServiceProof AI — Operations",
    template: "%s · ServiceProof",
  },
  description:
    "Prove the service. Trust the evidence. Network-powered assurance for field-service claims.",
  applicationName: "ServiceProof AI",
  openGraph: {
    title: "ServiceProof AI",
    description:
      "An AI agent decides what network evidence to gather. Deterministic policy decides what it means.",
    siteName: "ServiceProof AI",
    type: "website",
  },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#0060fc",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=Public+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap"
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
