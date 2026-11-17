import { Suspense } from "react";

import { InternsView } from "@/components/interns/InternsView";

export default function InternsPage() {
  return (
    <Suspense
      fallback={
        <div className="text-center text-muted py-5">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
          <div>Loading interns…</div>
        </div>
      }
    >
      <InternsView />
    </Suspense>
  );
}
