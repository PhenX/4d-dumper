4D database dumper
==================
This script is intented to be used on 4D SQL servers, to dump the database as CSV files + MySQL table declaration.

Requirements
------------
 * PHP 5.3+
 * sockets extension

Usage
-----
`php dump.php [--host 127.0.0.1] [--port 19812] [--username admin] [--password abcd] [--output out] [--tables comma separated list of files] [--limit 1000000]`

Will output, by table, in the output directory : 
 * one SQL file containing MySQL CREATE TABLE 
 * one CSV file containg data

License
-------
LGPL - GNU Lesser General Public License