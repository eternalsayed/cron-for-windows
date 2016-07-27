<?php
class Cron
{
    static $list, $mappings;
    public function init()
    {
        self::getMappingList();
        @mkdir('logs',0777,true);
        @mkdir('data', 0777, true);
        file_put_contents('data/status.ini', "ts = ".date('Y-m-d h:i:s'));
        file_put_contents('data/cron-schedule.ini','');
    }

    public function isRunning()
    {
        if(!file_exists('data/status.ini'))
            return false;
        $temp = parse_ini_file('data/status.ini');
        $last = strtotime($temp['ts']);
        $current = strtotime(date('Y-m-d h:i:s'));
        if(strtotime('+1 minute', $last) < $current)//last update over few minutes ago
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
        $current = date('Y-m-d h:i');
        $range = '+'.str_replace('+','', $range);
        $next = date('Y-m-d h:i',strtotime($range));
        echo "\r\n".'Getting scheduled crons falling before '.$next.' and '.$current;
        $crons = [];
        if(!count(self::$list))
            return $crons;
        $scheduled = parse_ini_file('data/cron-schedule.ini');
        $scheduled_crons = $scheduled=='' || !count($scheduled) ?[] :$scheduled;
        $mappings = self::getMappingList();
        foreach($scheduled_crons as $cron_id=>$date)
        {
            echo "\r\n".'Cron:'.$cron_id.', Time:'.$date; 
            if($date>=$current && $date<=$next)
                $crons[$cron_id] = array_search($cron_id, $mappings);
        }
        return $crons;
    }

    /*
    adds mapped array of cronId and it's next timestep (time at which next execution will occur for this cron) in cron-schedule.ini*/
    public function setCronSchedule($cron, $completed=false)
    {
        $next = self::cronGetNextTime($cron, $completed);
        $cronId = self::getCronId($cron);
        if($cronId)
        {
            $schedule = !file_exists('data/cron-schedule.ini') ?[] :parse_ini_file('data/cron-schedule.ini');
            $schedule[$cronId] = $next;
            $str = '';
            foreach($schedule as $cronId=>$ts)
                $str .= "$cronId = '$ts'"."\r\n";
            file_put_contents('data/cron-schedule.ini',$str);
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

    public function cronGetNextTime($cron, $completed=false)
    {
        echo "\r\n".'Getting nextSchedule for cron:'.$cron;
        $temp = explode(' ', $cron);

        $time['min']        = $temp[0];
        $time['hour']       = $temp[1];
        $time['day']        = $temp[2];
        $time['month']      = $temp[3];
        $time['weekday']    = $temp[4];
        $time['year']       = $temp[5];

        $next = strtotime(date('Y-m-d h:i:'));
        if($completed) $next = strtotime('+1 minute', $next);
        //for any of the bits, do nothing for JUST recursive bits
        foreach($time as $type=>$bit)
        {
            if($type!='year')
            {
                $next = self::getTimeFromBit($bit, $type, $next);
            }
        }
        if(date('Y-m-d h:i', $next) <= date('Y-m-d h:i'))//if $next minute is smaller than current time
            $next = strtotime('+1 minute', $next);
        $next = date('Y-m-d h:i:', $next);
        echo '>Returning next:'.$next;
        return $next;
    }

    public function cronSetLog($cronId, $log)
    {
        $filename = "logs/cron-$cronId.log";
        $file = fopen($filename, 'a+');
        fwrite($file, $log);
        fclose($file);
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
        self::cronSetLog($cron_id, $log);
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
        ob_start();
        if(!self::isRunning()) self::init();
        
        $now = strtotime(date('Y-m-d h:i:s'));
        file_put_contents('data/status.ini', "ts = ".$now);
        
        $mtime = filemtime('php/crontab.php');
        // echo "\r\n".'Current time:'.$now.', modified time:'.$crons_mtime.': current >'.date('Y-m-d h:i:s', $now).', mtime:'.date('Y-m-d h:i:s', $crons_mtime);
        //cron file updated; add schedule for new crons
        if(date('Y-m-d h:i:s',strtotime("+1 minute", $mtime)) >= date('Y-m-d h:i:s',$now) || !count(self::$mappings))
        {
            $added_crons = self::createMappingList($new=true);
            echo "\r\n".'added cron:'.print_r($added_crons, true);
            foreach($added_crons as $cron=>$id)
            {
                self::setCronSchedule($cron);
            }
        }
        $crons = self::getScheduledCrons();
        echo "\r\n".'Scheduled crons:'.print_r($crons,true);

        $now = date('Y-m-d h:i:s');
        echo "\r\n"."[$now] Cron list started"."\r\n";
        foreach($crons as $id=>$cron)
        {
            self::cronExecute($cron);
            self::setCronSchedule($cron, true);
        }
        $now = date('Y-m-d h:i:s');
        echo "\r\n"."[$now] Cron list ended"."\r\n";
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