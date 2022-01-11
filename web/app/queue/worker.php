<?php
namespace privuma\queue;

use privuma\queue\QueueManager;
use ReflectionClass;

class worker {
    const BATCH_SIZE = 200;
    function __construct(string $name = 'queue') {
        $queue = new QueueManager($name);
        for($index = 0; $index < self::BATCH_SIZE; $index++) {
            $raw = $queue->dequeue();
            if(is_null($raw)) {
                break;
            }
            $msg = json_decode($raw, true);
            if(isset($msg['type']) && class_exists($msg['type'])) {
                $ref = new ReflectionClass($msg['type']);
                $ref->newInstanceArgs(array($msg['data']));
            }
        }

    }
}