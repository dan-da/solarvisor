#!/usr/bin/env php

<?php

exit( main( $argv ) );

/**
 * program main().
 */
function main( $argv ) {
    
    // -- User Adjustable Settings --
    $settings = [
        // start of usable daylight power. failsafe in case voltage unavailable.
       'failsafe-window-starttime' => '9:00',
       
        // start of usable daylight power. failsafe in case voltage unavailable.
       'failsafe-window-stoptime' => '19:00',
       
        // prevent flutter, especially on cloudy days.
       'max-stops-per-day' => 1, 
    ];
    // -- End User Adjustable Settings --
    
    $params = get_params();
    $rc = check_params( $params );
    if( $rc != 0 ) {
        return $rc;
    }
    
    $params = array_merge( $settings, $params );
    print_params( $params );
    
    setup_signal_handlers();
    run_main_loop( params_2_settings( $params ) );   
}

/**
 * prints settings at startup
 */
function print_params( $params ) {
    echo date('c') . " -- solarvisor starting with these settings:\n---\n";
    echo trim( json_encode( $params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "{}" );
    echo "\n---\n\n";
}

/**
 * convert params with hyphens to underscores.
 */
function params_2_settings( $params ) {
    $settings = [];
    foreach( $params as $k => $v ) {
        $k = str_replace( '-', '_', $k);
        $settings[$k] = $v;
    }
    return $settings;    
}

/**
 * main loop of program.  the guts.
 */
function run_main_loop( $settings ) {
    extract( $settings );

    $stops_today = 0;
    $today = date('Y-m-d');

    $proc = &proc::$proc;
    $cnt = 0;
    while( true ) {
        $running = $proc ? $proc->status() : false;
        $batt_info = get_battery_info();
        $volts = @$batt_info['Output Voltage'];  // fixme:  normalize.
        $time = date('c');

        // reset daily stop counter on date change.
        if( date('Y-m-d') != $today ) {
            $stops_today = 0;
            $today = date('Y-m-d');
        }

        if( $cnt++ == 0 && $force_start ) {
           echo sprintf( "$time -- Forcing start because --force-start used.  starting\n" );
           $proc = new process($load_cmd, $log_file);
        }
        else if( !$running && $stops_today >= $max_stops_per_day ) {
            echo sprintf( "Daily stop limit (%s) reached. no more starts until tomorrow\n" );
            sleep(60);
            continue;
        }
        // enter failsafe mode if voltage not read.
        else if( !$volts ) {
            $startafter = strtotime( $failsafe_window_starttime );
            $stopafter = strtotime( $failsafe_window_stoptime );
            echo sprintf( "$time -- Battery voltage not read. failsafe mode.\n", $volts );
            
            $ctime = time();
            if( !$running && $ctime > $startafter && $ctime < $stopafter ) {
                echo sprintf( "$time   -- within failsafe operation window and service not running. starting\n" );
                $proc = new process( $load_cmd, $log_file );
            }
            else if( $running && $ctime < $startafter && $ctime > $stopafter && $proc ) {
                echo sprintf( "$time   -- outside failsafe operation window and service is running. stopping\n" );
                $stops_today ++;
                $proc->stop();
            }
            else {
                echo sprintf( "$time   -- No action taken.\n" );
            }
        }
        else if( $running && $volts < $volts_min ) {
            echo sprintf( "$time -- Battery voltage is %s. (below $volts_min).  stopping service\n", $volts ) ;
            $stops_today ++;
            $proc->stop();
        }
        else if( !$running && $volts >= $volts_start_min ) {
            echo sprintf( "$time -- Battery voltage is %s.  (above $volts_start_min).  starting service\n", $volts );
            $proc = new process( $load_cmd, $log_file );
        }
        else {
            echo sprintf( "$time -- Battery voltage is %s and service is %srunning.  No action taken\n", 
                          $volts,
                          $running ? '' : 'not ' );
        }
        sleep(60);
    } 
}

/**
 * retrieves CLI args
 */
function get_params() {
    
    $opt = getopt("ha:sp:", [ 'load-cmd:', 'force-start', 'nominal:',
                              'volts-min:', 'volts-start-min:', 'log-file:'] );
                             
    $params['load-cmd'] = @$opt['load-cmd'];
    $params['force_start'] = isset( $opt['force-start'] );
    $params['nominal'] = @$opt['nominal'] ?: 48;
    $params['volts-min'] = @$opt['volts-min'];
    $params['volts-start-min'] = @$opt['volts-start-min'];
    $params['log-file'] = @$opt['log-file'] ?: '/dev/null';
    
    return $params;
}

/**
 * validates CLI args, modifies as needed.
 * prints error message on any validation error.
 * returns 0 on success.  else program should exit.
 */
function check_params( &$params ) {
    
    if( isset($opt['h']) || !($params['load-cmd'] ) ){
        print_help();
        return -1;
    }
    
    $nominal = $params['nominal'];
    $nominal_limits = get_nominal_voltages( $params['nominal'] );
    
    if( !@$nominal_limits ) {
        if( $nominal != 'none' ) {
            echo sprintf( "warning: nominal voltage '%s' is unknown.  assuming --nominal=none\n", $nominal );
        }
        
        if( !$params['volts-min'] || !$params['volts-start-min'] ) {
            echo sprintf( "volts-min and volts-start-min are required when nominal=none.\n" );
            return 1;
        }
    }
    else {
        // this checks prevent conflicts between --nominal and [--volts-min, --min-start_volts]
        if( $params['volts-min'] && !between($params['volts-min'], $nominal_limits['range-min'], $nominal_limits['range-max'] )) {
            echo sprintf( "volts-min %s outside range for nominal voltage: $nominal\n", $params['volts-min'] );
            return 1;        
        }
        else if( $params['volts-start-min'] && !between($params['volts-start-min'], $nominal_limits['range-min'], $nominal_limits['range-max'] )) {
            echo sprintf( "volts-start-min %s outside range for nominal voltage: $nominal\n", $params['volts-start-min']);
            return 1;        
        }
        // use user-supplied values if supplied, else defaults for specified nominal voltage.
        $params['volts-min'] = @$params['volts-min'] ?: $nominal_limits['volts-min'];
        $params['volts-start-min'] = @$params['volts-start-min'] ?: $nominal_limits['volts-start-min'];
    }
    
    
    if( $params['volts-min'] >= $params['volts-start-min'] ) {
        echo sprintf( "volts-start-min (%s) must be greater than volts-min (%s)\n", $params['volts-start-min'], $params['volts-min']);
        return 1;
    }
    
    return 0;
}

/**
 * setup signal handlers for CTRL-C, TERM
 * needed so we can kill load-cmd before shutdown.
 */
function setup_signal_handlers() {
    
    declare(ticks = 50);
    pcntl_signal(SIGTERM, 'shutdown_cb');
    pcntl_signal(SIGINT, 'shutdown_cb');
    pcntl_signal(SIGCHLD, SIG_IGN);
}

/**
 * Returns table of nominal voltages if volts is null.
 * Otherwise, returns voltage info for specific voltage, or null if not found.
 */
function get_nominal_voltages( $volts = null ) {
    
    static $nominal_volts;
    
    if( $nominal_volts ) {
        return $nominal_volts;
    }
    
    // I start with 48 nominal because that is why my system uses and it is
    // easiest for me to think in those numbers.  I then calculate defaults
    // for other nominal voltages from there.
    $volts_min_48 = 51;
    $volts_start_min_48 = 53;
    $range_min_48 = 40;
    $range_max_48 = 64;
    
    $volts_min_12 = $volts_min_48 / 4;
    $volts_start_min_12 = $volts_start_min_48 / 4;
    $range_min_12 = $range_min_48 / 4;
    $range_max_12 = $range_max_48 / 4;
    
    $nominal_volts = [12 => ['volts-min' => $volts_min_12,
                             'volts-start-min' => $volts_start_min_12,
                             'range-min' => $range_min_12,
                             'range-max' => $range_max_12
                            ],
                      24 => ['volts-min' => $volts_min_12 * 2,
                             'volts-start-min' => $volts_start_min_12 * 2,
                             'range-min' => $range_min_12 * 2,
                             'range-max' => $range_max_12 * 2
                            ],
                      36 => ['volts-min' => $volts_min_12 * 3,
                             'volts-start-min' => $volts_start_min_12 * 3,
                             'range-min' => $range_min_12 * 3,
                             'range-max' => $range_max_12 * 3
                            ],
                      48 => ['volts-min' => $volts_min_48,
                             'volts-start-min' => $volts_start_min_48,
                             'range-min' => $range_min_48,
                             'range-max' => $range_max_48
                            ],
                      72 => ['volts-min' => $volts_min_12 * 6,
                             'volts-start-min' => $volts_start_min_12 * 6,
                             'range-min' => $range_min_12 * 6,
                             'range-max' => $range_max_12 * 6
                            ],
                      ];
    
    return $volts ? @$nominal_volts[$volts] : $nominal_volts;
}

/**
 * an abstract class to avoid a global var.  todo:  refactor.
 * ( needed for signal handler callback )
 */
abstract class proc {
    static $proc = null;
}

/**
 * retrieves battery info.
 * @todo: should be abstracted to call various implementations and return
 *        normalized dataset.
 */
function get_battery_info() {

    $url = 'http://192.168.2.201/theblackboxproject/htdocs/real.php';
    $buf = file_get_contents($url);
    $lines = explode( "\n", $buf );
    $info = [];
    foreach( $lines as $line ) {
    $row = explode( '|', $line );
        if( count( $row ) >= 2 ) {
            $info[$row[0]] = $row[1];
        }       
    }
    return $info;
}

// handle kill child process when we are killed via SIGTERM or SIGINT
function shutdown_cb( $signo=null ) {
    // stop child process if we are terminated.
    // must call exit, else CTRL-C will not work.
    echo "\nin signal handler! got signal $signo \n";

    $proc = &proc::$proc;
    if( $proc && $proc->get_pid() ) {
        echo sprintf( "stopping child process.  pid=%s...\n", $proc->get_pid() );;
        $rc = $proc->stop();
        echo $rc ? "  success!\n" : "  failed. The process is still running, but I must go... \n";
    }

    echo "exiting!\n";
    exit(0);
}

/**
 * returns true if value is between lower and upper.
 */
function between( $v, $lower, $upper ) {
    return $v >= $lower && $v <= $upper;
}


/**
 * prints help / usage.
 */
function print_help() {

   echo <<< END
   
      solarvisor.php

   This script starts and stops processes according to battery level.

   Required:
    --load-cmd=<cmd>       cmd to start process.

   Options:

    --force-start           exec cmd initially irregardless.
    --nominal=<volts>       nominal system voltage. 
                               12,24,36,48,72, or 'none'.  default = 48
    --volts-min=<v>         minimum voltage before stopping load.
                               default = 51 unless --nominal is used.
    --volts-start-min=<v>   minimum voltage before starting load.
                               default = 53 unless --nominal is used.
    --log-file=<path>       path to send load-cmd output. default = /dev/null
    
    -h                      Print this help.

END;

}

/**
 * A class to start/stop/status external commands.
 * @compatibility: Linux only. (Windows does not work).
 * @author: Peec
 * heavily modified by danda.
 */
class process{
    private $pid;
    private $command;
    private $logpath = '/dev/null';

    public function __construct($cl=false, $logpath){
        $this->logpath = $logpath;
        if ($cl != false){
            $this->command = $cl;
            $this->run();
        }
    }
    private function run(){
//        $command = sprintf( 'nohup %s > %s 2>&1 & echo $!', escapeshellcmd($this->command), $this->logpath );
        $command = sprintf( '%s > %s 2>&1 & echo $!', escapeshellcmd($this->command), $this->logpath );
        exec($command ,$op);
        $this->pid = (int)$op[0];
        echo "pid = " . $this->pid . "\n";
    }

    public function set_pid($pid){
        $this->pid = $pid;
    }

    public function get_pid(){
        return $this->pid;
    }

    public function status(){
        if( !$this->pid ) {
            return false;
        }
        return posix_kill( $this->pid, 0 );
    }

    public function start(){
        if ($this->command != '') {
            $this->run();
        }
        return true;
    }

    public function stop(){
        if( !$this->pid ) {
            return false;
        }

        if( !posix_kill( $this->pid, SIGTERM ) ) {
           echo posix_strerror( posix_get_last_error() );
        }

        sleep( 3 );
        if ($this->status() == false) {
            $this->pid = null;
            return true;
        }

        // kill -9
        if( !posix_kill( $this->pid, SIGKILL ) ) {
           echo posix_strerror( posix_get_last_error() );
        }

        sleep(1);

        if ($this->status() == false) {
            $this->pid = null;
            return true;
        }
        return false;
    }
}


