<?php

use Swoft\TestLib\SwooleBench;

require dirname(__DIR__) . '/src/SwooleBench.php';

$swooleVersion = SWOOLE_VERSION;

echo <<<EOF
============================================================
Swoole Version          {$swooleVersion}
============================================================
\n
EOF;

$bench = new SwooleBench([]);

if (!$bench->initFromCli()) {
    if ($error = $bench->getError()) {
        $bench->println('Prepare Error:', $error);
    }

    exit(0);
}

$bench->run();

if ($error = $bench->getError()) {
    $bench->println('Run Error:', $error);
}
