<?php
namespace privuma\queue;

use privuma\queue\QueueManager;
use ReflectionClass;

class worker {
    const BATCH_SIZE = 2000;
    function __construct(string $name = 'queue') {
        $queue = new QueueManager($name);
        for($index = 0; $index < self::BATCH_SIZE; $index++) {
            $raw = $queue->dequeue();
            if(is_null($raw) || empty($raw)) {
                echo "No Messages in Queue";
                break;
            }
            $msg = json_decode($raw, true);
            
            if(isset($msg['type']) && class_exists("privuma\\actions\\" . $msg['type'])) {
                echo PHP_EOL."Processing Msg action: " . $msg['type'];
                $ref = new ReflectionClass("privuma\\actions\\" . $msg['type']);
                $ref->newInstanceArgs(array($msg['data']));
            }
        }

    }
}