<?php
namespace phpcassa;

use phpcassa\Connection\ConnectionWrapper;
use phpcassa\Schema\DataType;

use cassa_cassandra\KsDef;
use cassa_cassandra\CfDef;
use cassa_cassandra\ColumnDef;
use cassa_cassandra\IndexType;

/**
 * Helps with getting information about the schema, making
 * schema changes, and getting information about the state
 * and configuration of the cluster.
 *
 * @package phpcassa
 */
class SystemManager {

    /** @internal */
    const KEEP = "<__keep__>";

    /**
     * @param string $server the host and port to connect to, in the
     *        form 'host:port'. Defaults to 'localhost:9160'.
     * @param array $credentials if using authentication or authorization with Cassandra,
     *        a username and password need to be supplied. This should be in the form
     *        array("username" => username, "password" => password)
     * @param int $send_timeout the socket send timeout in milliseconds. Defaults to 15000.
     * @param int $recv_timeout the socket receive timeout in milliseconds. Defaults to 15000.
     */
    public function __construct($server='localhost:9160',
                                $credentials=NULL,
                                $send_timeout=15000,
                                $recv_timeout=15000)
    {
        $this->conn = new ConnectionWrapper(
            NULL, $server, $credentials, True,
            $send_timeout, $recv_timeout);
        $this->client = $this->conn->client;
    }

    /**
     * Closes the underlying Thrift connection.
     */
    public function close() {
        $this->conn->close();
    }

    protected function wait_for_agreement() {
        while (true) {
            $versions = $this->client->describe_schema_versions();
            if (count($versions) == 1)
                break;
            usleep(500);
        }
    }

    /**
     * Creates a new keyspace.
     *
     * Example usage:
     * <code>
     * use phpcassa\SystemManager;
     * use phpcassa\Schema\StrategyClass;
     *
     * $sys = SystemManager();
     * $attrs = array("strategy_class" => StrategyClass\SIMPLE_STRATEGY,
     *                "strategy_options" => array("replication_factor" => "1"));
     * $sys->create_keyspace("Keyspace1", $attrs);
     * </code>
     *
     * @param string $keyspace the keyspace name
     * @param array $attrs an array that maps attribute
     *        names to values. Valid attribute names include
     *        "strategy_class", "strategy_options", and
     *        "replication_factor".
     *
     *        By default, SimpleStrategy will be used with a replication
     *        factor of 1 and no strategy options.
     *
     */
    public function create_keyspace($keyspace, $attrs) {
        $ksdef = $this->make_ksdef($keyspace, $attrs);
        $this->client->system_add_keyspace($ksdef);
        $this->wait_for_agreement();
    }

    /**
     * Modifies a keyspace's properties.
     *
     * Example usage:
     * <code>
     * $sys = SystemManager();
     * $attrs = array("replication_factor" => 2);
     * $sys->alter_keyspace("Keyspace1", $attrs);
     * </code>
     *
     * @param string $keyspace the keyspace to modify
     * @param array $attrs an array that maps attribute
     *        names to values. Valid attribute names include
     *        "strategy_class", "strategy_options", and
     *        "replication_factor".
     *
     */
    public function alter_keyspace($keyspace, $attrs) {
        $ksdef = $this->client->describe_keyspace($keyspace);
        $ksdef = $this->make_ksdef($keyspace, $attrs, $ksdef);
        $this->client->system_update_keyspace($ksdef);
        $this->wait_for_agreement();
    }

    /*
     * Drops a keyspace.
     *
     * @param string $keyspace the keyspace name
     */
    public function drop_keyspace($keyspace) {
        $this->client->system_drop_keyspace($keyspace);
        $this->wait_for_agreement();
    }

    protected static function endswith($haystack, $needle) {
        $start  = strlen($needle) * -1; //negative
        return (substr($haystack, $start) === $needle);
    }

    protected function make_ksdef($name, $attrs, $orig=NULL) {
        if ($orig !== NULL) {
            $ksdef = $orig;
        } else {
            $ksdef = new KsDef();
            $ksdef->strategy_class = 'SimpleStrategy';
            $ksdef->strategy_options = array("replication_factor" => "1");
        }

        $ksdef->name = $name;
        $ksdef->cf_defs = array();
        foreach ($attrs as $attr => $value) {
            if ($attr == "strategy_class") {
                if (strpos($value, ".") === false)
                    $value = "org.apache.cassandra.locator.$value";
                $ksdef->strategy_class = $value;
            } else {
                $ksdef->$attr = $value;
            }
        }
        return $ksdef;
    }

    /**
     * Creates a column family.
     *
     * Example usage:
     * <code>
     * $sys = SystemManager();
     * $attrs = array("column_type" => "Standard",
     *                "comparator_type" => "org.apache.cassandra.db.marshal.AsciiType",
     *                "memtable_throughput_in_mb" => 32);
     * $sys->create_column_family("Keyspace1", "ColumnFamily1", $attrs);
     * </code>
     *
     * @param string $keyspace the keyspace containing the column family
     * @param string $column_family the name of the column family
     * @param array $attrs an array that maps attribute
     *        names to values.
     */
    public function create_column_family($keyspace, $column_family, $attrs=null) {
        if ($attrs === null)
            $attrs = array();

        $this->client->set_keyspace($keyspace);
        $cfdef = $this->make_cfdef($keyspace, $column_family, $attrs);
        $this->client->system_add_column_family($cfdef);
        $this->wait_for_agreement();
    }

    protected function get_cfdef($ksname, $cfname) {
        $ksdef = $this->client->describe_keyspace($ksname);
        $cfdefs = $ksdef->cf_defs;
        foreach($cfdefs as $cfdef) {
            if ($cfdef->name == $cfname)
                return $cfdef;
        }
        return;
    }

    protected function make_cfdef($ksname, $cfname, $attrs, $orig=NULL) {
        if ($orig !== NULL) {
            $cfdef = $orig;
        } else {
            $cfdef = new CfDef();
            $cfdef->column_type = "Standard";
        }

        $cfdef->keyspace = $ksname;
        $cfdef->name = $cfname;

        foreach ($attrs as $attr => $value)
            $cfdef->$attr = $value;

        return $cfdef;
    }

    /**
     * Modifies a column family's attributes.
     *
     * Example usage:
     * <code>
     * $sys = SystemManager();
     * $attrs = array("max_compaction_threshold" => 10);
     * $sys->alter_column_family("Keyspace1", "ColumnFamily1", $attrs);
     * </code>
     *
     * @param string $keyspace the keyspace containing the column family
     * @param string $column_family the name of the column family
     * @param array $attrs an array that maps attribute
     *        names to values.
     */
    public function alter_column_family($keyspace, $column_family, $attrs) {
        $cfdef = $this->get_cfdef($keyspace, $column_family);
        $cfdef = $this->make_cfdef($keyspace, $column_family, $attrs, $cfdef);
        $this->client->set_keyspace($cfdef->keyspace);
        $this->client->system_update_column_family($cfdef);
        $this->wait_for_agreement();
    }

    /*
     * Drops a column family from a keyspace.
     *
     * @param string $keyspace the keyspace the CF is in
     * @param string $column_family the column family name
     */
    public function drop_column_family($keyspace, $column_family) {
        $this->client->set_keyspace($keyspace);
        $this->client->system_drop_column_family($column_family);
        $this->wait_for_agreement();
    }

    /**
     * Mark the entire column family as deleted.
     *
     * From the user's perspective a successful call to truncate will result
     * complete data deletion from cfname. Internally, however, disk space
     * will not be immediatily released, as with all deletes in cassandra,
     * this one only marks the data as deleted.
     *
     * The operation succeeds only if all hosts in the cluster at available
     * and will throw an UnavailableException if some hosts are down.
     *
     * Example usage:
     * <code>
     * $sys = SystemManager();
     * $sys->truncate_column_family("Keyspace1", "ColumnFamily1");
     * </code>
     *
     * @param string $keyspace the keyspace the CF is in
     * @param string $column_family the column family name
     */
    public function truncate_column_family($keyspace, $column_family) {
        $this->client->set_keyspace($keyspace);
        $this->client->truncate($column_family);
    }

    /**
     * Adds an index to a column family.
     *
     * Example usage:
     *
     * <code>
     * $sys = new SystemManager();
     * $sys->create_index("Keyspace1", "Users", "name", "UTF8Type");
     * </code>
     *
     * @param string $keyspace the name of the keyspace containing the column family
     * @param string $column_family the name of the column family
     * @param string $column the name of the column to put the index on
     * @param string $data_type the data type of the values being indexed
     * @param string $index_name an optional name for the index
     * @param IndexType $index_type the type of index. Defaults to
     *        \cassandra\IndexType::KEYS_INDEX, which is currently the only option.
     */
    public function create_index($keyspace, $column_family, $column,
        $data_type=self::KEEP, $index_name=NULL, $index_type=IndexType::KEYS)
    {
        $this->_alter_column($keyspace, $column_family, $column,
            $data_type=$data_type, $index_type=$index_type, $index_name=$index_name);
    }

    /**
     * Drop an index from a column family.
     *
     * Example usage:
     *
     * <code>
     * $sys = new SystemManager();
     * $sys->drop_index("Keyspace1", "Users", "name");
     * </code>
     *
     * @param string $keyspace the name of the keyspace containing the column family
     * @param string $column_family the name of the column family
     * @param string $column the name of the column to drop the index from
     */
    public function drop_index($keyspace, $column_family, $column) {
        $this->_alter_column($keyspace, $column_family, $column,
            $data_type=self::KEEP, $index_type=NULL, $index_name=NULL);
    }

    /**
     * Changes or sets the validation class of a single column.
     *
     * Example usage:
     *
     * <code>
     * $sys = new SystemManager();
     * $sys->alter_column("Keyspace1", "Users", "name", "UTF8Type");
     * </code>
     *
     * @param string $keyspace the name of the keyspace containing the column family
     * @param string $column_family the name of the column family
     * @param string $column the name of the column to put the index on
     * @param string $data_type the data type of the values being indexed
     */
    public function alter_column($keyspace, $column_family, $column, $data_type) {
        $this->_alter_column($keyspace, $column_family, $column, $data_type);
    }

    protected static function qualify_class_name($data_type) {
        if ($data_type === null)
            return null;

        if (strpos($data_type, ".") === false)
            return "org.apache.cassandra.db.marshal.$data_type";
        else
            return $data_type;
    }

    protected function _alter_column($keyspace, $column_family, $column,
        $data_type=self::KEEP, $index_type=self::KEEP, $index_name=self::KEEP) {

        $this->client->set_keyspace($keyspace);
        $cfdef = $this->get_cfdef($keyspace, $column_family);

        if ($cfdef->column_type == 'Super') {
            $col_name_type = DataType::get_type_for($cfdef->subcomparator_type);
        } else {
            $col_name_type = DataType::get_type_for($cfdef->comparator_type);
        }
        $packed_name = $col_name_type->pack($column);

        $col_def = null;
        $col_meta = $cfdef->column_metadata;
        for ($i = 0; $i < count($col_meta); $i++) {
            $temp_col_def = $col_meta[$i];
            if ($temp_col_def->name === $packed_name) {
                $col_def = $temp_col_def;
                unset($col_meta[$i]);
                break;
            }
        }

        if ($col_def === null) {
            $col_def = new ColumnDef();
            $col_def->name = $packed_name;
        }
        if ($data_type !== self::KEEP)
            $col_def->validation_class = self::qualify_class_name($data_type);
        if ($index_type !== self::KEEP)
            $col_def->index_type = $index_type;
        if ($index_name !== self::KEEP)
            $col_def->index_name = $index_name;

        $col_meta[] = $col_def;
        $cfdef->column_metadata = $col_meta;
        $this->client->system_update_column_family($cfdef);
        $this->wait_for_agreement();
    }

    /**
     * Describes the Cassandra cluster.
     *
     * @return array the node to token mapping
     */
    public function describe_ring($keyspace) {
        return $this->client->describe_ring($keyspace);
    }

    /**
     * Gives the cluster name.
     *
     * @return string the cluster name
     */
    public function describe_cluster_name() {
        return $this->client->describe_cluster_name();
    }

    /**
     * Gives the Thrift API version for the Cassandra instance.
     *
     * Note that this is different than the Cassandra version.
     *
     * @return string the API version
     */
    public function describe_version() {
        return $this->client->describe_version();
    }

    /**
     * Describes what schema version each node currently has.
     * Differences in schema versions indicate a schema conflict.
     *
     * @return array a mapping of schema versions to nodes.
     */
    public function describe_schema_versions() {
        return $this->client->describe_schema_versions();
    }

    /**
     * Describes the cluster's partitioner.
     *
     * @return string the name of the partitioner in use
     */
    public function describe_partitioner() {
        return $this->client->describe_partitioner();
    }

    /**
     * Describes the cluster's snitch.
     *
     * @return string the name of the snitch in use
     */
    public function describe_snitch() {
        return $this->client->describe_snitch();
    }

    /**
     * Returns a description of the keyspace and its column families.
     * This includes all configuration settings for the keyspace and
     * column families.
     *
     * @param string $keyspace the keyspace name
     *
     * @return cassandra\KsDef
     */
    public function describe_keyspace($keyspace) {
        return $this->client->describe_keyspace($keyspace);
    }

    /**
     * Like describe_keyspace(), but for all keyspaces.
     *
     * @return array an array of cassandra\KsDef
     */
    public function describe_keyspaces() {
        return $this->client->describe_keyspaces();
    }
}

