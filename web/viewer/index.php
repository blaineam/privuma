<?php
ini_set("memory_limit", "2G");
// error_reporting(E_ALL);
// ini_set("display_errors", "on");
session_start();

use privuma\privuma;
use privuma\helpers\tokenizer;

require_once __DIR__ .
  DIRECTORY_SEPARATOR .
  ".." .
  DIRECTORY_SEPARATOR .
  "app" .
  DIRECTORY_SEPARATOR .
  "privuma.php";

$privuma = privuma::getInstance();
$tokenizer = new tokenizer();

$conn = $privuma->getPDO();

function sanitizeLine($line)
{
  return trim(preg_replace("/[^A-Za-z0-9 ]/", "", $line), "\r\n");
}

function trimExtraNewLines($string)
{
  return trim(
    implode(
      PHP_EOL,
      array_map(function ($line) {
        return sanitizeLine($line);
      }, explode(PHP_EOL, $string))
    ),
    "\r\n"
  );
}

function parseMetaData($item)
{
  return [
    "title" => explode(PHP_EOL, explode("Title: ", $item)[1] ?? "")[0],
    "author" => explode(PHP_EOL, explode("Author: ", $item)[1] ?? "")[0],
    "date" => new DateTime(
      explode(PHP_EOL, explode("Date: ", $item)[1] ?? "")[0]
    ),
    "rating" => (int) explode(PHP_EOL, explode("Rating: ", $item)[1] ?? "")[0],
    "favorites" => (int) explode(
      PHP_EOL,
      explode("Favorites: ", $item)[1] ?? ""
    )[0],
    "description" => explode(
      "Tags:",
      explode("Description: ", $item)[1] ?? ""
    )[0],
    "tags" =>
      explode(", ", explode(PHP_EOL, explode("Tags: ", $item)[1] ?? "")[0]) ??
      [],
    "comments" => explode("Comments: ", $item)[1] ?? "",
  ];
}

function condenseMetaData($item)
{
  return str_replace(
    PHP_EOL,
    '\n',
    mb_convert_encoding(
      str_replace(
        PHP_EOL . PHP_EOL,
        PHP_EOL,
        implode(PHP_EOL, [
          sanitizeLine(
            $item["title"] ?:
            substr(trimExtraNewLines($item["description"]), 0, 256)
          ),
          sanitizeLine($item["favorites"]),
          sanitizeLine(implode(", ", array_slice($item["tags"], 0, 20))),
          //substr(trimExtraNewLines($item['comments']), 0, 256),
        ])
      ),
      "UTF-8",
      "UTF-8"
    )
  );
}

function getDB($mobile = false, $unfiltered = false, $nocache = false)
{
  global $conn;

  $cachePath =
    __DIR__ .
    DIRECTORY_SEPARATOR .
    ".." .
    DIRECTORY_SEPARATOR .
    "app" .
    DIRECTORY_SEPARATOR .
    "output" .
    DIRECTORY_SEPARATOR .
    "cache" .
    DIRECTORY_SEPARATOR .
    "viewer_db_" .
    ($mobile ? "mobile_" : "") .
    ($unfiltered ? "unfiltered_" : "") .
    ".json";

  $currentTime = time();
  $lastRan = filemtime($cachePath) ?? $currentTime - 24 * 60 * 60;

  if (
    $currentTime - $lastRan > 24 * 60 * 60 ||
    $nocache ||
    !file_exists($cachePath)
  ) {
    $blocked = "(album = 'Favorites' or blocked = 0) and";
    if ($unfiltered) {
      $blocked = "";
    }
    $stmt = $conn->prepare(
      "SELECT filename, album, dupe, time, hash, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE $blocked hash is not null and hash != '' and hash != 'compressed') t1 ORDER BY time desc;"
    );
    $stmt->execute();
    $data = str_replace(
      "`",
      "",
      json_encode($stmt->fetchAll(PDO::FETCH_ASSOC))
    );

    if ($mobile) {
      $data = json_encode(
        mb_convert_encoding(
          array_map(function ($item) {
            $item["metadata"] = is_null($item["metadata"])
              ? ""
              : condenseMetaData(parseMetaData($item["metadata"]));
            return $item;
          }, json_decode($data, true)),
          "UTF-8",
          "UTF-8"
        ),
        JSON_THROW_ON_ERROR
      );
    }

    file_put_contents($cachePath, $data);
  }

  return [
    "data" => file_get_contents($cachePath),
    "size" => filesize($cachePath),
  ];
}

if (isset($_GET["RapiServe"])) {
  header("Content-Type: text/javascript");
  echo 'canrapiserve = "index.php?path=";';
  die();
}

if (
  isset($_POST["key"]) &&
  base64_decode($_POST["key"]) == privuma::getEnv("DOWNLOAD_PASSWORD")
) {
  $_SESSION["viewer-authenticated-successfully"] = true;
}

if (
  !isset($_SESSION["viewer-authenticated-successfully"]) &&
  isset($_GET["path"])
) {
  http_response_code(400);
  die("Malformed request");
}

if (isset($_GET["path"])) {
  if (strstr($_GET["path"], ".js")) {
    if (isset($_SERVER["HTTP_RANGE"])) {
      header("Content-Type: text/javascript");
      echo "          ";
      die();
    }
    $originalFilename = base64_decode(basename($_GET["path"], ".js"));
    if (strstr($originalFilename, "_mobile_")) {
      header("Content-Type: text/javascript");
      $dataset = getDB(
        true,
        isset($_GET["unfiltered"]),
        isset($_GET["nocache"])
      );
      //header("Content-Length: " . $dataset['size']);
      echo "const encrypted_data = `" . $dataset["data"] . "`;";
      die();
    }

    header("Content-Type: text/javascript");
    $dataset = getDB(
      false,
      isset($_GET["unfiltered"]),
      isset($_GET["nocache"])
    );
    //header("Content-Length: " . $dataset['size']);
    echo "const encrypted_data = " . $dataset["data"] . ";";
    die();
  }

  $ext = pathinfo($_GET["path"], PATHINFO_EXTENSION);
  $hash = base64_decode(basename($_GET["path"], "." . $ext));
  $uri =
    "/?token=" .
    $tokenizer->rollingTokens(privuma::getEnv("AUTHTOKEN"))[1] .
    "&media=" .
    urlencode("h-$hash.$ext");
  header("Location: $uri");
}

echo file_get_contents("index.html");
die();
