# Stops a running k6 load test.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\stop.ps1
#
# Ctrl+C in the k6 terminal also stops a run gracefully; this is the
# out-of-band option when the terminal is not reachable.

$processes = Get-Process k6 -ErrorAction SilentlyContinue

if (-not $processes) {
    Write-Host "No k6 process is running."
    exit 0
}

foreach ($process in $processes) {
    Write-Host "Stopping k6 (PID $($process.Id)) ..."
    Stop-Process -Id $process.Id -Force
}

Write-Host "Stopped. Any partially written report stays in load-testing/results/."
