# setup_autosync.ps1 — Configure la sync automatique au demarrage de session
# Lancer une seule fois en tant qu'Administrateur :
# powershell -ExecutionPolicy Bypass -File setup_autosync.ps1

$ProjectDir = "C:\Users\Administrateur\Eval-Projet"
$SyncScript = "$ProjectDir\sync_remote.sh"
$BashExe    = "C:\Program Files\Git\bin\bash.exe"
$TaskName   = "EvalProjet-SyncSession"

if (-not (Test-Path $BashExe)) {
    $bashCmd = Get-Command bash -ErrorAction SilentlyContinue
    $BashExe = if ($bashCmd) { $bashCmd.Source } else { $null }
    if (-not $BashExe) {
        Write-Error "bash.exe introuvable. Installez Git for Windows."
        exit 1
    }
}

Write-Host "Configuration de la tache planifiee '$TaskName'..." -ForegroundColor Cyan

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue

$Trigger = New-ScheduledTaskTrigger -AtLogOn

$Action = New-ScheduledTaskAction `
    -Execute $BashExe `
    -Argument "`"$SyncScript`"" `
    -WorkingDirectory $ProjectDir

$Settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable

$Principal = New-ScheduledTaskPrincipal `
    -UserId $env:USERNAME `
    -LogonType Interactive `
    -RunLevel Highest

Register-ScheduledTask `
    -TaskName  $TaskName `
    -Trigger   $Trigger `
    -Action    $Action `
    -Settings  $Settings `
    -Principal $Principal `
    -Force | Out-Null

Write-Host ""
Write-Host "OK : Tache '$TaskName' creee." -ForegroundColor Green
Write-Host "  sync_remote.sh s'executera automatiquement a chaque ouverture de session Windows."
Write-Host "  Journal : $ProjectDir\sync_remote.log"
Write-Host ""
Write-Host "Pour lancer manuellement :" -ForegroundColor Yellow
Write-Host "  bash $SyncScript"
