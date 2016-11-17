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

/**
 * 4D statement
 */
class FourDStatement {
  /** @var FourDClient */
  public $client;

  public $id;
  public $type;
  public $updateability = false;
  public $rowCount;
  public $rowCountSent;
  public $rowCountRead = 0;
  public $columns = array();

  /**
   * FourDStatement constructor.
   *
   * @param FourDClient $client Client object
   * @param string      $header Header to build the statement
   */
  function __construct(FourDClient $client, $header) {
    $this->client = $client;

    $this->id = $header["statement-id"];
    $this->type = trim($header["result-type"]);
    $this->rowCount = $header["row-count"];
    $this->rowCountSent = $header["row-count-sent"];

    if ($this->type === "Result-Set") {
      $aliases = explode(" ", trim($header["column-aliases"]));
      $types = explode(" ", trim($header["column-types"]));
      $updateability = explode(" ", trim($header["column-updateability"]));

      $columns = array();
      foreach ($aliases as $_i => $_alias) {
        // Fixme : should support [, ], or space in alias
        $_alias = trim($_alias, '[]');
        $_updateability = $updateability[$_i] === "Y";

        if ($_updateability) {
          $this->updateability = true;
        }

        $columns[$_i] = array(
          "alias"         => $_alias,
          "type"          => $types[$_i],
          "updateability" => $_updateability,
        );
      }

      $this->columns = $columns;
    }
  }

  /**
   * Get column names
   *
   * @return array
   */
  function getColumns() {
    $columns = array();

    foreach ($this->columns as $_column) {
      $columns[] = $_column["alias"];
    }

    return $columns;
  }

  /**
   * Fetch a row as an array
   *
   * @return array|null
   * @throws Exception
   */
  function fetchRow() {
    $client = $this->client;

    if ($this->rowCountRead >= $this->rowCountSent) {
      return null;
    }

    if ($this->updateability) {
      $client->read(1);
      $client->readUInt32();
    }

    $data = array();

    $debug = false;

    foreach ($this->columns as $_column) {
      $_status = $client->read(1);
      $_value = null;

      if ($_status == 2) {
        throw new Exception("Not good ...");
      }

      if ($_status == 1) {
        switch ($_column["type"]) {
          case "VK_BOOLEAN":
            $_value = $client->readInt16() !== 0;
            break;

          case "VK_BYTE":
          case "VK_WORD":
            $_value = $client->readInt16();
            break;

          case "VK_LONG":
            $_value = $client->readUInt32();
            break;

          case "VK_LONG8":
          case "VK_REAL":
            $_value = $client->readUInt64();
            break;

          case "VK_FLOAT":
            $_exp = $client->readUInt32();
            $_sign = $client->readUByte();
            $_size = $client->readUInt32();
            $_data = $client->read($_size);

            $_value = sprintf("float : $_sign $_data ^ $_exp");
            break;

          case "VK_TIME":
          case "VK_TIMESTAMP":
            $_year = $client->readUInt16();
            $_month = $client->readUByte();
            $_day = $client->readUByte();
            $_ms = $client->readUInt32();

            $_h = $_ms / (60 * 60 * 1000);
            $_ms -= $_h * (60 * 60 * 1000);

            $_m = $_ms / (60 * 1000);
            $_ms -= $_m * (60 * 1000);

            $_s = $_ms / (1000);
            $_ms -= $_s * (1000);

            $_value = sprintf("%04d-%02d-%02d %02d:%02d:%02d.%03d", $_year, $_month, $_day, $_h, $_m, $_s, $_ms);
            break;

          case "VK_DURATION":
            $_value = $client->readUInt64();
            break;

          case "VK_TEXT":
          case "VK_STRING":
            $_value = $client->readPascalString();
            break;

          case "VK_BLOB":
          case "VK_IMAGE":
            $_value = $client->readBlob();
            break;

          default:
            trigger_error("Unknown data type");
            break;
        }
      }

      $data[$_column["alias"]] = $_value;

      if ($debug) {
        echo sprintf("%s:\n\t%s\n\n", $_column["alias"], $_value);
      }
    }

    $this->rowCountRead++;

    return $data;
  }

  /**
   * Fetch all data from the table
   *
   * @return array
   */
  function fetchAll() {
    $rows = array();

    while ($row = $this->fetchRow()) {
      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * Close statement
   *
   * @return void
   */
  function close() {
    $id_cnx = 1;
    $msg = sprintf(
      "%03d CLOSE-STATEMENT\r\n" .
      "STATEMENT-ID:%d\r\n",
      $id_cnx,
      $this->id
    );

    $this->client->send($msg);
    $this->client->receiveHeader();
  }
}