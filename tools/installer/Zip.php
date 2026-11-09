<?php
// Copied from jumphp2installer (installer/src/Zip.php, MIT, https://git.jumphp.net/jumphp/jumphp2installer): keep it the same.

namespace JumPHP2Installer;

/**
 * Rewrites a .zip with some of its files changed, in plain PHP (zlib only: the installer's own PHP has no zip
 * extension). Every entry keeps its name, date, attributes (the Unix modes of a macOS app) and order; the changed
 * ones are deflated again. No ZIP64 (archives under 4 GB), no encryption.
 */
final class Zip
{
    /**
     * @param callable(string $name, string $bytes): ?string $change new bytes for an entry, or null to keep it
     * @return int how many entries changed
     */
    public static function rewrite(string $zip, string $out, callable $change): int
    {
        $data = @file_get_contents(Binary::readable($zip));
        if ($data === false) {
            throw new \RuntimeException("cannot read $zip");
        }
        $end = strrpos($data, "PK\x05\x06");
        if ($end === false || strlen($data) - $end < 22) {
            throw new \RuntimeException("$zip: not a zip");
        }
        $eocd = unpack('vdisk/vcdDisk/ventriesHere/ventries/Vsize/Voffset/vcomment', substr($data, $end + 4, 18));
        if ($eocd['offset'] === 0xFFFFFFFF || $eocd['entries'] === 0xFFFF) {
            throw new \RuntimeException("$zip: ZIP64 archives are not supported");
        }

        $locals = '';
        $central = '';
        $changed = 0;
        $at = $eocd['offset'];
        for ($i = 0; $i < $eocd['entries']; $i++) {
            if (substr($data, $at, 4) !== "PK\x01\x02") {
                throw new \RuntimeException("$zip: broken central directory");
            }
            $c = unpack('vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Vlocal', substr($data, $at + 4, 42));
            $name = substr($data, $at + 46, $c['nameLen']);
            $cExtra = substr($data, $at + 46 + $c['nameLen'], $c['extraLen']);
            $cComment = substr($data, $at + 46 + $c['nameLen'] + $c['extraLen'], $c['commentLen']);
            $at += 46 + $c['nameLen'] + $c['extraLen'] + $c['commentLen'];

            $l = unpack('vnameLen/vextraLen', substr($data, $c['local'] + 26, 4));
            $lExtra = substr($data, $c['local'] + 30 + $l['nameLen'], $l['extraLen']);
            $raw = substr($data, $c['local'] + 30 + $l['nameLen'] + $l['extraLen'], $c['csize']);
            [$method, $crc, $csize, $usize] = [$c['method'], $c['crc'], $c['csize'], $c['usize']];

            if (!str_ends_with($name, '/') && in_array($method, [0, 8], true)) {
                $bytes = $method === 8 ? @gzinflate($raw) : $raw;
                if ($bytes === false) {
                    throw new \RuntimeException("$zip: cannot unpack $name");
                }
                $new = $change($name, $bytes);
                if ($new !== null) {
                    $raw = (string)gzdeflate($new, 6);
                    [$method, $crc, $csize, $usize] = [8, (int)hexdec(hash('crc32b', $new)), strlen($raw), strlen($new)];
                    $changed++;
                }
            }
            $flags = $c['flags'] & ~0x0008;          // the sizes are in the headers: no data descriptor
            $offset = strlen($locals);
            $locals .= "PK\x03\x04" . pack('vvvvvVVVvv', $c['needed'], $flags, $method, $c['time'], $c['date'], $crc, $csize, $usize, strlen($name), strlen($lExtra))
                . $name . $lExtra . $raw;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', $c['madeBy'], $c['needed'], $flags, $method, $c['time'], $c['date'], $crc, $csize, $usize,
                strlen($name), strlen($cExtra), strlen($cComment), 0, $c['internal'], $c['external'], $offset) . $name . $cExtra . $cComment;
        }
        $comment = substr($data, $end + 22, $eocd['comment']);
        $result = $locals . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $eocd['entries'], $eocd['entries'], strlen($central), strlen($locals), strlen($comment)) . $comment;
        if (@file_put_contents($out, $result) === false) {
            throw new \RuntimeException("cannot write $out");
        }
        return $changed;
    }
}
