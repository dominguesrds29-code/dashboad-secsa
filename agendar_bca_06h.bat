@echo off
chcp 65001 > nul
echo ====================================================================
echo   CONFIGURADOR DA TAREFA AGENDADA - SINCRONIZACAO BCA 06:00
echo ====================================================================
echo.
echo Criando tarefa no Agendador de Tarefas do Windows...
echo Nome da Tarefa: DTCEA_Dashboard_SyncBCA_06h
echo Horario: Diariamente as 06:00
echo Script: C:\xampp\htdocs\dashboad-secsa\sync_bca.php
echo.

schtasks /create /tn "DTCEA_Dashboard_SyncBCA_06h" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\dashboad-secsa\sync_bca.php" /sc daily /st 06:00 /f

if %ERRORLEVEL% EQU 0 (
    echo.
    echo [SUCESSO] Tarefa agendada criada com exito!
    echo O download e analise do BCA serao executados todos os dias as 06:00.
    echo O log sera gravado em: C:\xampp\htdocs\dashboad-secsa\bca\bca_sync.log
) else (
    echo.
    echo [AVISO] Se der erro de acesso, execute este arquivo .bat como Administrador!
)
echo.
pause
