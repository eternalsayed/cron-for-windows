<?php
chdir(dirname(dirname(__FILE__)));
$fp = fopen('check.ini','a+');
fwrite($fp, 'ts = '.date('Y-m-d h:i:s')."\r\n");
fclose($fp);
