<?php declare(strict_types=1);

namespace Swoft\TestLib;

use function array_pop;
use RuntimeException;
use function strpos;
use Swoole\Coroutine;
use Swoole\Coroutine\Client as TcpClient;
use Swoole\Coroutine\Http\Client as HttpClient;
use function count;
use function filter_var;
use function method_exists;
use function sprintf;
use const FILTER_VALIDATE_IP;

/**
 * Class BenchTesting
 *
 * from https://github.com/swoole/swoole-src/blob/master/benchmark/src/Base.php
 */
class SwooleBench
{
    protected const TCP_SENT_LEN = 1024;

    protected const TIMEOUT = 3; // seconds
    protected const PATH    = '/';
    protected const QUERY   = '';

    protected $nConcurrency = 100;
    protected $nRequest = 10000; // total
    protected $nShow;

    protected $scheme = 'http';
    protected $host;
    protected $port;
    protected $path;
    protected $query;

    protected $nRecvBytes = 0;
    protected $nSendBytes = 0;

    protected $requestCount = 0; // success
    // protected $connectCount = 0;
    protected $connectErrorCount = 0;
    protected $connectTime = 0;

    protected $keepAlive; // default disable
    protected $timeout; // seconds

    protected $startTime;
    protected $beginSendTime;
    protected $testMethod;

    protected $sentData;
    protected $sentLen = 0;

    protected $verbose;

    /**
     * @var string
     */
    private $data;

    /**
     * @var string
     */
    private $error = '';

    /**
     * @var string
     */
    private $scriptFile;

    /**
     * @var string
     */
    private $srvUrl;

    /**
     * Class constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        // $this->init();
    }

    /**
     * @param string $msg
     * @param mixed  ...$args
     *
     * @return bool
     */
    protected function addError(string $msg, ...$args): bool
    {
        if (count($args) > 0) {
            $msg = sprintf($msg, ...$args);
        }

        $this->error = $msg;

        return false;
    }

    /*****************************************************************************
     * Quick operate for CLI env
     ****************************************************************************/

    public function initFromCli(): bool
    {
        if (!$this->parseCliOpts()) {
            return false;
        }

        $scheme = $this->scheme;
        if (!$scheme || !method_exists($this, $scheme)) {
            return $this->addError('Not support pressure measurement objects %s', $scheme);
        }

        $this->testMethod = $scheme;

        if (!isset($this->port)) {
            switch ($scheme) {
                case 'tcp':
                    $this->port = 9501;
                    break;
                case 'http':
                    $this->port = 80;
                    break;
                case 'https':
                    $this->port = 443;
                    break;
                case 'ws':
                    $this->port = 80;
                    break;
                default:
                    break;
            }
        }

        return true;
    }

    protected function parseCliOpts(): bool
    {
        $cliArgs = $_SERVER['argv'];

        $this->scriptFile = $cliArgs[0];

        $shortOpts = 'c:n:l:s:t:d:khv';
        $optValues = getopt($shortOpts);

        if (!$optValues || isset($optValues['h'])) {
            $this->showCliHelp();
            return false;
        }

        if (isset($optValues['c']) && (int)$optValues['c'] > 0) {
            $this->nConcurrency = (int)$optValues['c'];
        }
        if (isset($optValues['n']) && (int)$optValues['n'] > 0) {
            $this->nRequest = (int)$optValues['n'];
        }
        $this->nShow = $this->nRequest / 10;

        if (isset($optValues['l']) && (int)$optValues['l'] > 0) {
            $this->sentLen = (int)$optValues['l'];
        }

        // if (!isset($opts['s'])) {
        //     return $this->addError('Require -s [server_url]. E.g: -s tcp://127.0.0.1:9501');
        // }

        $srvUrl = array_pop($cliArgs);
        if (strpos($srvUrl, '//') === false) {
            return $this->addError('Require server URL.  E.g: tcp://127.0.0.1:9501');
        }

        $urlInfo = parse_url($srvUrl);
        if (!$urlInfo) {
            return $this->addError('Invalid server URL');
        }

        $this->srvUrl = $srvUrl;
        $this->scheme = $urlInfo['scheme'];
        if (!filter_var($urlInfo['host'], FILTER_VALIDATE_IP)) {
            return $this->addError('Invalid IP address');
        }

        $this->host = $urlInfo['host'];
        if (isset($urlInfo['port']) && (int)$urlInfo['port'] > 0) {
            $this->port = $urlInfo['port'];
        }

        $this->path  = $urlInfo['path'] ?? self::PATH;
        $this->query = $urlInfo['query'] ?? self::QUERY;

        $this->timeout   = (int)($optValues['t'] ?? self::TIMEOUT);
        $this->keepAlive = isset($optValues['k']);
        $this->verbose   = isset($optValues['v']);

        if (isset($optValues['d'])) {
            $this->setSentData($optValues['d']);
        }

        return true;
    }

    public function showCliHelp(): void
    {
        $help = <<<HELP
A bench script by swoole, support test for tcp, http, ws.

Usage: php {$this->scriptFile} [OPTIONS] URL

Options:
  -c      Number of coroutine       E.g: -c 100
  -n      Number of requests        E.g: -n 10000
  -l      The length of the data sent per request    E.g: -l 1024
  -s      Server URL, support: tcp, http, ws         E.g: -s tcp://127.0.0.1:9501                    
  -t      Http request timeout detection
          Default is 3 seconds, -1 means disable
  -k      Use HTTP KeepAlive
  -d      HTTP post data
  -v      Flag enables verbose progress and debug output
  -h      Display this help

Example:
  {$this->scriptFile} -c 100 -n 10000 http://127.0.0.1:18306
  {$this->scriptFile} -c 100 -n 10000 tcp://127.0.0.1:18309
HELP;
        echo $help, "\n";
    }

    public function setSentData($data): void
    {
        $this->sentData = $data;
        $this->sentLen  = strlen($data);
    }

    protected function finish(): void
    {
        $costTime          = $this->format(microtime(true) - $this->startTime);
        $nRequest          = number_format($this->nRequest);
        $requestErrorCount = number_format($this->nRequest - $this->requestCount);
        $connectErrorCount = number_format($this->connectErrorCount);
        $nSendBytes        = number_format($this->nSendBytes);
        $nRecvBytes        = number_format($this->nRecvBytes);
        $requestPerSec     = $this->requestCount / $costTime;
        $connectTime       = $this->format($this->connectTime);

        echo <<<EOF
Benchmark testing for {$this->srvUrl}

Concurrency Level:      {$this->nConcurrency}
Time taken for tests:   {$costTime} seconds
Complete requests:      {$nRequest}
Failed requests:        {$requestErrorCount}
Connect failed:         {$connectErrorCount}
Total send:             {$nSendBytes} bytes
Total receive:          {$nRecvBytes} bytes
Requests per second:    {$requestPerSec}
Connection time:        {$connectTime} seconds
\n
EOF;
    }

    public function format($time): float
    {
        return round($time, 4);
    }

    /**
     * @param mixed ...$args The args allow int, string
     */
    public function println(...$args): void
    {
        echo implode(' ', $args), "\n";
    }

    /**
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }

    /*****************************************************************************
     * Bench for TCP server
     ****************************************************************************/

    public function tcp(): self
    {
        $cli = new TcpClient(SWOOLE_TCP);

        $n = $this->nRequest / $this->nConcurrency;
        Coroutine::defer(function () use ($cli) {
            $cli->close();
        });

        if ($this->sentLen === 0) {
            $this->sentLen = self::TCP_SENT_LEN;
        }
        $this->setSentData(str_repeat('A', $this->sentLen));

        if (!$cli->connect($this->host, $this->port)) { // connection failed
            if ($cli->errCode === 111) { // connection refuse
                throw new RuntimeException(swoole_strerror($cli->errCode));
            }
            if ($cli->errCode === 110) { // connection timeout
                $this->connectErrorCount++;
                if ($this->verbose) {
                    echo swoole_strerror($cli->errCode) . PHP_EOL;
                }

                return $this;
            }
        }

        while ($n--) {
            // request
            if (!$cli->send($this->sentData)) {
                if ($this->verbose) {
                    echo swoole_strerror($cli->errCode) . PHP_EOL;
                }
                continue;
            }
            $this->nSendBytes += $this->sentLen;
            $this->requestCount++;
            if (($this->requestCount % $this->nShow === 0) && $this->verbose) {
                echo "Completed {$this->requestCount} requests" . PHP_EOL;
            }

            //response
            $recvData = $cli->recv();
            if ($recvData === false && $this->verbose) {
                echo swoole_strerror($cli->errCode) . PHP_EOL;
            } else {
                $this->nRecvBytes += strlen($recvData);
            }
        }

        return $this;
    }

    protected function eof(): void
    {
        $eof = "\r\n\r\n";
        $cli = new TcpClient(SWOOLE_TCP);
        $cli->set(['open_eof_check' => true, 'package_eof' => $eof]);
        $cli->connect($this->host, $this->port);
        $n = $this->nRequest / $this->nConcurrency;
        while ($n--) {
            // request
            $data = $this->sentData . $eof;
            $cli->send($data);
            $this->nSendBytes += strlen($data);
            $this->requestCount++;
            // response
            $rdata            = $cli->recv();
            $this->nRecvBytes += strlen($rdata);
        }
        $cli->close();
    }

    protected function length(): void
    {
        $cli = new TcpClient(SWOOLE_TCP);
        $cli->set([
            'open_length_check'   => true,
            'package_length_type' => 'N',
            'package_body_offset' => 4,
        ]);
        $cli->connect($this->host, $this->port);
        $n = $this->nRequest / $this->nConcurrency;
        while ($n--) {
            //request
            $data = pack('N', strlen($this->sentData)) . $this->sentData;
            $cli->send($data);
            $this->nSendBytes += strlen($data);
            $this->requestCount++;
            //response
            $rdata            = $cli->recv();
            $this->nRecvBytes += strlen($rdata);
        }
        $cli->close();
    }

    /*****************************************************************************
     * Bench for HTTP server
     ****************************************************************************/

    public function http(): self
    {
        $httpCli = new HttpClient($this->host, $this->port);

        $n = $this->nRequest / $this->nConcurrency;
        Coroutine::defer(function () use ($httpCli) {
            $httpCli->close();
        });

        $headers = [
            'Host'         => "{$this->host}:{$this->port}",
            'Accept'       => 'text/html,application/xhtml+xml,application/xml',
            'content-type' => 'application/x-www-form-urlencoded',
        ];
        $httpCli->setHeaders($headers);

        $setting = [
            'timeout'    => $this->timeout,
            'keep_alive' => $this->keepAlive,
        ];
        $httpCli->set($setting);
        if (isset($this->sentData)) {
            $httpCli->setData($this->sentData);
        }

        $query = empty($this->query) ? '' : "?$this->query";

        while ($n--) {
            $httpCli->execute("{$this->path}{$query}");

            if (!$this->checkStatusCode($httpCli)) {
                continue;
            }

            $this->requestCount++;
            if ($this->requestCount % $this->nShow === 0 && $this->verbose) {
                echo "Completed {$this->requestCount} requests" . PHP_EOL;
            }
            $recvData         = $httpCli->body;
            $this->nRecvBytes += strlen($recvData);
        }

        return $this;
    }

    protected function checkStatusCode(HttpClient $httpCli): bool
    {
        if ($httpCli->statusCode === -1) { // connection failed
            if ($httpCli->errCode === 111) { // connection refused
                throw new RuntimeException(swoole_strerror($httpCli->errCode));
            }
            if ($httpCli->errCode === 110) { // connection timeout
                $this->connectErrorCount++;
                if ($this->verbose) {
                    echo swoole_strerror($httpCli->errCode) . PHP_EOL;
                }
                return false;
            }
        }

        if ($httpCli->statusCode === -2) { // request timeout
            if ($this->verbose) {
                echo swoole_strerror($httpCli->errCode) . PHP_EOL;
            }
            return false;
        }

        if ($httpCli->statusCode === 404) {
            $query = empty($this->query) ? '' : "?$this->query";
            $url   = "{$this->scheme}://{$this->host}:{$this->port}{$this->path}{$query}";
            throw new RuntimeException("The URL [$url] is non-existent");
        }

        return true;
    }

    /*****************************************************************************
     * Bench for WebSocket server
     ****************************************************************************/

    protected function ws(): self
    {
        $wsCli = new HttpClient($this->host, $this->port);
        $n     = $this->nRequest / $this->nConcurrency;
        Coroutine::defer(function () use ($wsCli) {
            $wsCli->close();
        });

        $setting = [
            'timeout'        => $this->timeout,
            'websocket_mask' => true,
        ];
        $wsCli->set($setting);
        if (!$wsCli->upgrade($this->path)) {
            if ($wsCli->errCode === 111) {
                throw new RuntimeException(swoole_strerror($wsCli->errCode));
            }

            if ($wsCli->errCode === 110) {
                throw new RuntimeException(swoole_strerror($wsCli->errCode));
            }

            throw new RuntimeException('Handshake failed');
        }

        if ($this->sentLen === 0) {
            $this->sentLen = self::TCP_SENT_LEN;
        }
        $this->setSentData(str_repeat('A', $this->sentLen));

        while ($n--) {
            // request
            if (!$wsCli->push($this->data)) {
                if ($wsCli->errCode === 8502) {
                    throw new RuntimeException('Error OPCODE');
                }

                if ($wsCli->errCode === 8503) {
                    throw new RuntimeException('Not connected to the server or the connection has been closed');
                }

                throw new RuntimeException('Handshake failed');
            }

            $this->nSendBytes += $this->sentLen;
            $this->requestCount++;
            if (($this->requestCount % $this->nShow === 0) && $this->verbose) {
                echo "Completed {$this->requestCount} requests" . PHP_EOL;
            }

            //response
            $frame            = $wsCli->recv();
            $this->nRecvBytes += strlen($frame->data);
        }

        return $this;
    }

    /*****************************************************************************
     * Do start bench testing
     ****************************************************************************/

    /**
     * @return self
     */
    public function run(): self
    {
        $exitException    = false;
        $exitExceptingMsg = '';
        $this->startTime  = microtime(true);

        $sch = new Coroutine\Scheduler;
        $sch->parallel($this->nConcurrency, function () use (&$exitException, &$exitExceptingMsg) {
            try {
                $this->{$this->testMethod}();
            } catch (RuntimeException $e) {
                $exitException    = true;
                $exitExceptingMsg = $e->getMessage();
            }
        });
        $sch->start();

        $this->beginSendTime = microtime(true);
        $this->connectTime   = $this->beginSendTime - $this->startTime;
        if ($exitException) {
            exit($exitExceptingMsg . PHP_EOL);
        }

        $this->finish();
        return $this;
    }
}
