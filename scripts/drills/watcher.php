<?php

// watcher.php — usage: php watcher.php <worker_pid> <queue_key>
// Tight phpredis loop (~50us per LLEN). redis-cli polling is too coarse: spawning a
// process costs more than this job's entire runtime.
$r = new Redis;
$r->connect('127.0.0.1', 6379);
$r->select(0);

$key = $argv[2];
$pid = (int) $argv[1];

$t0 = microtime(true);
while ($r->lLen($key) === 0) {
    if (microtime(true) - $t0 > 60) {
        echo "timeout waiting for enqueue\n";
        exit(1);
    }
}
$enqueued = microtime(true);
while ($r->lLen($key) > 0) {
    if (microtime(true) - $t0 > 60) {
        echo "timeout waiting for reserve\n";
        exit(1);
    }
}
$reserved = microtime(true);
posix_kill($pid, SIGKILL);
$killed = microtime(true);

printf("enqueue seen at t+%.1fms; reserved at t+%.1fms; SIGKILL sent %.3fms after reservation\n",
    ($enqueued - $t0) * 1000, ($reserved - $t0) * 1000, ($killed - $reserved) * 1000);
