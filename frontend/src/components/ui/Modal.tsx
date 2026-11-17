"use client";

import { useEffect } from "react";

interface ModalProps {
  show: boolean;
  onClose: () => void;
  title: React.ReactNode;
  children: React.ReactNode;
  footer?: React.ReactNode;
  size?: "sm" | "md" | "lg" | "xl";
}

/**
 * Bootstrap-styled dialog without Bootstrap's JS bundle: the Blade app uses
 * `bootstrap.Modal`, but the SPA has no jQuery/Bootstrap script, so the overlay,
 * Escape handling and scroll lock are implemented here.
 */
export function Modal({ show, onClose, title, children, footer, size = "md" }: ModalProps) {
  useEffect(() => {
    if (!show) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKeyDown);

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [show, onClose]);

  if (!show) return null;

  return (
    <div
      className="modal fade show d-block"
      role="dialog"
      aria-modal="true"
      tabIndex={-1}
      style={{ zIndex: 1055, background: "rgba(15, 23, 42, 0.5)" }}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div className={`modal-dialog modal-dialog-centered modal-dialog-scrollable modal-${size}`}>
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title">{title}</h5>
            <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
          </div>
          <div className="modal-body">{children}</div>
          {footer ? <div className="modal-footer">{footer}</div> : null}
        </div>
      </div>
    </div>
  );
}
