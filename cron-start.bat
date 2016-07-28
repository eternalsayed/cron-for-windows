call config.bat
set script="%php% -f %cron_file%"
schtasks /create /tn "cron-for-windows" /tr %script% /sc minute /mo 1
