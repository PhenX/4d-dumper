<?php

/**
 * @package 4d-dumper
 * @link    http://github.com/PhenX/4d-dumper
 * @author  Fabien Ménager <fabien.menager@gmail.com>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 *
 * @link http://sources.4d.com/trac/4d_pdo4d
 * @link http://sources.4d.com/trac/4d_pdo4d/raw-attachment/wiki/WikiStart/SQL%20networking%20protocol.doc
 */

require_once __DIR__."/FourDStatement.php";

/**
 * 4D client for the 4D SQL server
 */
class FourDClient {
  /** @var resource Socket resource */
  protected $socket;

  /** @var string Host name */
  protected $host;

  /** @var int Port number */
  protected $port;

  /** @var string SQL username */
  protected $username;

  /** @var string SQL password */
  protected $password;

  /**
   * FourDClient constructor.
   *
   * @param string $host Host name
   * @param int    $port Port number
   *
   * @throws Exception
   */
  public function __construct($host, $port = 19812) {
    $this->host = $host;
    $this->port = $port;

    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$socket) {
      throw new Exception("Could not create socket");
    }

    $r = socket_connect($socket, $host, $port);
    if (!$r) {
      throw new Exception("Could not connect to $host:$port");
    }

    socket_set_block($socket);

    $this->socket = $socket;
  }

  /**
   * Login to the server
   *
   * @param string $username SQL username
   * @param string $password SQL password
   *
   * @return void
   */
  public function login($username, $password) {
    $this->username = $username;
    $this->password = $password;

    $id_cnx = 1;
    $msg = sprintf(
      "%03d LOGIN\r\n" .
      "USER-NAME-BASE64:%s\r\n" .
      "USER-PASSWORD-BASE64:%s\r\n" .
      "REPLY-WITH-BASE64-TEXT:%s\r\n" .
      "PROTOCOL-VERSION:0.1a\r\n",
      $id_cnx,
      base64_encode($username),
      base64_encode($password),
      "Y"
    );

    $this->send($msg);

    $result = $this->parseResponseHeader($this->receiveHeader());

    echo "--------\n";

    foreach ($result as $_key => $_value) {
      if ($_key === "_") {
        echo "STATUS: $_value\n-------\n";
        continue;
      }

      echo "$_key: $_value\n";
    }

    echo "--------\n";
  }

  /**
   * Parses response header
   *
   * @param string $header The header
   *
   * @return array
   */
  function parseResponseHeader($header) {
    $lines = explode("\r\n", $header);
    $data = array();
    foreach ($lines as $_line) {
      if ($_line === "") {
        continue;
      }

      $_parts = explode(":", $_line, 2);

      if (count($_parts) === 1) {
        $_parts[1] = $_parts[0];
        $_parts[0] = "_";
      }

      list($key, $value) = $_parts;

      $key = strtolower($key);

      if (substr($key, -7) === "-base64") {
        $key = substr($key, 0, -7);
        $value = base64_decode($value);
      }

      $data[$key] = $value;
    }

    return $data;
  }

  /**
   * Execute statement
   *
   * @param string $query The query to execute
   *
   * @return FourDStatement
   */
  public function execStatement($query) {
    $id_cnx = 1;
    $n = 1000000;
    $msg = sprintf(
      "%03d EXECUTE-STATEMENT\r\n" .
      "FIRST-PAGE-SIZE:%d\r\n" .
      "STATEMENT-BASE64:%s\r\n" .
      "PREFERRED-IMAGE-TYPES:%s\r\n".
      "Output-Mode:%s\r\n",
      $id_cnx,
      $n,
      base64_encode($query),
      "png jpg",
      "release"
    );

    $this->send($msg);

    $header = $this->receiveHeader();

    return new FourDStatement($this, $this->parseResponseHeader($header));
  }

  /**
   * Sent data to the server
   *
   * @param string $data Data
   *
   * @return int
   */
  public function send($data) {
    return socket_write($this->socket, $data . "\r\n");
  }


  /**
   * Receive header after a request
   *
   * @return int|string
   */
  public function receiveHeader() {
    $buffer = "";
    $result = 0;
    $offset = 0;
    $len = 0;
    $crlf = false;

    do {
      $offset += $result;

      $_buffer = "";
      $result = socket_recv($this->socket, $_buffer, 1, 0);
      $buffer .= $_buffer;

      $len += $result;

      if ($len > 3) {
        if ($buffer[$offset - 3] === "\r"
            && $buffer[$offset - 2] === "\n"
            && $buffer[$offset - 1] === "\r"
            && $buffer[$offset] === "\n"
        ) {
          $crlf = true;
        }
      }
    } while ($result > 0 && !$crlf);

    if (!$crlf) {
      echo "Error: Header-end not found : $buffer\n";

      return 1;
    }

    return $buffer;
  }

  /**
   * Read string from socket
   *
   * @param int $size The length of the string to read
   *
   * @return string
   */
  function read($size) {
    $buffer = "";
    $index = $size-1;
    $remaining_size = $size;

    while (!isset($buffer[$index])) {
      $_r = socket_read($this->socket, $remaining_size, PHP_BINARY_READ);

      if ($_r === false) {
        break;
      }

      $remaining_size -= strlen($_r);

      $buffer .= $_r;
    }

    return $buffer;
  }

  /**
   * Read a Pascal string (length followed by the string)
   *
   * @return string
   */
  function readPascalString() {
    $l = abs($this->readUInt32()) * 2;

    if ($l == 0) {
      return "";
    }

    $string = $this->read($l);

    return iconv('UTF-16LE', 'UTF-8', $string);
  }

  /**
   * Read a Blob (pascal-like)
   *
   * @return string
   */
  function readBlob() {
    $l = abs($this->readUInt32());

    if ($l == 0) {
      return "";
    }

    return $this->read($l);
  }

  /**
   * Read unsigned byte
   *
   * @return int
   */
  function readUByte() {
    $d = $this->read(1);

    if ($d === false || $d === "") {
      return false;
    }

    $d = unpack("C", $d);

    return $d[1];
  }

  /**
   * Read unsigned 16 bits numbers
   *
   * @return integer
   */
  function readUInt16() {
    $tmp = unpack("v", $this->read(2));

    return $tmp[1];
  }

  /**
   * Read 16 bits numbers.
   *
   * @return integer
   */
  function readInt16() {
    $int = $this->readUInt16();

    if ($int >= 0x8000) {
      $int -= 0x10000;
    }

    return $int;
  }

  /**
   * Read unsigned 32 bits numbers
   *
   * @return integer
   */
  function readUInt32() {
    $tmp = unpack("V", $this->read(4));

    return $tmp[1];
  }

  /**
   * Read unsigned 32 bits numbers
   *
   * @return integer
   */
  function readUInt64() {
    $tmp = unpack("V", $this->read(8));

    return $tmp[1];
  }

}