<?php
/**
 * holds the database index class
 */
declare(strict_types=1);

namespace PhpMyAdmin;

use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Html\MySQLDocumentation;
use function array_pop;
use function count;
use function htmlspecialchars;
use function sprintf;
use function strlen;

/**
 * Index manipulation class
 */
class Index
{
    public const PRIMARY  = 1;
    public const UNIQUE   = 2;
    public const INDEX    = 4;
    public const SPATIAL  = 8;
    public const FULLTEXT = 16;

    /**
     * Class-wide storage container for indexes (caching, singleton)
     *
     * @var array
     */
    private static $_registry = [];

    /** @var string The name of the schema */
    private $_schema = '';

    /** @var string The name of the table */
    private $_table = '';

    /** @var string The name of the index */
    private $_name = '';

    /**
     * Columns in index
     *
     * @var array
     */
    private $_columns = [];

    /**
     * The index method used (BTREE, HASH, RTREE).
     *
     * @var string
     */
    private $_type = '';

    /**
     * The index choice (PRIMARY, UNIQUE, INDEX, SPATIAL, FULLTEXT)
     *
     * @var string
     */
    private $_choice = '';

    /**
     * Various remarks.
     *
     * @var string
     */
    private $_remarks = '';

    /**
     * Any comment provided for the index with a COMMENT attribute when the
     * index was created.
     *
     * @var string
     */
    private $_comment = '';

    /** @var int 0 if the index cannot contain duplicates, 1 if it can. */
    private $_non_unique = 0;

    /**
     * Indicates how the key is packed. NULL if it is not.
     *
     * @var string
     */
    private $_packed = null;

    /**
     * Block size for the index
     *
     * @var int
     */
    private $_key_block_size = null;

    /**
     * Parser option for the index
     *
     * @var string
     */
    private $_parser = null;

    /**
     * @param array $params parameters
     */
    public function __construct(array $params = [])
    {
        $this->set($params);
    }

    /**
     * Creates(if not already created) and returns the corresponding Index object
     *
     * @param string $schema     database name
     * @param string $table      table name
     * @param string $index_name index name
     *
     * @return Index corresponding Index object
     */
    public static function singleton($schema, $table, $index_name = '')
    {
        self::_loadIndexes($table, $schema);
        if (! isset(self::$_registry[$schema][$table][$index_name])) {
            $index = new Index();
            if (strlen($index_name) > 0) {
                $index->setName($index_name);
                self::$_registry[$schema][$table][$index->getName()] = $index;
            }
            return $index;
        }

        return self::$_registry[$schema][$table][$index_name];
    }

    /**
     * returns an array with all indexes from the given table
     *
     * @param string $table  table
     * @param string $schema schema
     *
     * @return Index[]  array of indexes
     */
    public static function getFromTable($table, $schema)
    {
        self::_loadIndexes($table, $schema);

        if (isset(self::$_registry[$schema][$table])) {
            return self::$_registry[$schema][$table];
        }

        return [];
    }

    /**
     * Returns an array with all indexes from the given table of the requested types
     *
     * @param string $table   table
     * @param string $schema  schema
     * @param int    $choices choices
     *
     * @return Index[] array of indexes
     */
    public static function getFromTableByChoice($table, $schema, $choices = 31)
    {
        $indexes = [];
        foreach (self::getFromTable($table, $schema) as $index) {
            if (($choices & self::PRIMARY)
                && $index->getChoice() == 'PRIMARY'
            ) {
                $indexes[] = $index;
            }
            if (($choices & self::UNIQUE)
                && $index->getChoice() == 'UNIQUE'
            ) {
                $indexes[] = $index;
            }
            if (($choices & self::INDEX)
                && $index->getChoice() == 'INDEX'
            ) {
                $indexes[] = $index;
            }
            if (($choices & self::SPATIAL)
                && $index->getChoice() == 'SPATIAL'
            ) {
                $indexes[] = $index;
            }
            if (($choices & self::FULLTEXT)
                && $index->getChoice() == 'FULLTEXT'
            ) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    /**
     * return primary if set, false otherwise
     *
     * @param string $table  table
     * @param string $schema schema
     *
     * @return mixed primary index or false if no one exists
     */
    public static function getPrimary($table, $schema)
    {
        self::_loadIndexes($table, $schema);

        if (isset(self::$_registry[$schema][$table]['PRIMARY'])) {
            return self::$_registry[$schema][$table]['PRIMARY'];
        }

        return false;
    }

    /**
     * Load index data for table
     *
     * @param string $table  table
     * @param string $schema schema
     *
     * @return bool whether loading was successful
     */
    private static function _loadIndexes($table, $schema)
    {
        if (isset(self::$_registry[$schema][$table])) {
            return true;
        }

        $_raw_indexes = $GLOBALS['dbi']->getTableIndexes($schema, $table);
        foreach ($_raw_indexes as $_each_index) {
            $_each_index['Schema'] = $schema;
            $keyName = $_each_index['Key_name'];
            if (! isset(self::$_registry[$schema][$table][$keyName])) {
                $key = new Index($_each_index);
                self::$_registry[$schema][$table][$keyName] = $key;
            } else {
                $key = self::$_registry[$schema][$table][$keyName];
            }

            $key->addColumn($_each_index);
        }

        return true;
    }

    /**
     * Add column to index
     *
     * @param array $params column params
     *
     * @return void
     */
    public function addColumn(array $params)
    {
        if (isset($params['Column_name'])
            && strlen($params['Column_name']) > 0
        ) {
            $this->_columns[$params['Column_name']] = new IndexColumn($params);
        }
    }

    /**
     * Adds a list of columns to the index
     *
     * @param array $columns array containing details about the columns
     *
     * @return void
     */
    public function addColumns(array $columns)
    {
        $_columns = [];

        if (isset($columns['names'])) {
            // coming from form
            // $columns[names][]
            // $columns[sub_parts][]
            foreach ($columns['names'] as $key => $name) {
                $sub_part = $columns['sub_parts'][$key] ?? '';
                $_columns[] = [
                    'Column_name'   => $name,
                    'Sub_part'      => $sub_part,
                ];
            }
        } else {
            // coming from SHOW INDEXES
            // $columns[][name]
            // $columns[][sub_part]
            // ...
            $_columns = $columns;
        }

        foreach ($_columns as $column) {
            $this->addColumn($column);
        }
    }

    /**
     * Returns true if $column indexed in this index
     *
     * @param string $column the column
     *
     * @return bool true if $column indexed in this index
     */
    public function hasColumn($column)
    {
        return isset($this->_columns[$column]);
    }

    /**
     * Sets index details
     *
     * @param array $params index details
     *
     * @return void
     */
    public function set(array $params)
    {
        if (isset($params['columns'])) {
            $this->addColumns($params['columns']);
        }
        if (isset($params['Schema'])) {
            $this->_schema = $params['Schema'];
        }
        if (isset($params['Table'])) {
            $this->_table = $params['Table'];
        }
        if (isset($params['Key_name'])) {
            $this->_name = $params['Key_name'];
        }
        if (isset($params['Index_type'])) {
            $this->_type = $params['Index_type'];
        }
        if (isset($params['Comment'])) {
            $this->_remarks = $params['Comment'];
        }
        if (isset($params['Index_comment'])) {
            $this->_comment = $params['Index_comment'];
        }
        if (isset($params['Non_unique'])) {
            $this->_non_unique = $params['Non_unique'];
        }
        if (isset($params['Packed'])) {
            $this->_packed = $params['Packed'];
        }
        if (isset($params['Index_choice'])) {
            $this->_choice = $params['Index_choice'];
        } elseif ($this->_name == 'PRIMARY') {
            $this->_choice = 'PRIMARY';
        } elseif ($this->_type == 'FULLTEXT') {
            $this->_choice = 'FULLTEXT';
            $this->_type = '';
        } elseif ($this->_type == 'SPATIAL') {
            $this->_choice = 'SPATIAL';
            $this->_type = '';
        } elseif ($this->_non_unique == '0') {
            $this->_choice = 'UNIQUE';
        } else {
            $this->_choice = 'INDEX';
        }
        if (isset($params['Key_block_size'])) {
            $this->_key_block_size = $params['Key_block_size'];
        }
        if (isset($params['Parser'])) {
            $this->_parser = $params['Parser'];
        }
    }

    /**
     * Returns the number of columns of the index
     *
     * @return int the number of the columns
     */
    public function getColumnCount()
    {
        return count($this->_columns);
    }

    /**
     * Returns the index comment
     *
     * @return string index comment
     */
    public function getComment()
    {
        return $this->_comment;
    }

    /**
     * Returns index remarks
     *
     * @return string index remarks
     */
    public function getRemarks()
    {
        return $this->_remarks;
    }

    /**
     * Return the key block size
     *
     * @return int
     */
    public function getKeyBlockSize()
    {
        return $this->_key_block_size;
    }

    /**
     * Return the parser
     *
     * @return string
     */
    public function getParser()
    {
        return $this->_parser;
    }

    /**
     * Returns concatenated remarks and comment
     *
     * @return string concatenated remarks and comment
     */
    public function getComments()
    {
        $comments = $this->getRemarks();
        if (strlen($comments) > 0) {
            $comments .= "\n";
        }
        $comments .= $this->getComment();

        return $comments;
    }

    /**
     * Returns index type (BTREE, HASH, RTREE)
     *
     * @return string index type
     */
    public function getType()
    {
        return $this->_type;
    }

    /**
     * Returns index choice (PRIMARY, UNIQUE, INDEX, SPATIAL, FULLTEXT)
     *
     * @return string index choice
     */
    public function getChoice()
    {
        return $this->_choice;
    }

    /**
     * Returns a lit of all index types
     *
     * @return string[] index types
     */
    public static function getIndexTypes()
    {
        return [
            'BTREE',
            'HASH',
        ];
    }

    public function hasPrimary(): bool
    {
        return (bool) self::getPrimary($this->_table, $this->_schema);
    }

    /**
     * Returns how the index is packed
     *
     * @return string how the index is packed
     */
    public function getPacked()
    {
        return $this->_packed;
    }

    /**
     * Returns 'No' if the index is not packed,
     * how the index is packed if packed
     *
     * @return string
     */
    public function isPacked()
    {
        if ($this->_packed === null) {
            return __('No');
        }

        return htmlspecialchars($this->_packed);
    }

    /**
     * Returns integer 0 if the index cannot contain duplicates, 1 if it can
     *
     * @return int 0 if the index cannot contain duplicates, 1 if it can
     */
    public function getNonUnique()
    {
        return $this->_non_unique;
    }

    /**
     * Returns whether the index is a 'Unique' index
     *
     * @param bool $as_text whether to output should be in text
     *
     * @return mixed whether the index is a 'Unique' index
     */
    public function isUnique($as_text = false)
    {
        if ($as_text) {
            $r = [
                '0' => __('Yes'),
                '1' => __('No'),
            ];
        } else {
            $r = [
                '0' => true,
                '1' => false,
            ];
        }

        return $r[$this->_non_unique];
    }

    /**
     * Returns the name of the index
     *
     * @return string the name of the index
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * Sets the name of the index
     *
     * @param string $name index name
     *
     * @return void
     */
    public function setName($name)
    {
        $this->_name = (string) $name;
    }

    /**
     * Returns the columns of the index
     *
     * @return IndexColumn[] the columns of the index
     */
    public function getColumns()
    {
        return $this->_columns;
    }

    /**
     * Show index data
     *
     * @param string $table  The table name
     * @param string $schema The schema name
     *
     * @return string HTML for showing index
     *
     * @access public
     */
    public static function getHtmlForIndexes($table, $schema)
    {
        $indexes = self::getFromTable($table, $schema);

        $no_indexes_class = count($indexes) > 0 ? ' hide' : '';
        $no_indexes  = "<div class='no_indexes_defined" . $no_indexes_class . "'>";
        $no_indexes .= Message::notice(__('No index defined!'))->getDisplay();
        $no_indexes .= '</div>';

        $r  = '<fieldset class="index_info">';
        $r .= '<legend id="index_header">' . __('Indexes');
        $r .= MySQLDocumentation::show('optimizing-database-structure');

        $r .= '</legend>';
        $r .= $no_indexes;

        if (count($indexes) < 1) {
            $r .= '</fieldset>';
            return $r;
        }

        $r .= self::findDuplicates($table, $schema);

        $r .= '<div class="responsivetable jsresponsive">';
        $r .= '<table id="table_index">';
        $r .= '<thead>';
        $r .= '<tr>';

        $r .= '<th colspan="2" class="print_ignore">' . __('Action') . '</th>';

        $r .= '<th>' . __('Keyname') . '</th>';
        $r .= '<th>' . __('Type') . '</th>';
        $r .= '<th>' . __('Unique') . '</th>';
        $r .= '<th>' . __('Packed') . '</th>';
        $r .= '<th>' . __('Column') . '</th>';
        $r .= '<th>' . __('Cardinality') . '</th>';
        $r .= '<th>' . __('Collation') . '</th>';
        $r .= '<th>' . __('Null') . '</th>';
        $r .= '<th>' . __('Comment') . '</th>';
        $r .= '</tr>';
        $r .= '</thead>';

        foreach ($indexes as $index) {
            $row_span = ' rowspan="' . $index->getColumnCount() . '" ';
            $r .= '<tbody class="row_span">';
            $r .= '<tr class="noclick" >';

            $this_params = $GLOBALS['url_params'];
            $this_params['index'] = $index->getName();
            $r .= '<td class="edit_index print_ignore';
            $r .= ' ajax';
            $r .= '" ' . $row_span . '>'
               . '    <a class="';
            $r .= 'ajax';
            $r .= '" href="' . Url::getFromRoute('/table/indexes') . '" data-post="' . Url::getCommon($this_params, '')
               . '">' . Generator::getIcon('b_edit', __('Edit')) . '</a>'
               . '</td>' . "\n";
            $this_params = $GLOBALS['url_params'];

            if ($index->getName() == 'PRIMARY') {
                $this_params['sql_query'] = 'ALTER TABLE '
                    . Util::backquote($table)
                    . ' DROP PRIMARY KEY;';
                $this_params['message_to_show']
                    = __('The primary key has been dropped.');
                $js_msg = Sanitize::jsFormat($this_params['sql_query'], false);
            } else {
                $this_params['sql_query'] = 'ALTER TABLE '
                    . Util::backquote($table) . ' DROP INDEX '
                    . Util::backquote($index->getName()) . ';';
                $this_params['message_to_show'] = sprintf(
                    __('Index %s has been dropped.'),
                    htmlspecialchars($index->getName())
                );
                $js_msg = Sanitize::jsFormat($this_params['sql_query'], false);
            }

            $r .= '<td ' . $row_span . ' class="print_ignore">';
            $r .= '<input type="hidden" class="drop_primary_key_index_msg"'
                . ' value="' . $js_msg . '">';
            $r .= Generator::linkOrButton(
                Url::getFromRoute('/sql', $this_params),
                Generator::getIcon('b_drop', __('Drop')),
                ['class' => 'drop_primary_key_index_anchor ajax']
            );
            $r .= '</td>' . "\n";

            $r .= '<th ' . $row_span . '>'
                . htmlspecialchars($index->getName())
                . '</th>';

            $r .= '<td ' . $row_span . '>';
            $type = $index->getType();
            if (! empty($type)) {
                $r .= htmlspecialchars($type);
            } else {
                $r .= htmlspecialchars($index->getChoice());
            }
            $r .= '</td>';
            $r .= '<td ' . $row_span . '>' . $index->isUnique(true) . '</td>';
            $r .= '<td ' . $row_span . '>' . $index->isPacked() . '</td>';

            foreach ($index->getColumns() as $column) {
                if ($column->getSeqInIndex() > 1) {
                    $r .= '<tr class="noclick" >';
                }
                $r .= '<td>' . htmlspecialchars($column->getName());
                if ($column->getSubPart()) {
                    $r .= ' (' . htmlspecialchars($column->getSubPart()) . ')';
                }
                $r .= '</td>';
                $r .= '<td>'
                    . htmlspecialchars((string) $column->getCardinality())
                    . '</td>';
                $r .= '<td>'
                    . htmlspecialchars((string) $column->getCollation())
                    . '</td>';
                $r .= '<td>'
                    . htmlspecialchars($column->getNull(true))
                    . '</td>';

                if ($column->getSeqInIndex() == 1
                ) {
                    $r .= '<td ' . $row_span . '>'
                        . htmlspecialchars($index->getComments()) . '</td>';
                }
                $r .= '</tr>';
            } // end foreach $index['Sequences']
            $r .= '</tbody>';
        } // end while
        $r .= '</table>';
        $r .= '</div>';

        $r .= '</fieldset>';

        return $r;
    }

    /**
     * Gets the properties in an array for comparison purposes
     *
     * @return array an array containing the properties of the index
     */
    public function getCompareData()
    {
        $data = [
            // 'Non_unique'    => $this->_non_unique,
            'Packed'        => $this->_packed,
            'Index_choice'    => $this->_choice,
        ];

        foreach ($this->_columns as $column) {
            $data['columns'][] = $column->getCompareData();
        }

        return $data;
    }

    /**
     * Function to check over array of indexes and look for common problems
     *
     * @param string $table  table name
     * @param string $schema schema name
     *
     * @return string  Output HTML
     *
     * @access public
     */
    public static function findDuplicates($table, $schema)
    {
        $indexes = self::getFromTable($table, $schema);

        $output  = '';

        // count($indexes) < 2:
        //   there is no need to check if there less than two indexes
        if (count($indexes) < 2) {
            return $output;
        }

        // remove last index from stack and ...
        while ($while_index = array_pop($indexes)) {
            // ... compare with every remaining index in stack
            foreach ($indexes as $each_index) {
                if ($each_index->getCompareData() !== $while_index->getCompareData()
                ) {
                    continue;
                }

                // did not find any difference
                // so it makes no sense to have this two equal indexes

                $message = Message::notice(
                    __(
                        'The indexes %1$s and %2$s seem to be equal and one of them '
                        . 'could possibly be removed.'
                    )
                );
                $message->addParam($each_index->getName());
                $message->addParam($while_index->getName());
                $output .= $message->getDisplay();

                // there is no need to check any further indexes if we have already
                // found that this one has a duplicate
                continue 2;
            }
        }
        return $output;
    }
}
