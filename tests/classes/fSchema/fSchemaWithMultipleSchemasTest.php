<?php
require_once('./support/init.php');

function fix_schema($input)
{
	if (DB_TYPE != 'oracle' && DB_TYPE != 'db2') {
		return $input;	
	}
	$input = str_replace('flourish2.', DB_SECOND_SCHEMA . '.', $input);
	return str_replace('flourish_role', DB_NAME . '_role', $input);	
}

class fSchemaWithMultipleSchemasTest extends PHPUnit_Framework_TestSuite
{
	public static function suite()
	{
		return new fSchemaWithMultipleSchemasTest('fSchemaWithMultipleSchemasTestChild');
	}
 
	protected function setUp()
	{
		if (defined('SKIPPING')) {
			return;
		}
		$db = new fDatabase(DB_TYPE, DB, DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT); 
		
		$db->execute(file_get_contents(DB_SETUP_FILE));
		$db->execute(fix_schema(file_get_contents(DB_ALTERNATE_SCHEMA_SETUP_FILE)));
		
		$this->sharedFixture = array(
			'db' => $db,
			'schema' => fJSON::decode(fix_schema(file_get_contents(DB_ALTERNATE_SCHEMA_SCHEMA_FILE)), TRUE)
		);
	}
 
	protected function tearDown()
	{
		if (defined('SKIPPING')) {
			return;
		}
		$db = $this->sharedFixture['db'];
		$db->execute(fix_schema(file_get_contents(DB_ALTERNATE_SCHEMA_TEARDOWN_FILE)));
		$db->execute(file_get_contents(DB_TEARDOWN_FILE));
	}
}
 
class fSchemaWithMultipleSchemasTestChild extends PHPUnit_Framework_TestCase
{
	public $db;
	public $schema;
	public $schema_obj;
	
	public function setUp()
	{
		if (defined('SKIPPING')) {
			$this->markTestSkipped();
		}
		$this->db         = $this->sharedFixture['db'];
		$this->schema_obj = new fSchema($this->db);
		$this->schema     = $this->sharedFixture['schema'];
	}
	
	public function tearDown()
	{
			
	}
	
	public function testGetTables()
	{
		$tables = $this->schema_obj->getTables();
		
		$this->assertEquals(
			array(
				"albums",
				"artists",
				"blobs",
				fix_schema("flourish2.albums"),
				fix_schema("flourish2.artists"),
				fix_schema("flourish2.groups"),
				fix_schema("flourish2.users"),
				fix_schema("flourish2.users_groups"),
				"groups",
				"owns_on_cd",
				"owns_on_tape",
				"songs",
				"users",
				"users_groups"
			),
			$tables
		);
	}
	
	public static function getTableProvider()
	{
		$output = array();
		
		$output[] = array(fix_schema("flourish2.albums"));
		$output[] = array(fix_schema("flourish2.artists"));
		$output[] = array(fix_schema("flourish2.groups"));
		$output[] = array(fix_schema("flourish2.users"));
		$output[] = array(fix_schema("flourish2.users_groups"));
		
		return $output;
	}
	
	/**
	 * @dataProvider getTableProvider
	 */
	public function testGetColumnInfo($table)
	{
		$schema_column_info = $this->schema['column_info'][$table];
		foreach ($schema_column_info as $col => &$info) {
			ksort($info);	
		}
		ksort($schema_column_info);
		
		$column_info = $this->schema_obj->getColumnInfo($table);
		foreach ($column_info as $col => &$info) {
			ksort($info);
			foreach ($info as $key => $value) {
				if ($value instanceof fNumber) {
					$info[$key] = $value->__toString();	
				}
			}	
		}
		ksort($column_info);
		
		$this->assertEquals(
			$schema_column_info,
			$column_info
		);
	}
	
	/**
	 * @dataProvider getTableProvider
	 */
	public function testGetKeys($table)
	{
		$schema_keys = $this->schema['keys'][$table];
		foreach ($schema_keys as $type => &$list) {
			sort($list);	
		}
		ksort($schema_keys);
		
		$keys = $this->schema_obj->getKeys($table);
		foreach ($keys as $type => &$list) {
			sort($list);
		}
		ksort($keys);
		
		$this->assertEquals(
			$schema_keys,
			$keys
		);
	}
	
	/**
	 * @dataProvider getTableProvider
	 */
	public function testGetRelationships($table)
	{
		$schema_relationships = $this->schema['relationships'][$table];
		foreach ($schema_relationships as $type => &$list) {
			sort($list);	
		}
		ksort($schema_relationships);
		
		$relationships = $this->schema_obj->getRelationships($table);
		foreach ($relationships as $type => &$list) {
			sort($list);
		}
		ksort($relationships);
		
		$this->assertEquals(
			$schema_relationships,
			$relationships
		);
	}
}