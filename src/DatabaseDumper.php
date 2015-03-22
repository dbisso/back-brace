<?php
namespace DBisso\BackBrace;

/**
 * Creates a resource that streams a dump of a MySQL database
 *
 * @package BackBrace
 */
class DatabaseDumper {
	/**
	 * The stream representing the database dump
	 * @var resource
	 */
	private $dumpStream;

	/**
	 * The path to the mysqldump binary
	 * @var string
	 */
	private $mysqldumpBin;

	/**
	 * Database connection details
	 * @var array
	 */
	private $connectionConfig;

	/**
	 * The actual dumping process
	 * @var resource
	 */
	private $dumpProcess;

	/**
	 * Constructor
	 * @param array $connectionConfig Database connection details
	 */
	public function __construct( array $connectionConfig ) {
		$this->connectionConfig = $connectionConfig;
		$this->mysqldumpBin = $this->getDumpBin();

		// $this->dumpStream = fopen( sys_get_temp_dir() . '/gotyourback-dump-temp', 'w+' );
		// var_dump(sys_get_temp_dir() . '/gotyourback-dump-temp');
		// $this->dumpStream = tmpfile();
	}

	/**
	 * Trigger the dumping
	 * @return stream The stream of the dump
	 */
	public function dump() {
		$this->process = $this->getDumpProcess();

		if ( false === $this->process ) {
			return false;
		}

		return $this->dumpStream;
	}

	/**
	 * Get the dump process
	 * @return resource Resource representing the mysqldump command
	 */
	private function getDumpProcess() {
		$filename = tempnam( sys_get_temp_dir(), 'gyb' );
		$cmd      = system( $this->getDumpCommand() . ' > ' . escapeshellcmd( $filename ) );

		$this->dumpStream = fopen( $filename, 'r+b' );

		return $cmd;
	}

	private function getDumpCommand() {
		$mysqldumpBin = $this->getDumpBin();

		return escapeshellcmd( "$mysqldumpBin -u{$this->connectionConfig['user']} -p{$this->connectionConfig['password']} -h{$this->connectionConfig['host']} -P{$this->connectionConfig['port']} {$this->connectionConfig['database']}" );
	}

	/**
	 * Get the binary for mysqldump
	 * @return string Path to mysqldump
	 */
	private function getDumpBin() {
		exec( 'which mysqldump', $exec, $return );

		if ( $return === 0 ) {
			return $exec[0];
		}

		throw new Exception("Command mysqldump not found", 1);
	}

	/**
	 * Tidy up our connections.
	 */
	public function __destruct() {
		// if ( $this->dumpStream ) {
		// }

		// proc_close($this->process);
		// fclose($this->dumpStream);
	}
}