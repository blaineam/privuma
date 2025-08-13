<?php

namespace privuma\queue;

// Queue Manager class (essentially a queue) that uses a simple text file for storage

class QueueManager
{
    private $filename;

    private string $delimiter;

    public function __construct(string $queueName = 'queue')
    {
        $this->delimiter = PHP_EOL . '|||';
        $this->filename = __DIR__ . DIRECTORY_SEPARATOR . $queueName . '.txt';
    }

    public function enqueue(string $rawMessage): bool
    {
        if (empty($rawMessage)) {
            return false;
        }

        $file = @fopen($this->filename, 'c+');
        if (!$file) {
            return false;
        }

        // Try to acquire lock with retries to avoid indefinite wait
        $maxRetries = 50; // e.g., 50 retries * 100ms = 5 second timeout
        $retryDelayUs = 100000; // 100ms
        $locked = false;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if (flock($file, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep($retryDelayUs);
        }

        if (!$locked) {
            print "Could not lock $this->filename! for enqueue after retries\n";
            fclose($file);
            return false;
        }

        try {
            $filesize = fstat($file)['size'];

            // Check for duplicate (containment, fixed to parse properly)
            $duplicate = false;
            $pos = 0;
            while ($pos < $filesize) {
                fseek($file, $pos);
                $del_check = fread($file, strlen($this->delimiter));
                if ($del_check !== $this->delimiter) {
                    break; // Corrupted or invalid
                }
                $start_msg = $pos + strlen($this->delimiter);
                $next_pos = $this->findNextDelimiter($file, $start_msg);
                $end_entry = $next_pos !== false ? $next_pos : $filesize;
                $len_msg = $end_entry - $start_msg;
                fseek($file, $start_msg);
                $msg = fread($file, $len_msg);
                $msg = rtrim($msg, ' ');
                if (strpos($msg, $rawMessage) !== false) {
                    $duplicate = true;
                    break;
                }
                if ($next_pos === false) {
                    break;
                }
                $pos = $next_pos;
            }

            if ($duplicate) {
                echo PHP_EOL . 'Message already in queue, skipping enqueue';
                return false;
            }

            // Prepare entry with padding
            $entry_len_mod = (strlen($this->delimiter) + strlen($rawMessage)) % 64;
            $pad_len = $entry_len_mod > 0 ? 64 - $entry_len_mod : 0;
            $entry = $this->delimiter . $rawMessage . str_repeat(' ', $pad_len);
            $len_entry = strlen($entry);

            // Shift existing content to make space at the beginning (memory efficient)
            $old_size = $filesize;
            if ($old_size > 0) {
                ftruncate($file, $old_size + $len_entry);
                $chunk_size = 1048576; // 1MB for better performance on large files
                $old_pos = $old_size;
                while ($old_pos > 0) {
                    $this_chunk = min($chunk_size, $old_pos);
                    fseek($file, $old_pos - $this_chunk);
                    $chunk = fread($file, $this_chunk);
                    fseek($file, $old_pos - $this_chunk + $len_entry);
                    fwrite($file, $chunk);
                    $old_pos -= $this_chunk;
                }
            }

            // Write new entry at beginning
            fseek($file, 0);
            fwrite($file, $entry);

            return true;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        return false;
    }

    public function dequeue(): string|bool|null
    {
        $file = @fopen($this->filename, 'c+');
        if (!$file) {
            return false;
        }

        // Non-blocking lock for dequeue
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            print "Could not lock $this->filename! for dequeue\n";
            fclose($file);
            return false;
        }

        try {
            $size = fstat($file)['size'];
            if ($size == 0) {
                return null;
            }

            $frame = $this->readFrame($file, 1);
            if ($frame == '') {
                return null;
            }

            $length = $size - strlen($frame) - strlen($this->delimiter);
            if ($length > 0) {
                ftruncate($file, $length);
                rewind($file);
            } else {
                ftruncate($file, 0);
            }
            $rawMessage = trim($frame);

            return $rawMessage;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public function dequeueMatching(string $match): ?string
    {
        $file = @fopen($this->filename, 'c+');
        if (!$file) {
            return null;
        }

        // Non-blocking lock for dequeueMatching
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            print "Could not lock $this->filename! for dequeueMatching\n";
            fclose($file);
            return null;
        }

        try {
            $filesize = fstat($file)['size'];
            if ($filesize == 0) {
                return null;
            }

            $pos = 0;
            $found = null;
            $found_start_entry = -1;
            $found_end_entry = -1;

            while ($pos < $filesize) {
                fseek($file, $pos);
                $del_check = fread($file, strlen($this->delimiter));
                if ($del_check !== $this->delimiter) {
                    break;
                }
                $start_msg = $pos + strlen($this->delimiter);
                $next_pos = $this->findNextDelimiter($file, $start_msg);
                $end_entry = $next_pos !== false ? $next_pos : $filesize;
                $len_msg = $end_entry - $start_msg;
                fseek($file, $start_msg);
                $msg = fread($file, $len_msg);
                $msg_trim = rtrim($msg, ' ');
                if (strpos($msg_trim, $match) !== false) {
                    $found = $msg_trim;
                    $found_start_entry = $pos;
                    $found_end_entry = $end_entry;
                    break; // Remove first match found (newest first)
                }
                if ($next_pos === false) {
                    break;
                }
                $pos = $next_pos;
            }

            if ($found !== null) {
                $len_entry = $found_end_entry - $found_start_entry;
                $write_pos = $found_start_entry;
                $read_pos = $found_end_entry;
                $chunk_size = 1048576; // 1MB for better performance
                while ($read_pos < $filesize) {
                    fseek($file, $read_pos);
                    $chunk = fread($file, $chunk_size);
                    $len_chunk = strlen($chunk);
                    if ($len_chunk == 0) {
                        break;
                    }
                    fseek($file, $write_pos);
                    fwrite($file, $chunk);
                    $write_pos += $len_chunk;
                    $read_pos += $len_chunk;
                }
                ftruncate($file, $filesize - $len_entry);
            }

            return $found;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
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

    private function findNextDelimiter($file, int $from): int|false
    {
        $del_len = strlen($this->delimiter);
        if ($del_len == 0) {
            return false;
        }
        $chunk_size = 4096;
        $overlap = $del_len - 1;
        fseek($file, $from);
        $base_offset = $from;
        $last_base = $from - 1; // Initial to ensure first pass
        while (!feof($file)) {
            $buffer = fread($file, $chunk_size);
            $buf_len = strlen($buffer);
            if ($buf_len == 0) {
                return false;
            }
            $pos = 0;
            if (($found = strpos($buffer, $this->delimiter, $pos)) !== false) {
                return $base_offset + $found;
            }
            $new_base = $base_offset + $buf_len - $overlap;
            if ($new_base <= $last_base) {
                return false; // Prevent infinite loop if no advance
            }
            $last_base = $base_offset;
            $base_offset = $new_base;
            fseek($file, $base_offset);
        }
        return false;
    }
}
