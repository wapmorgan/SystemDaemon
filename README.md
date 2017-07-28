# SystemDaemon
Simple base for creation daemons.

### Why do you need SystemDemon?

1. You want to create a daemon (the process that works in the background) and be able to manage it
2. You want to get rid of unnecessary dependencies in the project
3. You do not want to manually make a base for such a demon

If all three items are valid for your situation, SystemDaemon will satisfy all your needs.

**What you need to create your own demon:**

1. Create a class that inherits `AbstractDaemon` and implement methods for working in the background
2. Run an instance of this class or use `DaemonManager`

In fact, you can avoid creating a new class and use the base class to create a daemon to see it's work. Also we will use `DaemonManager` not to write the code for processing user commands for starting / stopping the daemon.

`DaemonManager` processes input in the command line and runs the necessary methods of the `AbstractDaemon` class to start, stop, check the status of the daemon and etc.

The simplest daemon that simply outputs simple messages to the log:
```php
// adjust here path to composer autoloader!
require_once __DIR__.'/../vendor/autoload.php';

use wapmorgan\SystemDaemon\AbstractDaemon;
use wapmorgan\SystemDaemon\DaemonManager;

$daemon = new AbstractDaemon();
$daemon->name = 'example';
$daemon->setLogger(AbstractDaemon::FILES);

(new DaemonManager($daemon))->handleConsole($argc, $argv);
```

Save this file as **daemon** and run it using php:

```sh
$ php daemon
```

You will see a simple help listing the valid commands for starting / stopping / checking the status of the job:

```
Manager for daemon "example". Available operations: daemon (start | status | stop | kill)
```

To start the daemon, simply call the same script with the `start` command. If successful, the process ID after the start will be displayed, which can be used to view the statistics of this process.

```sh
$ php daemon start
Successfully started. Pid is 8868
```

_Since logging was enabled, you can open the file **/tmp/daemon-example.log** (or **/var/log/daemon-example.log** if permissions are allowed) and see what the daemon writes:_

```
2017-07-28 23:56:40 info: This is a informing message. Reimplement wapmorgan\SystemDaemon\AbstractDaemon::onStart() method to do real work.
2017-07-28 23:56:42 info: This is a informing message. Reimplement wapmorgan\SystemDaemon\AbstractDaemon::onStart() method to do real work.
2017-07-28 23:56:44 info: This is a informing message. Reimplement wapmorgan\SystemDaemon\AbstractDaemon::onStart() method to do real work.
...
```
This is a standard stub message that is written to the log, unless the standard daemon methods in which all the work is done have been redefined.

You can not use `DaemonManager`, if it is not necessary. Then, to control the daemon, use the following methods of `AbstractDaemon`:

- `start()`:  returns process ID of started daemon.
- `stop()`: returns `true` if daemon stopped, `false` if not, `null` if was not running. This operation is preferred over `kill()` because you can catch this action in daemon and correctly finish it.
- `kill()`: returns `true` if daemon stopped, `false` if not, `null` if was not running. This operation performs system kill, which just terminates script.
- `getStatus()`: return `false` if daemon is not running, `stdClass` object with daemon information if running.

All these methods can throw exceptions if something goes wrong (for example, there is not enough permissions to remove the lock file, if the unprivileged user is trying to stop the daemon started by root), so take care of handling such situations.

To resume: the main features that `AbstractDaemon` provides:
1. Start the daemon in the background. The ban on running multiple copies of the daemon using a lock file.
2. Create a log file and write messages to it.
3. The daemon can be of two types: a normal daemon (in which the `onStart()` method is started once and all the work is done) and a daemon waking up at certain intervals (called "ticks") and executing the `onTick()` method.

# Types of daemons
The created daemon can work in two modes:

1. **Normal**. When one method is started and it processes some external data (for example, listening on a socket). This mode is used by default. To create your daemon, you need to override the `onStart()` method, which will be called when the daemon starts. For example, this:

  ```php
  class MyDaemon extends AbstractDaemon
  {
      protected function onStart()
      {
          // here's your daemon's code
      }
  }
  ```

2. **Ticking**. When a demon wakes up at regular intervals, it gets some information, does its job and falls asleep until the next "tick". To use this mode, you need to transfer the `AbstractDaemon::TICKABLE` value and the time that the daemon will wake up (in seconds) when creating a class instance. For example. So:

  ```php
  $daemon = new MyDaemon(AbstractDaemon::TICKABLE, 2); // this daemon will "tick" every 2 seconds
  ```
  
  To create such a daemon, you need to override the `onTick()` method, in which specify operations of the daemon within one tick:
  
  ```php
  class MyDaemon extends AbstractDaemon
  {
      protected function onTick()
      {
          // here's your daemon's code on every tick
      }
  }
  ```
  
 ## Stopping
 Stop processing for each type of its own:

1. A normal daemon processes a stop event (called by the `stop()` method) in the `handleStop()` method. You can override this method and make closing some sockets or setting a completion flag there, and in `onStart()` do a check: if the flag was set, finish the data processing and exit the method. In the base class, `handleStop()` sets the `$running` property of the daemon to **false**, so you can just check sometimes if that value is set there and shut down.
For example, you can do this:

  ```php
  class MyDaemon extends AbstractDaemon
  {
      protected $fp;

      protected function handleStop()
      {
          fclose($this->fp);
          $this->fp = null;
      }

      protected function onStart()
      {
          $this->fp = fopen('example-file', 'r');
          while (is_resource($this->fp)) {
              // data processing
          }
      }
  }
  ```
  
2. The ticking demon is easier to use: it continues its ticks until the stop command comes. Thus, you do not need to manually process the commands: the daemon will end after the tick, at the time of processing of which this command came.

## Other signals
You have the ability to process other signals sent by the kill command. At the moment, it is possible to process two signals, which can be used as buffer reset commands, clearing the cache, or something else. These commands are: `SIGUSR1` and `SIGUSR2`.
To process them in the daemon, reimplement the `onSigUsr1()` and `onSigUsr2()` methods, respectively.

To reboot the daemon settings using the USR1 command, you can implement such a daemon:
```php
class MyDaemon extends AbstractDaemon
{
    protected $config;
    
    protected function onSigUsr1()
    {
        $this->loadConfiguration();
    }
    
    protected function onStart()
    {
        $this->loadConfiguration();
        // ...
    }
    
    protected function loadConfiguration()
    {
        $this->config = json_decode(file_get_contents(__DIR__.'/config.json'));
    }
}
```

## Logging
You can use message logging. To enable logging, you must call the `setLogger($logger)` method after creating the daemon object. Two types of logging are supported:
1. Logging to syslog. Example of use:
```php
$daemon = new AbstractDaemon();
$daemon->setLogger(AbstractDaemon::SYSLOG);
```

2. Logging to a separate file. To enable logging in a separate file (/var/log/daemon-*daemon name*.log, if permissions allow, or /tmp/daemon-*name*.log):
```php
$daemon = new AbstractDaemon();
$daemon->setLogger(AbstractDaemon::FILES);
```

To send messages to the log in the daemon use `log($level, $message)` method, where `$level` is one of the predefined message severity levels and `$message` messages.

Predefined levels of importance are:
- `AbstractDaemon::ERROR`
- `AbstractDaemon::WARNING`
- `AbstractDaemon::NOTICE`
- `AbstractDaemon::INFO`
- `AbstractDaemon::DEBUG`
