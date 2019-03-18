<?php

namespace crazydope\http;

class ZipFile
{
    /**
     * @var bool|resource
     */
    private $file;
    /**
     * @var string
     */
    private $name;
    /**
     * @var string
     */
    private $displayName;
    /**
     * @var string
     */
    private $boundary;
    /**
     * @var int
     */
    private $delay;
    /**
     * @var int
     */
    private $size;

    public function __construct( string $filePath, string $displayName = '', int $delay = 0 )
    {
        $this->name = basename($filePath);

        if ( !$this->isValidFile($filePath) ) {
            $this->invalidRequest();
        }

        $this->size = filesize($filePath);
        $this->file = fopen($filePath, 'rb');

        if ( $this->file === false ) {
            $this->invalidRequest();
        }

        $this->boundary = md5($filePath);
        $this->delay = $delay;
        $this->displayName = $displayName ?: '';
    }

    public function process(): void
    {
        $ranges = null;
        $t = 0;

        if ( $_SERVER['REQUEST_METHOD'] === 'GET'
            && isset($_SERVER['HTTP_RANGE'])
            && $range = stristr(trim($_SERVER['HTTP_RANGE']), 'bytes=') ) {

            $range = substr($range, 6);
            $ranges = explode(',', $range);
            $t = count($ranges);
        }

        $fileName = $this->displayName ?: $this->name;
        header('Accept-Ranges: bytes');
        header('Content-Type: application/octet-stream');
        header('Content-Transfer-Encoding: binary');
        header(sprintf('Content-Disposition: attachment; filename="%s"', $fileName));
        if ( $t > 0 ) {
            header('HTTP/1.1 206 Partial content');
            $t === 1 ? $this->pushSingle($range) : $this->pushMulti($ranges);
        } else {
            header('Content-Length: ' . $this->size);
            $this->readFile();
        }
        flush();
    }

    private function isValidFile(string $filePath): bool
    {
        if ( !is_file($filePath) ) {
            return false;
        }

        $zip = new \ZipArchive();
        $res = $zip->open($filePath, \ZipArchive::CHECKCONS);

        if ( $res !== true ) {
            return false;
        }

        if ( fopen($filePath, 'rb') === false ) {
            return false;
        }

        return true;
    }

    private function invalidRequest(): void
    {
        header('HTTP/1.1 400 Invalid Request');
        exit('<h3>File Not Found</h3>');
    }

    private function pushSingle( $range ): void
    {
        $start = $end = 0;
        $this->getRange($range, $start, $end);
        header('Content-Length: ' . ( $end - $start + 1 ));
        header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $this->size));
        fseek($this->file, $start);
        $this->readBuffer($end - $start + 1);
        $this->readFile();
    }

    private function pushMulti( $ranges ): void
    {
        $length = $start = $end = 0;
        $tl = "Content-type: application/octet-stream\r\n";
        $formatRange = "Content-range: bytes %d-%d/%d\r\n\r\n";
        foreach ( $ranges as $range ) {
            $this->getRange($range, $start, $end);
            $length += strlen("\r\n--$this->boundary\r\n");
            $length += strlen($tl);
            $length += strlen(sprintf($formatRange, $start, $end, $this->size));
            $length += $end - $start + 1;
        }

        $length += strlen("\r\n--$this->boundary--\r\n");
        header("Content-Length: $length");
        header("Content-Type: multipart/x-byteranges; boundary=$this->boundary");
        foreach ( $ranges as $range ) {
            $this->getRange($range, $start, $end);
            echo "\r\n--$this->boundary\r\n";
            echo $tl;
            echo sprintf($formatRange, $start, $end, $this->size);
            fseek($this->file, $start);
            $this->readBuffer($end - $start + 1);
        }
        echo "\r\n--$this->boundary--\r\n";
    }

    private function getRange( $range, &$start, &$end ): array
    {
        [$start, $end] = explode('-', $range);
        $fileSize = $this->size;
        if ( $start === '' ) {
            $tmp = $end;
            $end = $fileSize - 1;
            $start = $fileSize - $tmp;
            if ( $start < 0 ) {
                $start = 0;
            }
        } else {
            if ( $end === '' || $end > $fileSize - 1 ) {
                $end = $fileSize - 1;
            }
        }
        if ( $start > $end ) {
            header('Status: 416 Requested range not satisfiable');
            header('Content-Range: */' . $fileSize);
            exit();
        }
        return [
            $start,
            $end
        ];
    }

    private function readFile(): void
    {
        while ( !feof($this->file) ) {
            echo fgets($this->file);
            flush();
            usleep($this->delay);
        }
    }

    private function readBuffer( $bytes, $size = 1024 ): void
    {
        $bytesLeft = $bytes;
        while ( $bytesLeft > 0 && !feof($this->file) ) {
            $bytesLeft > $size ? $bytesRead = $size : $bytesRead = $bytesLeft;
            $bytesLeft -= $bytesRead;
            echo fread($this->file, $bytesRead);
            flush();
            usleep($this->delay);
        }
    }
}