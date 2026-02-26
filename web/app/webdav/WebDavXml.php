<?php
namespace privuma\webdav;

class WebDavXml
{
    public static function multiStatus(array $responses): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:multistatus xmlns:D="DAV:">' . "\n";
        foreach ($responses as $response) {
            $xml .= $response;
        }
        $xml .= '</D:multistatus>';
        return $xml;
    }

    public static function directoryResponse(string $href, string $name, ?int $mtime = null): string
    {
        $href = self::encodePath($href);
        $xml = '<D:response>' . "\n";
        $xml .= '  <D:href>' . htmlspecialchars($href, ENT_XML1) . '</D:href>' . "\n";
        $xml .= '  <D:propstat>' . "\n";
        $xml .= '    <D:prop>' . "\n";
        $xml .= '      <D:displayname>' . htmlspecialchars($name, ENT_XML1) . '</D:displayname>' . "\n";
        $xml .= '      <D:resourcetype><D:collection/></D:resourcetype>' . "\n";
        if ($mtime !== null) {
            $xml .= '      <D:getlastmodified>' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT</D:getlastmodified>' . "\n";
        }
        $xml .= '    </D:prop>' . "\n";
        $xml .= '    <D:status>HTTP/1.1 200 OK</D:status>' . "\n";
        $xml .= '  </D:propstat>' . "\n";
        $xml .= '</D:response>' . "\n";
        return $xml;
    }

    public static function fileResponse(string $href, string $name, ?int $mtime, ?string $contentType, ?string $etag, ?int $size = null): string
    {
        $href = self::encodePath($href);
        $xml = '<D:response>' . "\n";
        $xml .= '  <D:href>' . htmlspecialchars($href, ENT_XML1) . '</D:href>' . "\n";
        $xml .= '  <D:propstat>' . "\n";
        $xml .= '    <D:prop>' . "\n";
        $xml .= '      <D:displayname>' . htmlspecialchars($name, ENT_XML1) . '</D:displayname>' . "\n";
        $xml .= '      <D:resourcetype/>' . "\n";
        if ($mtime !== null) {
            $xml .= '      <D:getlastmodified>' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT</D:getlastmodified>' . "\n";
        }
        if ($contentType !== null) {
            $xml .= '      <D:getcontenttype>' . htmlspecialchars($contentType, ENT_XML1) . '</D:getcontenttype>' . "\n";
        }
        if ($size !== null) {
            $xml .= '      <D:getcontentlength>' . $size . '</D:getcontentlength>' . "\n";
        }
        if ($etag !== null) {
            $xml .= '      <D:getetag>"' . htmlspecialchars($etag, ENT_XML1) . '"</D:getetag>' . "\n";
        }
        $xml .= '    </D:prop>' . "\n";
        $xml .= '    <D:status>HTTP/1.1 200 OK</D:status>' . "\n";
        $xml .= '  </D:propstat>' . "\n";
        $xml .= '</D:response>' . "\n";
        return $xml;
    }

    private static function encodePath(string $path): string
    {
        $parts = explode('/', $path);
        $encoded = array_map(function ($part) {
            return rawurlencode($part);
        }, $parts);
        return implode('/', $encoded);
    }
}
