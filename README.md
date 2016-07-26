# Cron for Windows (7/8/10)

This little script allows you to run cronjobs on a Windows PC. It uses Windows' Task Scheduler to create a Scheduled task named 'Cron'. It is created to run on these windows and may support some older versions too but I'm not sure. Use it on your own risk.. although there isn't any! Except system hanging. Or file locking. Or I don't know, matrix reset! Hold on, just kidding! ;) But yeah, use it on your own risk. I've although tested on my system and works perfectly. I created it so that I don't have to use *nix.

# Prerequisites
* Xampp/ Wampp server, or even a minimal server setup with Apache and php will do too
* To check your setup whether you've configured the files correctly, you can call the file `php/cron.php` from your localhost. For example, if you've installed the plugin under a folder `cfw` under your htdocs/localhost folder, then calling `http://localhost/cfw/php/cron.php` should create two folders within `cfw`, namely, `data` and `logs` and a file `check.ini`. These folders contain neccesary files required for proper execution of crons.

# Getting started
Download and unzip the code in a folder within your `htdocs` folder (or wherever your `localhost` points to). Then, open `cfw/config.bat` file using a text-editor and change the value of `php` key with the path where your `php.exe` is present. In my case, it was in `f:/xampp/php/php.exe` and hence, the `php` path is set to that by default. Once you've updated that, save the file and close the editor.

# Adding a cronjob
* You can add your cronjobs in file `php/crontab.php`. It contains an example cron by default which you may use as help. You can add more crons similarly.
* Currently, you can specify the execution time only in either of these formats: `*` (repeative), `*/[relative number without these brackets]` and `[numer1-number2]`.
* For each of the called `php` script, please specify absolute paths. Relative paths may not work properly.
* Adding a new cron while the crons are being executed won't break the operation of exiting crons. Your new crons will just be added and executed from next cycle, in next minute.

# Starting crons
* After you've added your crons, just double click on `cron-start.bat` file. It'll open console for a second and then close it. You can rest now as your crons are now scheduled to run.
* The cron manager runs every one minute to check for any cron that may have been scheduled. This means, you'll see the php console opening automatically at each minute. Please don't interfere with it's operation.

# Reseting crons
If you want to reset all crons, currently executing or not, just delete the `data` and `logs` folders. It'll auto-create new folders with fresh entries.

# Stopping crons
Just double click the `stop-cron.bat` file. It'll open up a console window and ask you to confirm if you want close the operation. Press `y` and enter. Tada! Your cronjobs execution is stopped!
