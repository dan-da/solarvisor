# solarvisor

A script that runs loads opportunistically by monitoring battery/charge levels
to start/stop child processes when power is available.

# description

This script was written with a single goal in mind: to opportunistically run
heavy energy loads when sunlight is available and stop them when it is not.

Some common types of opportunistic loads are:
  + bitcoin (or altcoin) mining.
  + hot water heating
  + space heating
  + air conditioning / climate control.
  + ventilation
  
But really how you use it is up to you.  solarvisor doesn't care.

# features

* starts and stops processes (loads) based on current battery voltage.
* user-definable start/stop voltage levels
* defaults for common nominal voltages:  12,24,36,48,72
* supports arbitrary voltages.  nominal voltage not required.
* failsafe mode daylight time window in case battery voltage cannnot be read.
* max_stops_per_day setting prevents intermittent running on cloudy days.

# how it operates

solarvisor is invoked with an argument --load-cmd that represents the power
load. The load-cmd (program) is created by the user and can do anything but
it is important that it starts the load when executed and stops it when killed.

Let's use a 48v nominal system for example. A minimum voltage and a start
voltage are defined, by default 51 and 53 respectively.

The script runs in a loop and checks battery voltage each minute.

If the battery voltage is above 53 then the load-cmd is started. If the
voltage drops below 51 then the load-cmd is killed.

This process continues forever.

The difference between 51 and 53 is necessary to prevent flutter, and is
adjustable. When the load starts, the voltage will drop somewhat. If it dropped
below 51 then the load would be stopped, at which point the voltage would rise
again, and the load would be started again... and so on. The best values to use
depend on many factors (system size, load size, sunlight, battery size/charge,
etc). solarvisor has default values for common nominal voltages, but you are
encouraged to tweak as necessary for your application.

## failsafe mode

At times, the battery voltage may not be available.  In this case, failsafe mode
activates. In failsafe mode, solarvisor uses a time window during which energy
is expected to be available. In a solar system, these would be daylight hours.

In this mode the load-cmd will be started when entering the window and
stopped when leaving the window.

# Flutter prevention and cloudy days.

On cloudy days, there may not be enough energy available to even charge your
battery much less operate a heavy load.

To help detect and deal with this, solarvisor has a setting max_stops_per_day which
is set to 1 by default.

So let's say that on a cloudy day the sun pops out for a bit and the voltage
gets up high enough to start the load the first time. Then a big black cloud
comes and maybe some rain. The voltage quickly drops down below the min_volts
setting and the load is stopped. Then the sun comes out again and
voltage passes the start_volts setting. This time, the load will *not* be
started because we have already reached the max_stops_per_day setting of 1.

Depending on your situation, you might wish to raise this setting.

# example -- log output

# Let's see an example session over a couple days.

```
2016-06-09T10:05:22-07:00 -- Battery voltage is 54.5.  (above 53).  starting service
pid = 18713
2016-06-09T10:06:24-07:00 -- Battery voltage is 54.4 and service is running.  No action taken
2016-06-09T10:07:25-07:00 -- Battery voltage is 54.3 and service is running.  No action taken
...
2016-06-09T11:08:50-07:00 -- Battery voltage not read. failsafe mode.
2016-06-09T11:08:50-07:00   -- No action taken.

```

# obtaining battery voltage

The trickiest part of all this is reading the battery's voltage.  Methods for doing
this can vary from system to system.

Right now, solarvisor makes an http request to a local server running an
instance of theblackboxproject, which is a PHP web interface to a midnite
classic controller.

If you are using solarvisor you will need to modify the function
get_battery_info() to talk to your charge controller or battery monitor system.

In the next release, solarvisor will call an external script that returns
battery info, usually from a charge controller.

The info will returned in a common, documented format.


# Usage

```
   solarvisor.php

   This script starts and stops processes according to battery level.

   Required:
    --start-cmd=<cmd>   cmd to start process.
    --force-start       exec cmd initially irregardless.

   Options:

    -h                Print this help.
```


# Installation and Running.

PHP 5.4+ is required. So far solarvisor has only been tested on Linux. It should
work on other unix systems including Mac. It will not work on Windows at
present.

There are no other dependencies.

Assuming you have PHP installed, you can run solarvisor via:

```php solarvisor.php```

or

```./solarvisor.php```



# Todo Ideas

* Obtain battery info from external script. ( user's can provide their own )
* Implement events/modes/settings for charge control modes (bulk/absorb/float/sleep)
* Make a way to signal solarvisor to start/stop load on demand without killing it.
* cleaner output/display, perhaps ncurses based.


# Use at your own risk.

The author makes no claims or guarantees of correctness.

