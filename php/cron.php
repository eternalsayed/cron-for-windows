<?php
class Cron
{
    static $list, $mappings;
    public function init()
    {
        self::getMappingList();
        @mkdir('logs',0777,true);
        @mkdir('data', 0777, true);
        file_put_contents('data/status.ini', "ts = ".time());
        file_put_contents('data/schedule.ini','');
    }

    public function isRunning()
    {
        if(!file_exists('data/status.ini'))
            return false;
        $temp = parse_ini_file('data/status.ini');
        $ts = strtotime($temp['ts']);
        $current = strtotime(date('Y-m-d h:i:s'));
        if($current-$ts>2*60)//last update over few minutes ago
        {
            @unlink('data/status.ini');
            return false;
        }
        return true;
    }

    public function isAlteringBit($bit)
    {
        if(self::isRecursiveBit($bit))
            $bit = str_replace('*/','', $bit);
        return preg_match("~\d+~", $bit)[0];
    }

    public function isRangeBit($bit, $range_type)
    {
        if(self::isRecursiveBit($bit))
            $bit = str_replace('*/','', $bit);
        $temp = explode('-', $bit);
        if(count($temp)==2)
        {
            $valid_ranges = ['weekdays'=>'1-7', 'months'=>'1-12','dates'=>'1-31','hours'=>'0-23','mins'=>'0-59'];
            $temp[0] = (int)$temp[0];
            $temp[1] = (int)$temp[1];
            $pattern = $valid_ranges[$range_type];
            return preg_match("~$pattern~", $bit) ?$range :false;
        }
        return false;
    }

    public function isRecursiveBit($bit)
    {
        return $bit[0]=='*';
    }

    public function getScheduledCrons($range='1 minute')
    {
        $current = strtotime(date('Y-m-d h:i:s'));
        $range = '+'.str_replace('+','', $range);
        $next = strtotime($range);
        $crons = [];
        if(!count(self::$list))
            return $crons;
        $scheduled = parse_ini_file('data/schedule.ini');
        $mappings = self::getMappingList();
        foreach($scheduled as $cron_id=>$ts)
        {
            $ts = strtotime($ts);
            if($ts>=$current && $ts<=$next)
                $crons[$cron_id] = array_search($cron_id, $mappings);
        }
        return $crons;
    }

    /*
    adds mapped array of cronId and it's next timestep (time at which next execution will occur for this cron) in schedule.ini*/
    public function setCronSchedule($cron)
    {
        $next = self::cronGetNextTime($cron);
        $cronId = self::getCronId($cron);
        if($cronId)
        {
            $schedule = !file_exists('data/schedule.ini') ?[] :parse_ini_file('data/schedule.ini');
            $schedule[$cronId] = $next;
            $str = '';
            foreach($schedule as $cronId=>$ts)
                $str .= "$cronId = '$ts'"."\r\n";
            file_put_contents('data/schedule.ini',$str);
        }
    }

    public function getCronId($cron)
    {
        $list = self::getMappingList();
        return !empty($list[$cron]) ?$list[$cron] :null;
    }

    public function getTimeFromBit($bit, $type, $next)
    {
        if($type=='weekday')
        {
            $type = 'day';
            $current = date('N');//gives weekday number, 0-6 for Mon-Sun
            $max = 7;//max number of days in a week
        }
        else if($type=='month')
        {
            $current = date('m');//month number
            $max = 12;//max number of months
        }
        else if($type=='day')
        {
            $current = date('d');//retuns 1-12 for Jan-Dec
            $max = date('t');//gives last day of current month, 29, 30 or 31
        }
        else if($type=='hour')
        {
            $current = date('h');//hours, from 0-23
            $max = 23;
        }
        else if($type=='min')
        {
            $type = 'minute';
            $current = date('m');//hours, from 0-59
            $max = 59;
        }
        $type .= 's';//make it plural
        if($range = self::isRangeBit($bit,$type))
        {
            if($current < $range[0])
                $ts = $range[0] - $current;
            else if($current > $range[1])
                $ts = $max - $current + $range[0];//eg, (7-6)+2 for a range 2-4 and weekday as 6
            else
                $ts = 0;
            $next = $ts >0 ?strtotime("+$ts $type", $next) :$next;
        }
        else if($bit = self::isAlteringBit($bit))
        {
            $ts = $max - $current + $bit;//eg, (7-6)+2 for a alternate bit of */2 and weekday as 6
            $next = $ts>0 ?strtotime("+$ts $type", $next) :$next;
        }
        return $next;
    }

    public function cronGetNextTime($cron)
    {
        $temp = explode(' ', $cron);

        $time['min']        = $temp[0];
        $time['hour']       = $temp[1];
        $time['day']        = $temp[2];
        $time['month']      = $temp[3];
        $time['weekday']    = $temp[4];
        $time['year']       = $temp[5];

        $next = strtotime(date('Y-m-d h:i:s'));
        //for any of the bits, do nothing for JUST recursive bits
        foreach($time as $type=>$bit)
        {
            if($type!='year')
            {
                $next = self::getTimeFromBit($bit, $type, $next);
            }
        }
        $date = strtotime(date('Y-m-d h:i:s'));
        if($date-$next<=60)
            $next = strtotime('+1 minute', $next);
        $next = date('Y-m-d h:i:s', $next);
        //echo '>Returning next:'.$next;
        return $next;
    }

    public function cronExecute($cron)
    {
        ob_start();
        $cron_id = self::getCronId($cron);
        $ts = date('Y-m-d h:i:s');
        $cron = self::getExecutableCron($cron);
        echo "[$ts] Executing cron: ".$cron."\r\n";
        try{
            $result = shell_exec($cron);
            $ts = date('Y-m-d h:i:s');
            echo "[$ts] Cron executed successfully!"."\r\n";
            echo $result."\r\n";
        }
        catch(Exception $e)
        {
            $ts = date('Y-m-d h:i:s');
            echo "[$ts] Cron failed:"."\r\n";
            print_r($e);
            echo "\r\n";
        }
        $log = ob_get_flush();
        self::setCronLog($cron_id, $log);
    }

    public function getExecutableCron($cron)
    {
        $cron = preg_replace("/\\+/","\\\\\\\\", $cron);//double-escape the backslashes: once for PHP and once for the shell.
        $cron = explode(' ', $cron);
        $temp = [];
        //remove schedule from cron
        for($i=5; $i<count($cron); $i++)
            $temp[] = $cron[$i];
        $cron = join(' ', $temp);
        return trim($cron);
    }

    public function setCronLog($cronId, $log)
    {
        $filename = "logs/cron-$cronId.log";
        $file = fopen($filename, 'a+');
        fwrite($file, $log);
        fclose($file);
    }

    public function createMappingList($return_new=false)
    {
        $existing = self::getMappingList();
        $temp = [];
        $additions = [];
        $mappings = '';
        foreach(self::$list as $cron)
        {
            $key = !empty($existing[$cron]) ?$existing[$cron] :uniqid('cid_');
            $temp[$cron] = $key;
            if(empty($existing[$cron]))
                $additions[$cron] = $key;
            $mappings .= "'$cron' = $key"."\r\n";
        }
        file_put_contents('data/map.ini', $mappings);
        self::$mappings = $temp;
        return $return_new ?$additions :$temp;
    }

    public function getMappingList()
    {
        if(!file_exists('data/map.ini'))
        {
            @mkdir('data',0777,true);
            file_put_contents('data/map.ini','');
        }
        if(empty(self::$mappings))
        {
            $mappings = parse_ini_file('data/map.ini');
            self::$mappings = !count($mappings) ?[] :$mappings;
        }
        return self::$mappings;
    }

    public function run()
    {
        if(!self::isRunning()) self::init();
        $crons_mtime = filemtime('php/crontab.php');
        //cron file updated; add schedule for new crons
        if(time()-$crons_mtime<=60 || !count(self::$mappings))
        {
            $added_crons = self::createMappingList($new=true);
           // echo 'added cron:'.print_r($added_crons, true);
            foreach($added_crons as $cron=>$id)
            {
                self::setCronSchedule($cron);
            }
        }
        $crons = self::getScheduledCrons();

        ob_start();
        $now = date('Y-m-d h:i:s');
        echo "[$now] Cron list started"."\r\n";
        foreach($crons as $id=>$cron)
            self::cronExecute($cron);
        $now = date('Y-m-d h:i:s');
        echo "[$now] Cron list ended"."\r\n";
        $output = ob_get_flush();
        if(!is_dir('logs')) @mkdir('logs',0777,true);
        $log = fopen('logs/cronlog.log','a+');
        fwrite($log, $output);
        fclose($log);
    }
}
include_once('crontab.php');//contains list of cron jobs
chdir(dirname(dirname(__FILE__)));
Cron::run();