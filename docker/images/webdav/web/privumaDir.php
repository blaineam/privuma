<?php
require(__DIR__.'/privumaFile.php');
use Sabre\DAV;


class PrivumaDir extends DAV\Collection {

  private $path;

  function __construct($path) {

    $this->path = $path;

  }

  function getChildren() {

    $children = array();
    // Loop through the directory, and create objects for each node
    foreach(scandir($this->path) as $node) {

      // Ignoring files staring with .
      if ($node[0]==='.') continue;
      $children[] = $this->getChild($node);

    }

    return $children;

  }

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



  function getChild($name) {

      $path = $this->path . '/' . $name;
      $encodedPath = $this->path . '/' . $this->encode($name); 

      // We have to throw a NotFound exception if the file didn't exist
      if (!file_exists($path)) {
	if (!file_exists($encodedPath)) {
	  throw new DAV\Exception\NotFound('The file with name: ' . $name . ' could not be found');
	}
	$path = $encodedPath;
      }

      // Some added security
      if ($name[0]=='.')  throw new DAV\Exception\NotFound('Access denied');

      if (is_dir($path)) {

          return new PrivumaDir($path);

      } else {

          return new PrivumaFile($path);

      }

  }

  function childExists($name) {

        return file_exists($this->path . '/' . $name);

  }

  function getName() {

      return base64_decode(basename($this->path));

  }

}
