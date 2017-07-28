<?php
namespace wapmorgan\SystemDaemon;

use wapmorgan\SystemDaemon\Utilities\WindowsFork;
use Exception;

class AbstractDaemon
{
    const DEFAULT_PID_FILE = '{tmp}/daemon-{name}.pid';
    const DEFAULT_LOCK_FILE = '{tmp}/daemon-{name}.lock';
    const DEFAULT_LOG_FILE = '/var/log/daemon-{name}.log';

    /**
     * Daemon types
     */
    const NORMAL = 1;
    const TICKABLE = 2;

    /**
     * Log targets
     */
    const SYSLOG = 1;
    const FILES = 2;
    const FILES_DEBUG = 3;

    /**
     * Log message levels
     */
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    /**
     * @var string Short name of this daemon.
     * Used for generating pid and lock files for this daemon.
     */
    public $name;

    /**
     * @var string Full name of this daemon.
     * Used to show in managment console.
     */
    public $fullname;

    /**
     * @var string Daemons group.
     * Used to manage all daemons within a group.
     */
    public $group = 'default';

    /**
     * Settings:
     * @var boolean $usePidFile Should be or should not be used pid files for this daemon.
     * @var boolean $useLockFile Should be or should not be used lock files for this daemon.
     */
    public $usePidFile = true;
    public $useLockFile = true;

    /**
     * @var string The location of pid file for this daemon.
     * By default used AbstractDaemon::DEFAULT_PID_FILE template
     */
    public $pidFile;

    /**
     * @var string The location of lock file for this daemon.
     * By default used AbstractDaemon::DEFAULT_LOCK_FILE template
     */
    public $lockFile;

    /**
     * @var integer Stragery of daemon.
     */
    protected $strategy;

    /**
     * @var float Tick time for tickable daemon
     */
    protected $tickTime = null;

    /**
     * @var boolean Flag used as an indicator of running
     */
    protected $ticking = true;

    /**
     * @var boolean Flag used as an indicator of running
     */
    protected $running = true;

    /**
     * @var null|Loggers\* Logger of daemon.
     * If null, no logs will be collected.
     */
    protected $logger;

    /**
     * Builds a daemon.
     * @param integer $strategy
     * @param integer $tickTime Time between ticks. Valueable only if ticking strategy used.
     */
    public function __construct($strategy = self::NORMAL, $tickTime = null)
    {
        if ($strategy === self::TICKABLE) {
            $this->strategy = self::TICKABLE;
            if (!is_numeric($tickTime) || $tickTime === 0)
                throw new Exception('Tick time should be positive integer!');
            $this->tickTime = $tickTime;
        } else
            $this->strategy = self::NORMAL;

        $this->pidFile = static::DEFAULT_PID_FILE;
        $this->lockFile = static::DEFAULT_LOCK_FILE;
    }

    /**
     * Sets logger for daemon.
     * @param integer $log
     */
    public function setLogger($log = self::SYSLOG)
    {
        if ($log === self::FILES || $log === self::FILES_DEBUG) {
            $log_file = strtr(static::DEFAULT_LOG_FILE, [
                '{name}' => $this->name,
            ]);
            $this->logger = new Loggers\FilesLogger($log_file);
        } else
            $this->logger = new Loggers\SyslogLogger();
        return $this;
    }

    /**
     * Starts daemon.
     * @return integer Process ID of daemon.
     */
    public function start()
    {
        $this->ensureNotLocked();
        $pid = $this->fork();

        // return pid
        if ($pid > 0)
            return $pid;

        declare(ticks=1);

        $this->lock();
        $this->registerSignalHandlers();
        if ($this->strategy === self::NORMAL)
            $this->onStart();
        else
            $this->startTicking();
        $this->unlock();
        exit(0);
    }

    /**
     * Returns status about a daemon.
     * Notice: if lock files are not used, this method alwats returns false.
     * @return boolean|array Returns false if daemon is not running
     */
    public function getStatus()
    {
        if (!$this->useLockFile)
            return false;

        $lock_file = strtr($this->lockFile, [
            '{tmp}' => sys_get_temp_dir(),
            '{name}' => $this->name,
        ]);

        if (!file_exists($lock_file))
            return false;

        if (!is_readable($lock_file))
            throw new Exception('Lock file can not be read ('.$lock_file.'). Is daemon launched by root?');

        $lock_data = json_decode(file_get_contents($lock_file));

        // corrupted lock data, delete lock
        if (!is_object($lock_data)) {
            if (!unlink($lock_file))
                throw new Exception('Can not delete messed lock file ('.$lock_file.').');
            return false;
        }

        // check if daemon is still running
        if (!is_dir('/proc/'.$lock_data->pid)) {
            if (!unlink($lock_file))
                throw new Exception('Can not delete invalid lock file ('.$lock_file.').');
            return false;
        }

        return $lock_data;
    }

    /**
     * Stops daemon.
     * @return null|boolean True if daemon is stopped. Null if it was not running.
     */
    public function stop()
    {
        $lock_data = $this->getStatus();
        if ($lock_data === false)
            return null;

        return posix_kill($lock_data->pid, SIGTERM);
    }

    /**
     * Kills daemon.
     * @return null|boolean True if daemon is stopped. Null if it was not running.
     */
    public function kill()
    {
        $lock_data = $this->getStatus();
        if ($lock_data === false)
            return null;

        return posix_kill($lock_data->pid, SIGKILL);
    }

    protected function ensureNotLocked()
    {
        if (!$this->useLockFile)
            return true;

        $lock_file = strtr($this->lockFile, [
            '{tmp}' => sys_get_temp_dir(),
            '{name}' => $this->name,
        ]);

        if (!file_exists($lock_file))
            return true;

        if (!is_readable($lock_file))
            throw new Exception('Lock file can not be read ('.$lock_file.'). Is daemon launched by root?');

        $lock_data = json_decode(file_get_contents($lock_file));

        // corrupted lock data, delete lock
        if (!is_object($lock_data)) {
            if (!unlink($lock_file))
                throw new Exception('Can not delete messed lock file ('.$lock_file.').');
        }

        // check if daemon is still running
        if (!is_dir('/proc/'.$lock_data->pid)) {
            if (!unlink($lock_file))
                throw new Exception('Can not delete invalid lock file ('.$lock_file.').');

            return true;
        }

        throw new Exception('Daemon is still running (PID '.$lock_data->pid.').');
    }

    protected function fork()
    {
        if (function_exists('pcntl_fork')) {
            $result_pid = pcntl_fork();

            // failure
            if ($result_pid === -1) {
                throw new Exception('Can\'t fork. Check system settings.');
            }

            return $result_pid;
        }

        throw new Exception('Unable to fork.');
    }

    protected function lock()
    {
        if (!$this->useLockFile)
            return true;

        $lock_file = strtr($this->lockFile, [
            '{tmp}' => sys_get_temp_dir(),
            '{name}' => $this->name,
        ]);

        $lock_data = [
            'pid' => getmypid(),
            'uid' => getmyuid(),
            'gid' => getmygid(),
            'group' => $this->group,
        ];

        if (!file_put_contents($lock_file, json_encode($lock_data)))
            throw new Exception('Can\'t write lock file. Do you have sufficient permissions?');

        return true;
    }

    protected function unlock()
    {
        if (!$this->useLockFile)
            return true;

        $lock_file = strtr($this->lockFile, [
            '{tmp}' => sys_get_temp_dir(),
            '{name}' => $this->name,
        ]);

        if (file_exists($lock_file)) {
            if (!unlink($lock_file))
                throw new Exception('Can not delete lock file ('.$lock_file.').');
        }

        return true;
    }

    protected function registerSignalHandlers()
    {
        pcntl_signal(SIGTERM, [$this, 'onSigTerm']);
        pcntl_signal(SIGUSR1, [$this, 'onSigUsr1']);
        pcntl_signal(SIGUSR2, [$this, 'onSigUsr2']);
    }

    protected function onSigTerm()
    {
        if ($this->strategy === self::TICKABLE)
            $this->ticking = false;
        else
            $this->handleStop();
    }

    protected function handleStop()
    {
        $this->log(self::INFO, 'This is a informing message when calling handleStop() method on normal daemon. Reimplement '.get_class($this).'::handleStop() method to change this behavior.');
        $this->running = false;
    }

    protected function onSigUsr1()
    {
        $this->log(self::INFO, 'Received SIGUSR1 signal. Reimplement '.get_class($this).'::onSigUsr1() method to change this behavior.');
    }

    protected function onSigUsr2()
    {
        $this->log(self::INFO, 'Received SIGUSR2 signal. Reimplement '.get_class($this).'::onSigUsr2() method to change this behavior.');
    }

    protected function onStart()
    {
        while ($this->running) {
            $this->log(self::INFO, 'This is a informing message. Reimplement '.get_class($this).'::onStart() method to do real work.');
            sleep(2);
        }
    }

    protected function startTicking()
    {
        while ($this->ticking) {
            $this->onTick();
            sleep($this->tickTime);
        }
    }

    protected function onTick()
    {
        $this->log(self::INFO, 'This is a informing message. Reimplement '.get_class($this).'::onTick() method to do real work.');
    }

    protected function log($level, $message)
    {
        if ($this->logger !== null)
            $this->logger->log($level, $message);
    }
}
