$workDir = "C:\Users\hbeignon\Desktop\gocode"

$phpRunning = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
if (-not $phpRunning) {
    Start-Process -FilePath "php" -ArgumentList "artisan", "serve" -WorkingDirectory $workDir -WindowStyle Minimized
}

$viteRunning = Get-NetTCPConnection -LocalPort 5173 -State Listen -ErrorAction SilentlyContinue
if (-not $viteRunning) {
    Start-Process -FilePath "cmd" -ArgumentList "/c", "npm run dev" -WorkingDirectory $workDir -WindowStyle Minimized
}
