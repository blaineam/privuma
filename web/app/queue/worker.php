<?php
namespace privuma\queue;

use ReflectionClass;

class worker
{
    const BATCH_SIZE = 30000;
    public function __construct(string $name = 'queue', string $search = null)
    {
        $queue = new QueueManager($name);
        for ($index = 0; $index < self::BATCH_SIZE; $index++) {
            if (is_null($search)) {
                $raw = $queue->dequeue();
            } else {
                $raw = $queue->dequeueMatching($search);
            }
            if (is_null($raw) || empty($raw)) {
                echo PHP_EOL . 'No Messages in Queue';
                break;
            }
            $msg = json_decode($raw, true);

            if (isset($msg['type']) && class_exists('privuma\\actions\\' . $msg['type'])) {
                echo PHP_EOL . 'Processing Msg action: ' . $msg['type'];
                $ref = new ReflectionClass('privuma\\actions\\' . $msg['type']);
                $ref->newInstanceArgs(array($msg['data']));
            }
        }

    }
}
