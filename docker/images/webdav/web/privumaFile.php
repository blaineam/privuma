<?php
use Sabre\DAV;

class PrivumaFile extends DAV\File {

  private $path;


    public function encode(string $path) : string {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return implode(DIRECTORY_SEPARATOR, array_map(function($part) use ($ext) {
            return implode('*', array_map(function($p) use ($ext) {
                if(strpos($p, '.') !== 0){
                    return base64_encode(basename($p, '.' . $ext));
                }
                return "";
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);
    }

    public function decode(string $path) : string {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return implode(DIRECTORY_SEPARATOR, array_map(function($part) use ($ext) {
            return implode('*', array_map(function($p) use ($ext) {
                if(strpos($p, '.') !== 0){
                    return base64_decode(basename($p, '.' . $ext));
                }
                return "";
            }, explode('*',$part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);
    }


  function __construct($path) {

    $this->path = $path;

  }

  function getName() {

    return $this->decode(basename($this->path));

  }

  function get() {

    return fopen($this->path,'r');

  }

  function getSize() {

    return filesize($this->path);

  }

  function getETag() {

    return '"' . md5_file($this->path) . '"';

  }

}
