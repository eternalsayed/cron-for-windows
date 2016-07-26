call config.bat
set script="%php% -f %cron_file%"
schtasks /create /tn "Crons" /tr %script% /sc minute /mo 1
