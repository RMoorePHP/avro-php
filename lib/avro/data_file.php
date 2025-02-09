<?php
/**
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Classes handling reading and writing from and to AvroIO objects
 * @package Avro
 */

/**
 * Raised when something unkind happens with respect to AvroDataIO.
 * @package Avro
 */
class AvroDataIOException extends AvroException {}

/**
 * @package Avro
 */
class AvroDataIO
{
  /**
   * @var int used in file header
   */
  const VERSION = 1;

  /**
   * @var int count of bytes in synchronization marker
   */
  const SYNC_SIZE = 16;

  /**
   * @var int   count of items per block, arbitrarily set to 4000 * SYNC_SIZE
   * @todo make this value configurable
   */
  const SYNC_INTERVAL = 64000;

  /**
   * @var string map key for datafile metadata codec value
   */
  const METADATA_CODEC_ATTR = 'avro.codec';

  /**
   * @var string map key for datafile metadata schema value
   */
  const METADATA_SCHEMA_ATTR = 'avro.schema';
  /**
   * @var string JSON for datafile metadata schema
   */
  const METADATA_SCHEMA_JSON = '{"type":"map","values":"bytes"}';

  /**
   * @var string codec value for NULL codec
   */
  const NULL_CODEC = 'null';

  /**
   * @var string codec value for deflate codec
   */
  const DEFLATE_CODEC = 'deflate';

  /**
   * @var string codec value for snappy codec
   *   cf: http://google.github.io/snappy/
   */
  const SNAPPY_CODEC = 'snappy';

  /**
   * @var array array of valid codec names
   */
  private static $valid_codecs = array(self::NULL_CODEC, self::DEFLATE_CODEC, self::SNAPPY_CODEC);

  /**
   * @var AvroSchema cached version of metadata schema object
   */
  private static $metadata_schema;

  /**
   * @return string the initial "magic" segment of an Avro container file header.
   */
  public static function magic() { return ('Obj' . pack('c', self::VERSION)); }

  /**
   * @return int count of bytes in the initial "magic" segment of the
   *              Avro container file header
   */
  public static function magic_size() { return strlen(self::magic()); }


  /**
   * @return AvroSchema object of Avro container file metadata.
   */
  public static function metadata_schema()
  {
    if (is_null(self::$metadata_schema))
      self::$metadata_schema = AvroSchema::parse(self::METADATA_SCHEMA_JSON);
    return self::$metadata_schema;
  }

  /**
   * @param string $file_path file_path of file to open
   * @param string $mode one of AvroFile::READ_MODE or AvroFile::WRITE_MODE
   * @param string $schema_json JSON of writer's schema
   * @param string $codec compression codec
   * @return AvroDataIOWriter instance of AvroDataIOWriter
   *
   * @throws AvroDataIOException if $writers_schema is not provided
   *         or if an invalid $mode is given.
   */
  public static function open_file($file_path, $mode=AvroFile::READ_MODE,
                                   $schema_json=null, $codec=self::NULL_CODEC)
  {
    $schema = !is_null($schema_json)
      ? AvroSchema::parse($schema_json) : null;

    switch ($mode)
    {
      case AvroFile::WRITE_MODE:
        if (is_null($schema))
          throw new AvroDataIOException('Writing an Avro file requires a schema.');
        $file = new AvroFile($file_path, AvroFile::WRITE_MODE);
        $io = self::open_writer($file, $schema, $codec);
        break;
      case AvroFile::READ_MODE:
        $file = new AvroFile($file_path, AvroFile::READ_MODE);
        $io = self::open_reader($file, $schema);
        break;
      default:
        throw new AvroDataIOException(
          sprintf("Only modes '%s' and '%s' allowed. You gave '%s'.",
                  AvroFile::READ_MODE, AvroFile::WRITE_MODE, $mode));
    }
    return $io;
  }

  /**
   * @return array array of valid codecs
   */
  public static function valid_codecs()
  {
    return self::$valid_codecs;
  }

  /**
   * @param string $codec
   * @return boolean true if $codec is a valid codec value and false otherwise
   */
  public static function is_valid_codec($codec)
  {
    return in_array($codec, self::valid_codecs());
  }

  /**
   * @param AvroIO $io
   * @param AvroSchema $schema
   * @param string $codec
   * @return AvroDataIOWriter
   */
  protected static function open_writer($io, $schema, $codec=self::NULL_CODEC)
  {
    $writer = new AvroIODatumWriter($schema);
    return new AvroDataIOWriter($io, $writer, $schema, $codec);
  }

  /**
   * @param AvroIO $io
   * @param AvroSchema $schema
   * @return AvroDataIOReader
   */
  protected static function open_reader($io, $schema)
  {
    $reader = new AvroIODatumReader(null, $schema);
    return new AvroDataIOReader($io, $reader);
  }

}

/**
 *
 * Reads Avro data from an AvroIO source using an AvroSchema.
 * @package Avro
 */
class AvroDataIOReader
{
  /**
   * @var AvroIO
   */
  private $io;

  /**
   * @var AvroIOBinaryDecoder
   */
  private $decoder;

  /**
   * @var AvroIODatumReader
   */
  private $datum_reader;

  /**
   * @var string
   */
  public $sync_marker;

  /**
   * @var array object container metadata
   */
  public $metadata;

  /**
   * @var int count of items in block
   */
  private $block_count;

  /**
   * @var string compression codec
   */
  private $codec;

  /**
   * @param AvroIO $io source from which to read
   * @param AvroIODatumReader $datum_reader reader that understands
   *                                        the data schema
   * @throws AvroDataIOException if $io is not an instance of AvroIO
   *                             or the codec specified in the header
   *                             is not supported
   * @uses read_header()
   */
  public function __construct($io, $datum_reader)
  {

    if (!($io instanceof AvroIO))
      throw new AvroDataIOException('io must be instance of AvroIO');

    $this->io = $io;
    $this->decoder = new AvroIOBinaryDecoder($this->io);
    $this->datum_reader = $datum_reader;
    $this->read_header();

    $codec = AvroUtil::array_value($this->metadata, 
                                   AvroDataIO::METADATA_CODEC_ATTR);
    if ($codec && !AvroDataIO::is_valid_codec($codec))
      throw new AvroDataIOException(sprintf('Uknown codec: %s', $codec));
    $this->codec = $codec;

    $this->block_count = 0;
    // FIXME: Seems unsanitary to set writers_schema here.
    // Can't constructor take it as an argument?
    $this->datum_reader->set_writers_schema(
      AvroSchema::parse($this->metadata[AvroDataIO::METADATA_SCHEMA_ATTR]));
  }

  /**
   * Reads header of object container
   * @throws AvroDataIOException if the file is not an Avro data file.
   */
  private function read_header()
  {
    $this->seek(0, AvroIO::SEEK_SET);

    $magic = $this->read(AvroDataIO::magic_size());

    if (strlen($magic) < AvroDataIO::magic_size())
      throw new AvroDataIOException(
        'Not an Avro data file: shorter than the Avro magic block');

    if (AvroDataIO::magic() != $magic)
      throw new AvroDataIOException(
        sprintf('Not an Avro data file: %s does not match %s',
                $magic, AvroDataIO::magic()));

    $this->metadata = $this->datum_reader->read_data(AvroDataIO::metadata_schema(),
                                                     AvroDataIO::metadata_schema(),
                                                     $this->decoder);
    $this->sync_marker = $this->read(AvroDataIO::SYNC_SIZE);
  }

  /**
   * @return array of data from object container.
   * @throws AvroDataIOException
   * @throws AvroIOException
   */
  public function data()
  {
    $data = array();
    while (true)
    {
      if (0 == $this->block_count)
      {
        if ($this->is_eof())
          break;

        if ($this->skip_sync())
          if ($this->is_eof())
            break;

        $decoder = $this->apply_codec($this->decoder, $this->codec);
      }
      $data[] = $this->datum_reader->read($decoder);
      $this->block_count -= 1;
    }
    return $data;
  }

  /**
   * @throws AvroDataIOException
   * @throws AvroIOException
   */
  public function data_iterator()
  {
    while (true)
    {
      if (0 == $this->block_count)
      {
        if ($this->is_eof())
          break;

        if ($this->skip_sync())
          if ($this->is_eof())
            break;

        $decoder = $this->apply_codec($this->decoder, $this->codec);
      }
      yield $this->datum_reader->read($decoder);
      $this->block_count -= 1;
    }
    return $data;
  }

  /**
   * @param AvroIOBinaryDecoder $decoder
   * @param string $codec
   * @return AvroIOBinaryDecoder
   * @throws AvroDataIOException
   * @throws AvroIOException
   */
  protected function apply_codec($decoder, $codec)
  {
    $length = $this->read_block_header();
    if ($codec == AvroDataIO::DEFLATE_CODEC) {
      if (!function_exists('gzinflate')) {
        throw new AvroDataIOException('"gzinflate" function not available, "zlib" extension required.');
      }
      $compressed = $decoder->read($length);
      $datum = gzinflate($compressed);
      $decoder = new AvroIOBinaryDecoder(new AvroStringIO($datum));
    } elseif ($codec == AvroDataIO::SNAPPY_CODEC) {
      if (!function_exists('snappy_uncompress')) {
        throw new AvroDataIOException('"snappy_uncompress" function not available, "snappy" extension required.');
      }
      $compressed = $decoder->read($length-4);
      $datum = snappy_uncompress($compressed);
      $crc32 = unpack('N', $decoder->read(4));
      if ($crc32[1] != crc32($datum)) {
        throw new AvroDataIOException('Invalid CRC32 checksum.');
      }
      $decoder = new AvroIOBinaryDecoder(new AvroStringIO($datum));
    }
    return $decoder;
  }

  /**
   * Closes this writer (and its AvroIO object.)
   * @uses AvroIO::close()
   */
  public function close() { return $this->io->close(); }

  /**
   * @uses AvroIO::seek()
   * @param $offset
   * @param $whence
   * @return bool
   * @throws AvroNotImplementedException
   */
  private function seek($offset, $whence)
  {
    return $this->io->seek($offset, $whence);
  }

  /**
   * @uses AvroIO::read()
   * @param $len
   * @return string
   * @throws AvroNotImplementedException
   */
  private function read($len) { return $this->io->read($len); }

  /**
   * @uses AvroIO::is_eof()
   */
  private function is_eof() { return $this->io->is_eof(); }

  /**
   * @return bool
   */
  private function skip_sync()
  {
    $proposed_sync_marker = $this->read(AvroDataIO::SYNC_SIZE);
    if ($proposed_sync_marker != $this->sync_marker)
    {
      $this->seek(-AvroDataIO::SYNC_SIZE, AvroIO::SEEK_CUR);
      return false;
    }
    return true;
  }

  /**
   * Reads the block header (which includes the count of items in the block
   * and the length in bytes of the block)
   * @return int length in bytes of the block.
   */
  private function read_block_header()
  {
    $this->block_count = $this->decoder->read_long();
    return $this->decoder->read_long();
  }

}

/**
 * Writes Avro data to an AvroIO source using an AvroSchema
 * @package Avro
 */
class AvroDataIOWriter
{
  /**
   * @return string a new, unique sync marker.
   */
  private static function generate_sync_marker()
  {
    // From http://php.net/manual/en/function.mt-rand.php comments
    return pack('S8',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff) | 0x4000,
                mt_rand(0, 0xffff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
  }

  /**
   * @var AvroIO object container where data is written
   */
  private $io;

  /**
   * @var AvroIOBinaryEncoder encoder for object container
   */
  private $encoder;

  /**
   * @var AvroDatumWriter
   */
  private $datum_writer;

  /**
   * @var AvroStringIO buffer for writing
   */
  private $buffer;

  /**
   * @var AvroIOBinaryEncoder encoder for buffer
   */
  private $buffer_encoder; // AvroIOBinaryEncoder

  /**
   * @var int count of items written to block
   */
  private $block_count;

  /**
   * @var array map of object container metadata
   */
  private $metadata;

  /**
   * @var string compression codec
   */
  private $codec;

  /**
   * @param AvroIO $io
   * @param AvroIODatumWriter $datum_writer
   * @param AvroSchema $writers_schema
   * @param string $codec
   * @throws AvroDataIOException
   */
  public function __construct($io, $datum_writer, $writers_schema=null, $codec=AvroDataIO::NULL_CODEC)
  {
    if (!($io instanceof AvroIO))
      throw new AvroDataIOException('io must be instance of AvroIO');

    $this->io = $io;
    $this->encoder = new AvroIOBinaryEncoder($this->io);
    $this->datum_writer = $datum_writer;
    $this->buffer = new AvroStringIO();
    $this->buffer_encoder = new AvroIOBinaryEncoder($this->buffer);
    $this->block_count = 0;
    $this->metadata = array();

    if ($writers_schema)
    {
      if (!AvroDataIO::is_valid_codec($codec))
        throw new AvroDataIOException(
          sprintf('codec %s is not supported', $codec));

      $this->sync_marker = self::generate_sync_marker();
      $this->metadata[AvroDataIO::METADATA_CODEC_ATTR] = $this->codec = $codec;
      $this->metadata[AvroDataIO::METADATA_SCHEMA_ATTR] = strval($writers_schema);
      $this->write_header();
    }
    else
    {
      $dfr = new AvroDataIOReader($this->io, new AvroIODatumReader());
      $this->sync_marker = $dfr->sync_marker;
      $this->metadata[AvroDataIO::METADATA_CODEC_ATTR] = $this->codec
                                                       = $dfr->metadata[AvroDataIO::METADATA_CODEC_ATTR];

      $schema_from_file = $dfr->metadata[AvroDataIO::METADATA_SCHEMA_ATTR];
      $this->metadata[AvroDataIO::METADATA_SCHEMA_ATTR] = $schema_from_file;
      $this->datum_writer->writers_schema = AvroSchema::parse($schema_from_file);
      $this->seek(0, SEEK_END);
    }
  }

  /**
   * @param mixed $datum
   */
  public function append($datum)
  {
    $this->datum_writer->write($datum, $this->buffer_encoder);
    $this->block_count++;

    if ($this->buffer->length() >= AvroDataIO::SYNC_INTERVAL)
      $this->write_block();
  }

  /**
   * Flushes buffer to AvroIO object container and closes it.
   * @return mixed value of $io->close()
   * @see AvroIO::close()
   */
  public function close()
  {
    $this->flush();
    return $this->io->close();
  }

  /**
   * Flushes biffer to AvroIO object container.
   * @return mixed value of $io->flush()
   * @see AvroIO::flush()
   */
  private function flush()
  {
    $this->write_block();
    return $this->io->flush();
  }

  /**
   * Writes a block of data to the AvroIO object container.
   */
  private function write_block()
  {
    if ($this->block_count > 0)
    {
      $this->encoder->write_long($this->block_count);
      $to_write = strval($this->buffer);

      if ($this->codec == AvroDataIO::DEFLATE_CODEC) {
        if (!function_exists('gzinflate')) {
          throw new AvroDataIOException('"gzinflate" function not available, "zlib" extension required.');
        }
        $to_write = gzdeflate($to_write);
      } elseif ($this->codec == AvroDataIO::SNAPPY_CODEC) {
        if (!function_exists('snappy_compress')) {
          throw new AvroDataIOException('"snappy_compress" function not available, "snappy" extension required.');
        }
        $crc32 = pack('N', crc32($to_write));
        $to_write = snappy_compress($to_write) . $crc32;
      }

      $this->encoder->write_long(strlen($to_write));
      $this->write($to_write);
      $this->write($this->sync_marker);
      $this->buffer->truncate();
      $this->block_count = 0;
    }
  }

  /**
   * Writes the header of the AvroIO object container
   */
  private function write_header()
  {
    $this->write(AvroDataIO::magic());
    $this->datum_writer->write_data(AvroDataIO::metadata_schema(),
                                    $this->metadata, $this->encoder);
    $this->write($this->sync_marker);
  }

  /**
   * @param string $bytes
   * @uses AvroIO::write()
   * @return int
   */
  private function write($bytes) { return $this->io->write($bytes); }

  /**
   * @param int $offset
   * @param int $whence
   * @uses AvroIO::seek()
   * @return bool
   */
  private function seek($offset, $whence)
  {
    return $this->io->seek($offset, $whence);
  }
}
