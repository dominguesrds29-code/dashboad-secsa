@echo off
chcp 65001 > nul
echo ====================================================================
echo   CONFIGURADOR DA TAREFA AGENDADA - SINCRONIZACAO BCA 06:10
echo ====================================================================
echo.
echo Criando tarefa no Agendador de Tarefas do Windows...
echo Nome da Tarefa: DTCEA_Dashboard_SyncBCA_0610
echo Horario: Diariamente as 06:10 (Execucao unica e controlada)
echo Script: C:\xampp\htdocs\dashboad-secsa\sync_bca.php
echo.

REM Remove tarefas antigas de 06:00 caso existam
schtasks /delete /tn "DTCEA_Dashboard_SyncBCA_06h" /f >nul 2>&1

REM Cria a tarefa agendada oficial para as 06:10
schtasks /create /tn "DTCEA_Dashboard_SyncBCA_0610" /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\dashboad-secsa\sync_bca.php\"" /sc daily /st 06:10 /f

if %ERRORLEVEL% EQU 0 (
    echo.
    echo [SUCESSO] Tarefa agendada criada com exito!
    echo O download, analise do BCA e manutencao dos 10 PDFs serao executados todos os dias as 06:10.
    echo O log sera gravado em: C:\xampp\htdocs\dashboad-secsa\bca\bca_sync.log
) else (
    echo.
    echo [AVISO] Se der erro de permissao, clique com botao direito e execute como Administrador!
)
echo.
pause
