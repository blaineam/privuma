<?php

namespace privuma\queue;

// Queue Manager class ( essentially a queue ) that uses a simple text file for storage

class QueueManager
{
    private $filename;

    private string $delimiter;

    public function __construct(string $queueName = 'queue')
    {
        $this->delimiter = PHP_EOL . '|||';
        $this->filename = __DIR__ . DIRECTORY_SEPARATOR . $queueName . '.txt';
    }

    public function enqueue(string $rawMessage)
    {
        if (!empty($rawMessage)) {
            $handle = @fopen($this->filename, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    $buffer = fgets($handle);
                    if (strpos($buffer, $rawMessage) !== false) {
                        echo PHP_EOL . 'Message already in queue, skipping enqueue';
                        return;
                    }
                }
                fclose($handle);
            }
            $queueFile = fopen($this->filename, 'r+');
            $rawMessage = $this->delimiter . $rawMessage . str_repeat(' ', 64 - ((strlen($rawMessage) + strlen($this->delimiter)) % 64));
            if (flock($queueFile, LOCK_EX)) {
                $old_text = file_get_contents($this->filename);
                fwrite($queueFile, $rawMessage . $old_text);
                flock($queueFile, LOCK_UN);
            } else {
                print "Could not lock $this->filename! for enqueue\n";
            }
        }
    }

    public function dequeue(): ?string
    {
        $file = fopen($this->filename, 'c+');
        if (flock($file, LOCK_EX)) {
            $frame = $this->readFrame($file, 1);
            $length = fstat($file)[ 'size' ] - strlen($frame) - strlen($this->delimiter);
            if ($length > 0) {
                ftruncate($file, $length);
                rewind($file);
            } else {
                file_put_contents($this->filename, '');
            }
            $rawMessage = trim($frame);
            flock($file, LOCK_UN);
        } else {
            print "Could not lock $this->filename! for dequeue\n";
            return false;
        }
        return $rawMessage;
    }

    private function readFrame($file, $frameNumber)
    {
        $frameSize = 64;
        $offset = $frameNumber * $frameSize;
        fseek($file, -$offset, SEEK_END);
        $frame = fread($file, $frameSize);
        if ('' == $frame) {
            return '';
        }
        $position = strpos($frame, $this->delimiter);
        $sub = substr($frame, $position + strlen($this->delimiter));
        if (false !== $position && !empty($sub)) {
            return $sub;
        }
        return $this->readFrame($file, $frameNumber + 1) . $frame;
    }
}
