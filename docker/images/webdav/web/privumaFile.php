<?php
use Sabre\DAV;

class PrivumaFile extends DAV\File
{

    private $path;

    public function encode(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return implode(DIRECTORY_SEPARATOR, array_map(function ($part) use ($ext) {
            return implode('*', array_map(function ($p) use ($ext) {
                if(strpos($p, '.') !== 0) {
                    return base64_encode(basename($p, '.' . $ext));
                }
                return '';
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);
    }

    public function decode(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return implode(DIRECTORY_SEPARATOR, array_map(function ($part) use ($ext) {
            return implode('*', array_map(function ($p) use ($ext) {
                if(strpos($p, '.') !== 0) {
                    return base64_decode(basename($p, '.' . $ext));
                }
                return '';
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);
    }

    public function __construct($path)
    {

        $this->path = $path;

    }

    public function getName()
    {

        return $this->decode(basename($this->path));

    }

    public function get()
    {

        return fopen($this->path, 'r');

    }

    public function getSize()
    {

        return filesize($this->path);

    }

    public function getETag()
    {

        return '"' . md5_file($this->path) . '"';

    }

}
