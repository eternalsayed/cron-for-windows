<?php
class Cron
{
    static $list, $mappings;
    public function init()
    {
        echo "\r\n".'Cron was not setup. Initializing';
        @mkdir('logs',0777,true);
        @mkdir('data', 0777, true);
        file_put_contents('data/status.ini', '');
        file_put_contents('data/cron-schedule.ini','');
    }

    public function isRunning()
    {
        if(!file_exists('data/status.ini'))
            return false;
        $temp = parse_ini_file('data/status.ini');
        $last = $temp['ts'];
        $current = strtotime(date('Y-m-d h:i:s'));
        if(strtotime('+2 minute', $last) < $current)//last update over few minutes ago
        {
            @unlink('data/status.ini');
            return false;
        }
        return true;
    }

    public function isRepeativeBit($bit)
    {
        $bit = str_replace('*/','', $bit);
        return preg_match('/[^0-9]+/',$bit) ?false :(int)$bit;
    }

    public function isRangeBit($bit, $range_type)
    {
        if(self::isRecursiveBit($bit))
            $bit = str_replace('*/','', $bit);
        $temp = explode('-', $bit);
        if(count($temp)==2)
        {
            $valid_ranges = ['weekdays'=>'0-6', 'months'=>'1-12','dates'=>'1-31','hours'=>'0-23','mins'=>'0-59'];
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

    public function getScheduledCrons($current_ts, $range='1 minute')
    {
        $current = date('Y-m-d h:i:s', $current_ts);
        $range = '+'.str_replace('+','', $range);
        $range .= (int)$range[0]<=1 ?'' :'s';
        $next = date('Y-m-d h:i:s',strtotime($range, $current_ts));
        //echo "\r\n".'Find crons between '.$current.' and '.$next;
        $crons = [];
        
        $scheduled = parse_ini_file('data/cron-schedule.ini');
        $scheduled_crons = $scheduled=='' || !count($scheduled) ?[] :$scheduled;
        
        $mappings = self::getMappingList();
        foreach($scheduled_crons as $cron_id=>$ts)
        {
            $ts = (double)$ts;
            $date = date('Y-m-d h:i:s', $ts);
            //echo "\r\n".'Checking Cron:'.array_search($cron_id, $mappings).' ['.$cron_id.'], Time:'.$date; 
            if($date>=$current && $date<=$next)
            {
                //echo "\r\n".'Cron '.$cron_id.' is falling between this time. Adding it to list';
                $crons[$cron_id] = $ts;
            }
        }
        return $crons;
    }

    /*
    adds mapped array of cronId and it's next timestep (time at which next execution will occur for this cron) in cron-schedule.ini*/
    public function setCronSchedule($cron, $prev_ts=false)
    {
        $cron = self::cronTrimEnds($cron);
        //echo "\r\n".'Setting nextSchedule for cron: '.$cron.' has prevTime:'.date('Y-m-d h:i:s',$prev_ts);
        $next = self::cronGetNextTime($cron, $prev_ts);
        //echo "\r\n".'Next schedule for cron is at:'.date('Y-m-d h:i:s',$next);
        $cronId = self::getCronId($cron);
        if($cronId)
        {
            $schedule = !file_exists('data/cron-schedule.ini') ?[] :parse_ini_file('data/cron-schedule.ini');
            $schedule[$cronId] = $next;
            $str = '';
            foreach($schedule as $cronId=>$ts)
                $str .= "$cronId = $ts"."\r\n";
            file_put_contents('data/cron-schedule.ini',rtrim($str,"\r\n"));
        }
    }

    public function cronGetNextTime($cron, $prev_ts=null)
    {
        //echo "\r\n".'Getting nextSchedule for cron: '.$cron;
        $temp = explode(' ', $cron);

        $time['min']        = $temp[0];
        $time['hour']       = $temp[1];
        $time['day']        = $temp[2];
        $time['month']      = $temp[3];
        $time['weekday']    = $temp[4];
        $time['year']       = $temp[5];

        $next = $prev_ts ?strtotime('+1 minute', $prev_ts) :strtotime(date('Y-m-d h:i:s'));
        //for any of the bits, do nothing for JUST recursive bits
        foreach($time as $type=>$bit)
        {
            if($type!='year')
            {
                $temp = self::getTimeFromBit($bit, $type, $next);
                //echo "\r\n".'Time after processing bit:'.$type.':'.date('Y-m-d h:i:s',$temp);
                $next = $temp;
            }
        }
        $next = strtotime(date('Y-m-d h:i', $next));//round off the time
        echo "\r\n"."Cron '$cron' scheduled at: ".date('Y-m-d h:i:s', $next);
        return $next;
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
            $current = date('i');//hours, from 0-59
            $max = 59;
        }
        $current = (int)$current;
        $type .= 's';//make it plural
        if($range = self::isRangeBit($bit,$type))
        {
            if($current < $range[0])
                $ts = $range[0] - $current;
            else if($current > $range[1])
                $ts = $max - $current + $range[0];//eg, (7-6)+2 for a range 2-4 and weekday as 6
            else
                $ts = 0;
            //echo "\r\n".'Setting next bit for '.$type.':'.($ts>0 ?"+$ts $type to ".date('Y-m-d h:i:s') :'');
            $next = $ts >0 ?strtotime("+$ts $type", $next) :$next;
        }
        else if($gap = self::isRepeativeBit($bit))
        {
            //echo "\r\n".'It was a repeative bit ('.$bit.') for type:'.$type.' '.$gap;
            if(self::isRecursiveBit($bit))
            {
                //echo "\r\n".'Setting next bit for '.$type.':'.($gap>0 ?"+$gap $type to ".date('Y-m-d h:i:s',$next) :'').' >';
                $next = $gap>0 ?strtotime("+$gap $type", $next) :$next;
                //echo date('Y-m-d h:i:s', $next);
            }
            else
            {
                $ts = $max - $current + $gap;
                //echo "\r\n".'Current:'.$current.', gap:'.$gap;
                $next = strtotime("$ts $type", $next);
                //echo "\r\n".'SPECIFIC bit for '.$type.':'.$ts.', Time:'.date('Y-m-d h:i:s', $next);
            }
        }
        return $next;
    }

    public function getCronId($cron)
    {
        $list = self::getMappingList();
        return !empty($list[$cron]) ?$list[$cron] :null;
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
            $result = exec($cron);
            $ts = date('Y-m-d h:i:s');
            echo $result."\r\n";
            echo "[$ts] Cron executed successfully!"."\r\n";
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
        return strtotime(date('Y-m-d h:i:s'));
    }

    public function getExecutableCron($cron)
    {
        $cron = self::cronTrimEnds($cron);
        $cron = preg_replace("/\\+/","\\\\\\\\", $cron);//double-escape the backslashes: once for PHP and once for the shell.
        $cron = explode(' ', $cron);
        $temp = [];
        //remove schedule from cron
        for($i=5; $i<count($cron); $i++)
            $temp[] = $cron[$i];
        $cron = join(' ', $temp);
        $cron = trim($cron);
        return $cron;
    }
    
    public function cronTrimEnds($cron)
    {
        $cron = trim($cron, "'");
        $cron = trim($cron, '"');
        return $cron;
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
           // echo "\r\n".'Mapping file missing. Creating new';
            @mkdir('data',0777,true);
            file_put_contents('data/map.ini','');
        }
        if(!count(self::$mappings))
        {
            //echo "\r\n".'Mapping list is empty. Getting list from file:';            
            $mappings = parse_ini_file('data/map.ini');
            self::$mappings = !count($mappings) ?[] :$mappings;
            //print_r(self::$mappings);
        }
        return self::$mappings;
    }

    public function run()
    {
        ob_start();
        echo "[".date('Y-m-d h:i:s')."] Starting cron execution";
        if(!self::isRunning()) 
            self::init();
        self::getMappingList();
        
        $now = strtotime(date('Y-m-d h:i:s'));
        file_put_contents('data/status.ini', "ts = ".$now);
        
        $mtime = filemtime('php/crontab.php');
        //cron file updated; add schedule for new crons
        if(date('Y-m-d h:i',strtotime("+1 minute", $mtime)) >= date('Y-m-d h:i',$now) || !count(self::$mappings))
        {
            echo "\r\n".'Scheduling new crons:';
            $added_crons = self::createMappingList($get_new=true);
            foreach($added_crons as $cron=>$id)
            {
                self::setCronSchedule($cron, strtotime('-1 minute',$now));
            }
        }
        $crons = self::getScheduledCrons($now);
        echo "\r\n".'Scheduled crons for this iteration: '.(count($crons)>0 ?"\r\n".print_r($crons,true) :'None');

        $now = date('Y-m-d h:i:s');
        echo "\r\n"."[$now] Cron list started"."\r\n";
        foreach($crons as $cron_id=>$ts)
        {
            $cron = array_search($cron_id, self::$mappings);
            self::cronExecute($cron);
            self::setCronSchedule($cron, $ts);
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