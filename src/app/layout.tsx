import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "AnyOrigin — Self-Hosted Multi-Cloud Failover & Load Balancer",
  description: "Zero-dependency origin failover and intelligent traffic manager for modern web applications.",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="dark">
      <body className="bg-[#090d16] text-slate-100 antialiased min-h-screen">
        {children}
      </body>
    </html>
  );
}
