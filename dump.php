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

// CLI or die
PHP_SAPI === "cli" or die;

$options = array(
  "debug"    => false,
  "host"     => "127.0.0.1",
  "port"     => 19812,
  "username" => "administrateur",
  "password" => null,
  "output"   => ".",
  "limit"    => 1000000,
  "tables"   => null,
);

for ($i = 1; $i < $argc; $i++) {
  switch ($argv[$i]) {
    case "--debug":
      $options["debug"] = true;
      break;

    case "--host":
    case "--port":
    case "--username":
    case "--password":
    case "--output":
    case "--limit":
    case "--tables":
      $options[substr($argv[$i], 2)] = $argv[++$i];
      break;
    default:
      // Ignore
  }
}

require_once __DIR__."/src/FourDClient.php";

$t = microtime(true);

$client = new FourDClient($options["host"], $options["port"]);

$client->login($options["username"], $options["password"]);

if (empty($options["tables"])) {
  $stmt = $client->execStatement("SELECT * FROM _USER_TABLES;");
  $list_tables = array();
  while ($row = $stmt->fetchRow()) {
    $_table_name = $row["TABLE_NAME"];

    if (in_array($_table_name, array("PARAMETRES"))) {
      continue;
    }

    $list_tables[] = $_table_name;
  }
}
else {
  $list_tables = explode(",", $options["tables"]);
}

$limit = (int)$options["limit"];

$output = $options["output"];
if (!is_dir($output)) {
  mkdir($output);
}

foreach ($list_tables as $_table) {
  echo "## $_table ##\n";

  // List columns
  $_table_name = strtoupper($_table);
  $stmt_cols = $client->execStatement("SELECT * FROM _USER_COLUMNS WHERE TABLE_NAME = '$_table_name';");
  $cols = $stmt_cols->fetchAll();
  $stmt_cols->close();

  // List indexes
  $stmt_indexes = $client->execStatement("SELECT * FROM _USER_INDEXES WHERE TABLE_NAME = '$_table_name';");
  $indexes = $stmt_indexes->fetchAll();
  $stmt_indexes->close();

  $query = "CREATE TABLE `$_table`";
  $query_columns = array();
  foreach ($cols as $_col) {
    $_name = $_col["COLUMN_NAME"];
    $_type = $_col["DATA_TYPE"];
    $_length = $_col["DATA_LENGTH"];
    $_nullable = $_col["NULLABLE"];

    /**
     * Field type in 4D   DATA_TYPE
     * ------------------------------
     * Boolean            1
     * Integer            3
     * Long Integer       4
     * Integer 64 Bits    5
     * Real               6
     * Float              7
     * Date               8
     * Time               9
     * Alpha              10 *
     * Text               10 *
     * Picture            12
     * BLOB               18
     */
    switch ($_type) {
      case 1:
        $_sql_type = "TINYINT(1)";
        break;
      case 3:
        $_sql_type = "SMALLINT(11)";
        break;
      case 4:
        $_sql_type = "INT(11)";
        break;
      case 5:
        $_sql_type = "BIGINT(20)";
        break;
      case 6:
        $_sql_type = "DOUBLE";
        break;
      case 7:
        $_sql_type = "FLOAT";
        break;
      case 8:
        $_sql_type = "DATE";
        break;
      case 9:
        $_sql_type = "TIME";
        break;
      case 10:
        if ($_length == 0 || $_length > 65535) {
          $_sql_type = "LONGTEXT";
        }
        elseif ($_length > 255) {
          $_sql_type = "TEXT";
        }
        else {
          $_sql_type = "VARCHAR($_length)";
        }
        break;
      case 12:
      case 18:
        $_sql_type = "BLOB";
        break;

      default:
        throw new Exception("Unknown data type $_type");
    }

    $query_columns[] = "`$_name` $_sql_type";
  }

  $query .= "(\n  ".implode(",\n  ", $query_columns)."\n);";

  file_put_contents("$output/$_table.sql", $query);

  $stmt = $client->execStatement("SELECT * FROM $_table LIMIT $limit;");

  $csv = fopen("$output/$_table.csv", "w");

  $s = fputcsv($csv, $stmt->getColumns());

  $n = 0;
  while ($row = $stmt->fetchRow()) {
    $s += fputcsv($csv, $row);

    if ($n % 500 === 0) {
      echo sprintf(" -> Rows: %d \tFile size: %s\r", $n, number_format($s, 0, ".", " "));
    }

    $n++;
  }

  echo sprintf(" -> %d rows exported \tFile size: %s\r", $n, number_format($s, 0, ".", " "));

  echo "\n";

  $stmt->close();
}

echo sprintf("Done in %.3f ms\n", (microtime(true) - $t) * 1000);
