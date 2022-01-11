<?php

// Queue Manager class (essentially a queue) that uses a simple text file for storage
class QueueManager {
	private $filename;
	
	public function __construct(string $queueName = "queue") {
        $this->filename = $queueName.".txt";
	}
	
	// Add a Queue to the queue and if we are at our limit, drop one off the end.
	public function enqueue(string $rawMessage) {
		if (!empty($rawMessage)) {
            $queueFile = fopen($this->filename, '+a');

            // here it may add some spaces so the message length is multiples of modular.
            // that make it easier to read messages from a file.

            $rawMessage = str_repeat(' ', 64 - (strlen($rawMessage) % 64)).$rawMessage;

            if (flock($queueFile, LOCK_EX)) {
                fwrite($queueFile, $rawMessage);
                flock($queueFile, LOCK_UN); // unlock the file
            } else {
                // flock() returned false, no lock obtained
                print "Could not lock $this->filename! for enqueue\n";
            }
		}
	}
	
	// Remove a Queue item from the end of our list
	public function dequeue(): ?string {
		
        $file = fopen($this->filename, '+c');

        // lock file
        if (flock($file, LOCK_EX)) {
            $frame = $this->readFrame($file, 1);
            ftruncate($file, fstat($file)['size'] - strlen($frame));
            rewind($file);
            $rawMessage = substr(trim($frame), 1);
            flock($file, LOCK_UN); // unlock the file
        } else {
            // flock() returned false, no lock obtained
            print "Could not lock $this->filename! for dequeue\n";
            return false;
        }

        return $rawMessage;
	}

    private function readFrame($file, $frameNumber) {
        $frameSize = 64;
        $offset = $frameNumber * $frameSize;
        fseek($file, -$offset, SEEK_END);
        $frame = fread($file, $frameSize);
        if ('' == $frame) {
            return '';
        }
        if (false !== strpos($frame, '|{')) {
            return $frame;
        }
        return $this->readFrame($file, $frameNumber + 1).$frame;
    }
}
?>