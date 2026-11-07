<?php
/**
 * Minimal pure-PHP ZIP reader/writer.
 *
 * XAMPP installations often ship with ext-zip disabled, so the application
 * reads and writes the XLSX (ZIP) packages itself using zlib only.
 */

class ZipReader {
    private $data;
    private $entries = [];

    public function __construct($path) {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $this->data = $data;

        $eocdPos = strrpos($data, "PK\x05\x06");
        if ($eocdPos === false) {
            throw new RuntimeException('This does not look like a valid .xlsx file (ZIP structure not found).');
        }

        $eocd = unpack('Vsig/vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset/vcommentlen', substr($data, $eocdPos, 22));
        $entries = (int)$eocd['entries'];
        $pos = (int)$eocd['cdoffset'];
        $end = $pos + (int)$eocd['cdsize'];

        while ($pos < $end && substr($data, $pos, 4) === "PK\x01\x02") {
            $header = unpack(
                'Vsig/vmadeby/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Voffset',
                substr($data, $pos, 46)
            );
            $name = substr($data, $pos + 46, $header['namelen']);
            $this->entries[$name] = [
                'method'   => (int)$header['method'],
                'comp'     => (int)$header['comp'],
                'uncomp'   => (int)$header['uncomp'],
                'crc'      => (int)$header['crc'],
                'offset'   => (int)$header['offset'],
            ];
            $pos += 46 + $header['namelen'] + $header['extralen'] + $header['commentlen'];
        }

        if (count($this->entries) === 0) {
            throw new RuntimeException('The .xlsx file contains no readable entries.');
        }
    }

    public function names() {
        return array_keys($this->entries);
    }

    public function has($name) {
        return isset($this->entries[$name]);
    }

    public function get($name) {
        if (!isset($this->entries[$name])) {
            return null;
        }
        $entry = $this->entries[$name];
        $pos = $entry['offset'];
        if (substr($this->data, $pos, 4) !== "PK\x03\x04") {
            throw new RuntimeException('Corrupt ZIP entry: ' . $name);
        }
        $local = unpack('Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen', substr($this->data, $pos, 30));
        $dataStart = $pos + 30 + $local['namelen'] + $local['extralen'];
        $payload = substr($this->data, $dataStart, $entry['comp']);

        if ($entry['method'] === 0) {
            $out = $payload;
        } elseif ($entry['method'] === 8) {
            $out = @gzinflate($payload);
            if ($out === false) {
                throw new RuntimeException('Could not decompress ZIP entry: ' . $name);
            }
        } else {
            throw new RuntimeException('Unsupported ZIP compression method for entry: ' . $name);
        }

        return $out;
    }
}

class ZipWriter {
    private $entries = [];

    public function add($name, $data) {
        $this->entries[] = ['name' => (string)$name, 'data' => (string)$data];
    }

    public function build() {
        if (count($this->entries) === 0) {
            throw new RuntimeException('Cannot build an empty ZIP archive.');
        }

        $d = getdate();
        $dosTime = (($d['hours'] & 0x1F) << 11) | (($d['minutes'] & 0x3F) << 5) | ((int)($d['seconds'] / 2) & 0x1F);
        $dosDate = ((max(1980, $d['year']) - 1980) << 9) | (($d['mon'] & 0x0F) << 5) | ($d['mday'] & 0x1F);

        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $crc = crc32($data);
            $deflated = @gzdeflate($data, 6);

            if ($deflated !== false && strlen($deflated) < strlen($data)) {
                $method = 8;
                $payload = $deflated;
            } else {
                $method = 0;
                $payload = $data;
            }

            $compSize = strlen($payload);
            $uncompSize = strlen($data);
            $nameLen = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0x0800,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $compSize,
                $uncompSize,
                $nameLen,
                0
            );

            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0x0800,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $compSize,
                $uncompSize,
                $nameLen,
                0,
                0,
                0,
                0,
                0,
                $offset
            );

            $local .= $localHeader . $name . $payload;
            $central .= $centralHeader . $name;
            $offset += strlen($localHeader) + $nameLen + $compSize;
            $count++;
        }

        $cdOffset = strlen($local);
        $cdSize = strlen($central);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cdSize, $cdOffset, 0);

        return $local . $central . $eocd;
    }
}
