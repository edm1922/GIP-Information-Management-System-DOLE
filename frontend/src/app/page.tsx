import { redirect } from "next/navigation";

/**
 * The SPA has no landing page of its own — `/` is served by the Blade app in
 * production. Send visitors straight into the authenticated shell; AppShell
 * then forwards guests to /login and interns with a pending password rotation
 * to /force-password-change.
 */
export default function Home() {
  redirect("/dashboard");
}
