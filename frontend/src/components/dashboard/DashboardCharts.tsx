"use client";

import type { Chart } from "chart.js";
import { useEffect, useRef } from "react";

import type { InternStatusRow, InternsTrendRow, PartnerAgencyRow } from "@/lib/types";

interface DashboardChartsProps {
  internsTrend: InternsTrendRow[];
  internStatusDistribution: InternStatusRow[];
  partnerAgencyDistribution: PartnerAgencyRow[];
}

export function DashboardCharts({
  internsTrend,
  internStatusDistribution,
  partnerAgencyDistribution,
}: DashboardChartsProps) {
  const trendRef = useRef<HTMLCanvasElement | null>(null);
  const statusRef = useRef<HTMLCanvasElement | null>(null);
  const agencyRef = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    let charts: Chart[] = [];
    let cancelled = false;

    (async () => {
      const { Chart } = await import("chart.js/auto");
      if (cancelled) return;

      const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
      const counts = new Array(12).fill(0);
      internsTrend.forEach((row) => {
        if (row.month >= 1 && row.month <= 12) counts[row.month - 1] = row.count;
      });

      if (trendRef.current) {
        charts.push(
          new Chart(trendRef.current.getContext("2d") as CanvasRenderingContext2D, {
            type: "line",
            data: {
              labels: months,
              datasets: [
                {
                  label: "Interns Onboarded",
                  data: counts,
                  borderColor: "#1E429B",
                  backgroundColor: "rgba(30, 66, 155, 0.1)",
                  borderWidth: 3,
                  tension: 0.4,
                  fill: true,
                  pointBackgroundColor: "#1E429B",
                  pointBorderColor: "#fff",
                  pointBorderWidth: 2,
                  pointRadius: 4,
                  pointHoverRadius: 6,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: "#1f2937", padding: 12, cornerRadius: 8, displayColors: false },
              },
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: { precision: 0, color: "#9ca3af", font: { size: 11 } },
                  grid: { color: "#e5e7eb" },
                },
                x: { ticks: { color: "#9ca3af", font: { size: 11 } }, grid: { display: false } },
              },
            },
          }),
        );
      }

      if (statusRef.current) {
        const statusColors: Record<string, string> = {
          active: "#10b981",
          inactive: "#9ca3af",
          completed: "#2563eb",
        };
        charts.push(
          new Chart(statusRef.current.getContext("2d") as CanvasRenderingContext2D, {
            type: "doughnut",
            data: {
              labels: internStatusDistribution.map((row) => row.status.charAt(0).toUpperCase() + row.status.slice(1)),
              datasets: [
                {
                  data: internStatusDistribution.map((row) => row.count),
                  backgroundColor: internStatusDistribution.map((row) => statusColors[row.status] ?? "#6b7280"),
                  borderWidth: 2,
                  borderColor: "#fff",
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              cutout: "62%",
              plugins: {
                legend: { position: "bottom", labels: { boxWidth: 10, font: { size: 11 } } },
                tooltip: { backgroundColor: "#1f2937", padding: 12, cornerRadius: 8 },
              },
            },
          }),
        );
      }

      if (agencyRef.current) {
        const agencyColors = [
          "#1E429B",
          "#2563eb",
          "#10b981",
          "#f59e0b",
          "#8b5cf6",
          "#ec4899",
          "#06b6d4",
          "#4b5563",
          "#10b981",
          "#f97316",
        ];
        charts.push(
          new Chart(agencyRef.current.getContext("2d") as CanvasRenderingContext2D, {
            type: "pie",
            data: {
              labels: partnerAgencyDistribution.map((row) => row.name),
              datasets: [
                {
                  data: partnerAgencyDistribution.map((row) => row.count),
                  backgroundColor: partnerAgencyDistribution.map(
                    (_row, index) => agencyColors[index % agencyColors.length],
                  ),
                  borderWidth: 2,
                  borderColor: "#fff",
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { position: "bottom", labels: { boxWidth: 10, font: { size: 11 } } },
                tooltip: { backgroundColor: "#1f2937", padding: 12, cornerRadius: 8 },
              },
            },
          }),
        );
      }
    })();

    return () => {
      cancelled = true;
      charts.forEach((chart) => chart.destroy());
      charts = [];
    };
  }, [internsTrend, internStatusDistribution, partnerAgencyDistribution]);

  return (
    <>
      <div className="col-lg-8">
        <div className="graph-container">
          <h3 className="dashboard-section-title mb-3">
            <i className="fas fa-chart-line" style={{ color: "var(--company-primary)" }} /> Interns Onboarded
          </h3>
          <div className="mt-2" style={{ height: 300 }}>
            <canvas ref={trendRef} />
          </div>
        </div>
      </div>

      <div className="col-lg-4">
        <div className="graph-container">
          <h3 className="dashboard-section-title mb-3">
            <i className="fas fa-chart-pie" style={{ color: "var(--company-primary)" }} /> Status Distribution
          </h3>
          <div className="mt-2" style={{ height: 300 }}>
            <canvas ref={statusRef} />
          </div>
        </div>
      </div>

      <div className="col-lg-5">
        <div className="graph-container">
          <h3 className="dashboard-section-title mb-3">
            <i className="fas fa-handshake" style={{ color: "var(--company-primary)" }} /> Partner Agency Distribution
          </h3>
          <div className="mt-2" style={{ height: 300 }}>
            <canvas ref={agencyRef} />
          </div>
        </div>
      </div>
    </>
  );
}
