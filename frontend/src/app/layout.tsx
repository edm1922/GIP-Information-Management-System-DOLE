import type { Metadata } from "next";

import { AuthProvider } from "@/lib/auth";

import "./globals.css";

const brandName = process.env.NEXT_PUBLIC_BRAND_NAME ?? "GIP Information Management System (GIMS)";
const brandShort = process.env.NEXT_PUBLIC_BRAND_SHORT ?? "GIMS";
const primary = process.env.NEXT_PUBLIC_BRAND_PRIMARY ?? "#1E429B";

export const metadata: Metadata = {
  title: brandName,
  description: "GIP Information Management System",
  icons: { icon: "/image/dole-emblem.png" },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link
          href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet"
        />
        <link
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
          rel="stylesheet"
        />
        <link
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          rel="stylesheet"
        />
        <link href="/css/style.css" rel="stylesheet" />
        <link href="/css/app.css" rel="stylesheet" />
        <style
          dangerouslySetInnerHTML={{
            __html: `:root{--company-primary:${primary};--company-primary-hover:${primary}dd;--company-primary-light:${primary}1a;--company-primary-border:${primary}33;--brand-red:${primary};}`,
          }}
        />
      </head>
      <body data-brand={brandShort}>
        <AuthProvider>{children}</AuthProvider>
      </body>
    </html>
  );
}
