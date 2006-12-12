<?php
/* $Id$ */
// vim: expandtab sw=4 ts=4 sts=4:

/**
 * Gets the variables sent or posted to this script and displays the header
 */
require_once './libraries/common.lib.php';

/**
 * Sets global variables.
 * Here it's better to use a if, instead of the '?' operator
 * to avoid setting a variable to '' when it's not present in $_REQUEST
 */
if (isset($_REQUEST['pos'])) {
    $pos =  $_REQUEST['pos'];
}
if (isset($_REQUEST['session_max_rows'])) {
    $session_max_rows = $_REQUEST['session_max_rows'];
}
if (isset($_REQUEST['disp_direction'])) {
    $disp_direction = $_REQUEST['disp_direction'];
}
if (isset($_REQUEST['repeat_cells'])) {
    $repeat_cells = $_REQUEST['repeat_cells'];
}
if (isset($_REQUEST['dontlimitchars'])) {
    $dontlimitchars = $_REQUEST['dontlimitchars'];
}
if (isset($_REQUEST['primary_key'])) {
    $primary_key = $_REQUEST['primary_key'];
}
if (isset($_REQUEST['sql_query'])) {
    $sql_query = $_REQUEST['sql_query'];
}
if (isset($_REQUEST['ShowFunctionFields'])) {
    $cfg['ShowFunctionFields'] = $_REQUEST['ShowFunctionFields'];
}


$js_to_run = 'tbl_change.js';
require_once './libraries/header.inc.php';
require_once './libraries/relation.lib.php'; // foreign keys
require_once './libraries/file_listing.php'; // file listing


/**
 * Displays the query submitted and its result
 */
if (!empty($disp_message)) {
    if (isset($goto)) {
        $goto_cpy      = $goto;
        $goto          = 'tbl_sql.php?'
                       . PMA_generate_common_url($db, $table)
                       . '&amp;$show_query=1'
                       . '&amp;sql_query=' . (isset($disp_query) ? urlencode($disp_query) : '');
    } else {
        $show_query = '1';
    }
    if (isset($sql_query)) {
        $sql_query_cpy = $sql_query;
        unset($sql_query);
    }
    if (isset($disp_query)) {
        $sql_query     = $disp_query;
    }
    PMA_showMessage($disp_message);
    if (isset($goto_cpy)) {
        $goto          = $goto_cpy;
        unset($goto_cpy);
    }
    if (isset($sql_query_cpy)) {
        $sql_query     = $sql_query_cpy;
        unset($sql_query_cpy);
    }
}


/**
 * Defines the url to return to in case of error in a sql statement
 * (at this point, $goto might be set but empty)
 */
if (empty($goto)) {
    $goto    = 'db_sql.php';
}
/**
 * @todo check if we could replace by "db_|tbl_"
 */
if (!preg_match('@^(db|tbl)_@', $goto)) {
    $err_url = $goto . "?" . PMA_generate_common_url($db) . "&amp;sql_query=" . urlencode($sql_query);
} else {
    $err_url = $goto . '?'
             . PMA_generate_common_url($db)
             . ((preg_match('@^(tbl_)@', $goto)) ? '&amp;table=' . urlencode($table) : '');
}


/**
 * Ensures db and table are valid, else moves to the "parent" script
 */
require_once './libraries/db_table_exists.lib.php';


/**
 * Sets parameters for links
 */
$url_query = PMA_generate_common_url($db, $table)
           . '&amp;goto=tbl_sql.php';

require_once './libraries/tbl_info.inc.php';

/* Get comments */

$comments_map = array();

if ($GLOBALS['cfg']['ShowPropertyComments']) {
    require_once './libraries/relation.lib.php';
    require_once './libraries/transformations.lib.php';

    $cfgRelation = PMA_getRelationsParam();

    if ($cfgRelation['commwork'] || PMA_MYSQL_INT_VERSION >= 40100) {
        $comments_map = PMA_getComments($db, $table);
    }
}

/**
 * Displays top menu links
 */
require_once './libraries/tbl_links.inc.php';


/**
 * Get the analysis of SHOW CREATE TABLE for this table
 */
$show_create_table = PMA_DBI_fetch_value(
        'SHOW CREATE TABLE ' . PMA_backquote($db) . '.' . PMA_backquote($table),
        0, 1 );
$analyzed_sql = PMA_SQP_analyze( PMA_SQP_parse( $show_create_table ) );
unset($show_create_table);

/**
 * Get the list of the fields of the current table
 */
PMA_DBI_select_db($db);
$table_def = PMA_DBI_query('SHOW FIELDS FROM ' . PMA_backquote($table) . ';', null, PMA_DBI_QUERY_STORE);
if (isset($primary_key)) {
    if (is_array($primary_key)) {
        $primary_key_array = $primary_key;
    } else {
        $primary_key_array = array(0 => $primary_key);
    }

    $row = array();
    $result = array();
    foreach ($primary_key_array AS $rowcount => $primary_key) {
        $local_query             = 'SELECT * FROM ' . PMA_backquote($table) . ' WHERE ' . $primary_key . ';';
        $result[$rowcount]       = PMA_DBI_query($local_query, null, PMA_DBI_QUERY_STORE);
        $row[$rowcount]          = PMA_DBI_fetch_assoc($result[$rowcount]);
        $primary_keys[$rowcount] = $primary_key;

        // No row returned
        if (!$row[$rowcount]) {
            unset($row[$rowcount]);
            unset($primary_key_array[$rowcount]);
            $goto_cpy          = $goto;
            $goto              = 'tbl_sql.php?'
                               . PMA_generate_common_url($db, $table)
                               . '&amp;$show_query=1'
                               . '&amp;sql_query=' . urlencode($local_query);
            if (isset($sql_query)) {
                $sql_query_cpy = $sql_query;
                unset($sql_query);
            }
            $sql_query         = $local_query;
            PMA_showMessage($strEmptyResultSet);
            $goto              = $goto_cpy;
            unset($goto_cpy);
            if (isset($sql_query_cpy)) {
                $sql_query    = $sql_query_cpy;
                unset($sql_query_cpy);
            }
            echo "\n";
            require_once './libraries/footer.inc.php';
        } // end if (no record returned)
    }
} else {
    $result = PMA_DBI_query('SELECT * FROM ' . PMA_backquote($table) . ' LIMIT 1;', null, PMA_DBI_QUERY_STORE);
    unset($row);
}

// <markus@noga.de>
// retrieve keys into foreign fields, if any
$cfgRelation = PMA_getRelationsParam();
$foreigners  = ($cfgRelation['relwork'] ? PMA_getForeigners($db, $table) : FALSE);


/**
 * Displays the form
 */
// loic1: autocomplete feature of IE kills the "onchange" event handler and it
//        must be replaced by the "onpropertychange" one in this case
$chg_evt_handler = (PMA_USR_BROWSER_AGENT == 'IE' && PMA_USR_BROWSER_VER >= 5)
                 ? 'onpropertychange'
                 : 'onchange';
// Had to put the URI because when hosted on an https server,
// some browsers send wrongly this form to the http server.
?>

<?php if ($cfg['CtrlArrowsMoving']) { ?>
<!-- Set on key handler for moving using by Ctrl+arrows -->
<script src="./js/keyhandler.js" type="text/javascript" language="javascript"></script>
<script type="text/javascript" language="javascript">
//<![CDATA[
var switch_movement = 0;
document.onkeydown = onKeyDownArrowsHandler;
//]]>
</script>
<?php } ?>

<!-- Change table properties form -->
<form method="post" action="tbl_replace.php" name="insertForm" <?php if ($is_upload) { echo ' enctype="multipart/form-data"'; } ?>>
    <?php echo PMA_generate_common_hidden_inputs($db, $table); ?>
    <input type="hidden" name="goto" value="<?php echo urlencode($goto); ?>" />
    <input type="hidden" name="pos" value="<?php echo isset($pos) ? $pos : 0; ?>" />
    <input type="hidden" name="session_max_rows" value="<?php echo isset($session_max_rows) ? $session_max_rows : ''; ?>" />
    <input type="hidden" name="disp_direction" value="<?php echo isset($disp_direction) ? $disp_direction : ''; ?>" />
    <input type="hidden" name="repeat_cells" value="<?php echo isset($repeat_cells) ? $repeat_cells : ''; ?>" />
    <input type="hidden" name="dontlimitchars" value="<?php echo (isset($dontlimitchars) ? $dontlimitchars : 0); ?>" />
    <input type="hidden" name="err_url" value="<?php echo urlencode($err_url); ?>" />
    <input type="hidden" name="sql_query" value="<?php echo isset($sql_query) ? urlencode($sql_query) : ''; ?>" />
<?php
if (isset($primary_key_array)) {
    foreach ($primary_key_array AS $primary_key) {
        ?>
    <input type="hidden" name="primary_key[]" value="<?php echo urlencode($primary_key); ?>" />
<?php
    }
}
echo "\n";

if ($cfg['PropertiesIconic'] == true) {
    // We need to copy the value or else the == 'both' check will always return true
    $propicon = (string)$cfg['PropertiesIconic'];

    if ($propicon == 'both') {
        $iconic_spacer = '<div class="nowrap">';
    } else {
        $iconic_spacer = '';
    }

    $titles['Browse']     = $iconic_spacer . '<img width="16" height="16" src="' . $pmaThemeImage . 'b_browse.png" alt="' . $strBrowseForeignValues . '" title="' . $strBrowseForeignValues . '" border="0" />';

    if ($propicon == 'both') {
        $titles['Browse']        .= '&nbsp;' . $strBrowseForeignValues . '</div>';
    }
} else {
    $titles['Browse']        = $strBrowseForeignValues;
}

// Set if we passed the first timestamp field
$timestamp_seen = 0;
$fields_cnt     = PMA_DBI_num_rows($table_def);

// Set a flag here because the 'if' would not be valid in the loop
// if we set a value in some field
$insert_mode = (!isset($row) ? TRUE : FALSE);
if ($insert_mode) {
    $loop_array  = array();
    for ($i = 0; $i < $cfg['InsertRows']; $i++) {
        $loop_array[] = FALSE;
    }
} else {
    $loop_array  = $row;
}

while ($trow = PMA_DBI_fetch_assoc($table_def)) {
    $trow_table_def[] = $trow;
}

$tabindex = 0;
$tabindex_for_function = +1000;
$tabindex_for_null     = +2000;
$tabindex_for_value    = 0;
$o_rows   = 0;
$biggest_max_file_size = 0;

// user can toggle the display of Function column
// (currently does not work for multi-edits)
$url_params['db'] = $db;
$url_params['table'] = $table;
if (isset($primary_key)) {
    $url_params['primary_key'] = $primary_key;
}
if (isset($sql_query)) {
    $url_params['sql_query'] = $sql_query;
}

if (! $cfg['ShowFunctionFields']) {
    $this_url_params = array_merge($url_params,
        array('ShowFunctionFields' => 1));
    echo $strShow . ' : <a href="tbl_change.php' . PMA_generate_common_url($this_url_params) . '">' . $strFunction . '</a>' . "\n";
}

foreach ($loop_array AS $vrowcount => $vrow) {
    if ($vrow === FALSE) {
        unset($vrow);
    }

    if ($insert_mode) {
        $jsvkey = $vrowcount;
        $browse_foreigners_uri = '&amp;pk=' . $vrowcount;
    } else {
        $jsvkey = urlencode($primary_keys[$vrowcount]);
        $browse_foreigners_uri = '&amp;pk=' . urlencode($primary_keys[$vrowcount]);
    }
    $vkey = '[multi_edit][' . $jsvkey . ']';

    $vresult = (isset($result) && is_array($result) && isset($result[$vrowcount]) ? $result[$vrowcount] : $result);
    if ($insert_mode && $vrowcount > 0) {
        echo '<input type="checkbox" checked="checked" name="insert_ignore_' . $vrowcount . '" id="insert_ignore_check_' . $vrowcount . '" />';
        echo '<label for="insert_ignore_check_' . $vrowcount . '">' . $strIgnore . '</label><br />' . "\n";
    }
?>
    <table>
        <tr>
            <th><?php echo $strField; ?></th>
            <th><?php echo $strType; ?></th>
<?php
    if ($cfg['ShowFunctionFields']) {
        $this_url_params = array_merge($url_params,
            array('ShowFunctionFields' => 0));
        echo '          <th><a href="tbl_change.php' . PMA_generate_common_url($this_url_params) . '" title="' . $strHide . '">' . $strFunction . '</a></th>' . "\n";
    }
?>
            <th><?php echo $strNull; ?></th>
            <th><?php echo $strValue; ?></th>
        </tr>
<?php

    // garvin: For looping on multiple rows, we need to reset any variable used inside the loop to indicate sth.
    $timestamp_seen = 0;
    unset($first_timestamp);

    // Sets a multiplier used for input-field counts (as zero cannot be used, advance the counter plus one)
    $m_rows = $o_rows + 1;

    $odd_row = true;
    for ($i = 0; $i < $fields_cnt; $i++) {
        // Display the submit button after every 15 lines --swix
        // (wanted to use an <a href="#bottom"> and <a name> instead,
        // but it didn't worked because of the <base href>)

        if ((($o_rows * $fields_cnt + $i) % 15 == 0) && ($i + $o_rows != 0)) {
            ?>
        <tr>
            <th colspan="5" align="right" class="tblFooters">
                <input type="submit" value="<?php echo $strGo; ?>" />&nbsp;
            </th>
        </tr>
            <?php
        } // end if
        echo "\n";

        $row_table_def   = $trow_table_def[$i];
        $row_table_def['True_Type'] = preg_replace('@\(.*@s', '', $row_table_def['Type']);

        $field           = $row_table_def['Field'];

        // removed previous PHP3-workaround that caused a problem with
        // field names like '000'
        $rowfield = $field;

        // d a t e t i m e
        //
        // loic1: current date should not be set as default if the field is NULL
        //        for the current row
        // lem9:  but do not put here the current datetime if there is a default
        //        value (the real default value will be set in the
        //        Default value logic below)

        // Note: (tested in MySQL 4.0.16): when lang is some UTF-8,
        // $row_table_def['Default'] is not set if it contains NULL:
        // Array ( [Field] => d [Type] => datetime [Null] => YES [Key] => [Extra] => [True_Type] => datetime )
        // but, look what we get if we switch to iso: (Default is NULL)
        // Array ( [Field] => d [Type] => datetime [Null] => YES [Key] => [Default] => [Extra] => [True_Type] => datetime )
        // so I force a NULL into it (I don't think it's possible
        // to have an empty default value for DATETIME)
        // then, the "if" after this one will work
        if ($row_table_def['Type'] == 'datetime'
            && !isset($row_table_def['Default'])
            && isset($row_table_def['Null'])
            && $row_table_def['Null'] == 'YES') {
            $row_table_def['Default'] = null;
        }

        if ($row_table_def['Type'] == 'datetime'
            && (!isset($row_table_def['Default']))
            && (!is_null($row_table_def['Default']))) {
            // INSERT case
            if ($insert_mode) {
                if (isset($vrow)) {
                    $vrow[$rowfield] = date('Y-m-d H:i:s', time());
                } else {
                    $vrow = array($rowfield => date('Y-m-d H:i:s', time()));
                }
            }
            // UPDATE case with an empty and not NULL value under PHP4
            elseif (empty($vrow[$rowfield]) && is_null($vrow[$rowfield])) {
                $vrow[$rowfield] = date('Y-m-d H:i:s', time());
            } // end if... elseif...
        }
        $len             = (preg_match('@float|double@', $row_table_def['Type']))
                         ? 100
                         : PMA_DBI_field_len($vresult, $i);
        $first_timestamp = 0;

        $field_name = htmlspecialchars($field);
        if (isset($comments_map[$field])) {
            $field_name = '<span style="border-bottom: 1px dashed black;" title="' . htmlspecialchars($comments_map[$field]) . '">' . $field_name . '</span>';
        }

        ?>
        <tr class="<?php echo $odd_row ? 'odd' : 'even'; ?>">
            <td <?php echo ($cfg['LongtextDoubleTextarea'] && strstr($row_table_def['True_Type'], 'longtext') ? 'rowspan="2"' : ''); ?> align="center"><?php echo $field_name; ?></td>

        <?php
        // The type column
        $is_binary                  = stristr($row_table_def['Type'], 'binary');
        $is_blob                    = stristr($row_table_def['Type'], 'blob');
        $is_char                    = stristr($row_table_def['Type'], 'char');
        switch ($row_table_def['True_Type']) {
            case 'set':
                $type         = 'set';
                $type_nowrap  = '';
                break;
            case 'enum':
                $type         = 'enum';
                $type_nowrap  = '';
                break;
            case 'timestamp':
                if (!$timestamp_seen) {   // can only occur once per table
                    $timestamp_seen  = 1;
                    $first_timestamp = 1;
                }
                $type         = $row_table_def['Type'];
                $type_nowrap  = ' nowrap="nowrap"';
                break;

            default:
                $type         = $row_table_def['Type'];
                $type_nowrap  = ' nowrap="nowrap"';
                break;
        }
        ?>
            <td align="center"<?php echo $type_nowrap; ?>>
                <?php echo $type; ?>
            </td>

        <?php

        // Prepares the field value
        $real_null_value = FALSE;
        if (isset($vrow)) {
            if (!isset($vrow[$rowfield])
              || (function_exists('is_null') && is_null($vrow[$rowfield]))) {
                $real_null_value = TRUE;
                $vrow[$rowfield]   = '';
                $special_chars = '';
                $data          = $vrow[$rowfield];
            } else {
                // loic1: special binary "characters"
                if ($is_binary || $is_blob) {
                    $vrow[$rowfield] = str_replace("\x00", '\0', $vrow[$rowfield]);
                    $vrow[$rowfield] = str_replace("\x08", '\b', $vrow[$rowfield]);
                    $vrow[$rowfield] = str_replace("\x0a", '\n', $vrow[$rowfield]);
                    $vrow[$rowfield] = str_replace("\x0d", '\r', $vrow[$rowfield]);
                    $vrow[$rowfield] = str_replace("\x1a", '\Z', $vrow[$rowfield]);
                } // end if
                $special_chars   = htmlspecialchars($vrow[$rowfield]);
                $data            = $vrow[$rowfield];
            } // end if... else...
            // loic1: if a timestamp field value is not included in an update
            //        statement MySQL auto-update it to the current timestamp
            // lem9:  however, things have changed since MySQL 4.1, so
            //        it's better to set a fields_prev in this situation
            $backup_field  = (PMA_MYSQL_INT_VERSION < 40100 && $row_table_def['True_Type'] == 'timestamp')
                           ? ''
                           : '<input type="hidden" name="fields_prev' . $vkey . '[' . urlencode($field) . ']" value="' . urlencode($vrow[$rowfield]) . '" />';
        } else {
            // loic1: display default values
            if (!isset($row_table_def['Default'])) {
                $row_table_def['Default'] = '';
                $real_null_value          = TRUE;
                $data                     = '';
            } else {
                $data                     = $row_table_def['Default'];
            }
            $special_chars = htmlspecialchars($row_table_def['Default']);
            $backup_field  = '';
        }

        $idindex  = ($o_rows * $fields_cnt) + $i + 1;
        $tabindex = (($idindex - 1) * 3) + 1;

        // The function column
        // -------------------
        // Change by Bernard M. Piller <bernard@bmpsystems.com>
        // We don't want binary data to be destroyed
        // Note: from the MySQL manual: "BINARY doesn't affect how the column is
        //       stored or retrieved" so it does not mean that the contents is
        //       binary
        if ($cfg['ShowFunctionFields']) {
            if (($cfg['ProtectBinary'] && $is_blob && !$is_upload)
                || ($cfg['ProtectBinary'] == 'all' && $is_binary)) {
                echo '        <td align="center">' . $strBinary . '</td>' . "\n";
            } elseif (strstr($row_table_def['True_Type'], 'enum') || strstr($row_table_def['True_Type'], 'set')) {
                echo '        <td align="center">--</td>' . "\n";
            } else {
                ?>
            <td>
                <select name="funcs<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_function); ?>" id="field_<?php echo $idindex; ?>_1">
                    <option></option>
                <?php
                $selected     = '';

                // garvin: Find the current type in the RestrictColumnTypes. Will result in 'FUNC_CHAR'
                // or something similar. Then directly look up the entry in the RestrictFunctions array,
                // which will then reveal the available dropdown options
                if (isset($cfg['RestrictFunctions']) && isset($cfg['RestrictColumnTypes']) && isset($cfg['RestrictColumnTypes'][strtoupper($row_table_def['True_Type'])]) && isset($cfg['RestrictFunctions'][$cfg['RestrictColumnTypes'][strtoupper($row_table_def['True_Type'])]])) {
                    $current_func_type  = $cfg['RestrictColumnTypes'][strtoupper($row_table_def['True_Type'])];
                    $dropdown           = $cfg['RestrictFunctions'][$current_func_type];
                    $default_function   = $cfg['DefaultFunctions'][$current_func_type];
                } else {
                    $dropdown = array();
                    $default_function   = '';
                }

                $dropdown_built = array();
                $op_spacing_needed = FALSE;
                // garvin: loop on the dropdown array and print all available options for that field.
                $cnt_dropdown = count($dropdown);
                for ($j = 0; $j < $cnt_dropdown; $j++) {
                    // Is current function defined as default?
                    // For MySQL < 4.1.2, for the first timestamp we set as
                    // default function the one defined in config (which
                    // should be NOW() ).
                    // For MySQL >= 4.1.2, we don't set the default function
                    // if there is a default value for the timestamp
                    // (not including CURRENT_TIMESTAMP)
                    // and the column does not have the
                    // ON UPDATE DEFAULT TIMESTAMP attribute.

                    if (PMA_MYSQL_INT_VERSION < 40102
                    || (PMA_MYSQL_INT_VERSION >= 40102
                       && !($row_table_def['True_Type'] == 'timestamp' && !empty($row_table_def['Default']) &&  !isset($analyzed_sql[0]['create_table_fields'][$field]['on_update_current_timestamp'])))) {
                    $selected = ($first_timestamp && $dropdown[$j] == $cfg['DefaultFunctions']['first_timestamp'])
                                || (!$first_timestamp && $dropdown[$j] == $default_function)
                              ? ' selected="selected"'
                              : '';
                }
                    echo '                ';
                    echo '<option' . $selected . '>' . $dropdown[$j] . '</option>' . "\n";
                    $dropdown_built[$dropdown[$j]] = 'TRUE';
                    $op_spacing_needed = TRUE;
                }

                // garvin: For compatibility's sake, do not let out all other functions. Instead
                // print a separator (blank) and then show ALL functions which weren't shown
                // yet.
                $cnt_functions = count($cfg['Functions']);
                for ($j = 0; $j < $cnt_functions; $j++) {
                    if (!isset($dropdown_built[$cfg['Functions'][$j]]) || $dropdown_built[$cfg['Functions'][$j]] != 'TRUE') {
                        // Is current function defined as default?
                        $selected = ($first_timestamp && $cfg['Functions'][$j] == $cfg['DefaultFunctions']['first_timestamp'])
                                    || (!$first_timestamp && $cfg['Functions'][$j] == $default_function)
                                  ? ' selected="selected"'
                                  : '';
                        if ($op_spacing_needed == TRUE) {
                            echo '                ';
                            echo '<option value="">--------</option>' . "\n";
                            $op_spacing_needed = FALSE;
                        }

                        echo '                ';
                        echo '<option' . $selected . '>' . $cfg['Functions'][$j] . '</option>' . "\n";
                    }
                } // end for
                unset($selected);
                ?>
                </select>
            </td>
                <?php
            }
        } // end if ($cfg['ShowFunctionFields'])


        // The null column
        // ---------------
        echo '        <td>' . "\n";
        if ($row_table_def['Null'] == 'YES') {
            echo '            <input type="hidden" name="fields_null_prev' . $vkey . '[' . urlencode($field) . ']"';
            if ($real_null_value && !$first_timestamp) {
                echo ' value="on"';
            }
            echo ' />' . "\n";

            if (!(($cfg['ProtectBinary'] && $is_blob) || ($cfg['ProtectBinary'] == 'all' && $is_binary)) ) {

                echo '            <input type="checkbox" tabindex="' . ($tabindex + $tabindex_for_null) . '"'
                     . ' name="fields_null' . $vkey . '[' . urlencode($field) . ']"';
                if ($real_null_value && !$first_timestamp) {
                    echo ' checked="checked"';
                }
                echo ' id="field_' . ($idindex) . '_2"';
                $onclick         = ' onclick="if (this.checked) {nullify(';
                if (strstr($row_table_def['True_Type'], 'enum')) {
                    if (strlen($row_table_def['Type']) > 20) {
                        $onclick .= '1, ';
                    } else {
                        $onclick .= '2, ';
                    }
                } elseif (strstr($row_table_def['True_Type'], 'set')) {
                    $onclick     .= '3, ';
                } elseif ($foreigners && isset($foreigners[$field])) {
                    $onclick     .= '4, ';
                } else {
                    $onclick     .= '5, ';
                }
                $onclick         .= '\'' . urlencode($field) . '\', \'' . md5($field) . '\', \'' . $vkey . '\'); this.checked = true}; return true" />' . "\n";
                echo $onclick;
            } else {
                echo '            <input type="hidden" name="fields_null' . $vkey . '[' . urlencode($field) . ']"';
                if ($real_null_value && !$first_timestamp) {
                    echo ' value="on"';
                }
                echo ' />' . "\n";
            }
        }
        echo '        </td>' . "\n";

        // The value column (depends on type)
        // ----------------

        require './libraries/get_foreign.lib.php';

        if (isset($foreign_link) && $foreign_link == true) {
            ?>
            <td>
            <?php echo $backup_field . "\n"; ?>
            <input type="hidden" name="fields_type<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="foreign" />
            <input type="hidden" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="" id="field_<?php echo ($idindex); ?>_1" />
            <input type="text"   name="field_<?php echo md5($field); ?><?php echo $vkey; ?>[]" class="textfield" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" id="field_<?php echo ($idindex); ?>_3" value="<?php echo htmlspecialchars($data); ?>" />
            <script type="text/javascript" language="javascript">
            //<![CDATA[
                document.writeln('<a target="_blank" onclick="window.open(this.href, \'foreigners\', \'width=640,height=240,scrollbars=yes,resizable=yes\'); return false" href="browse_foreigners.php?<?php echo PMA_generate_common_url($db, $table); ?>&amp;field=<?php echo urlencode($field) . $browse_foreigners_uri; ?>"><?php echo str_replace("'", "\'", $titles['Browse']); ?></a>');
            //]]>
            </script>
            </td>
            <?php
        } elseif (isset($disp_row) && is_array($disp_row)) {
            ?>
            <td>
            <?php echo $backup_field . "\n"; ?>
            <input type="hidden" name="fields_type<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="foreign" />
            <input type="hidden" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="" id="field_<?php echo $idindex; ?>_1" />
            <select name="field_<?php echo md5($field); ?><?php echo $vkey; ?>[]" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" id="field_<?php echo ($idindex); ?>_3">
                <?php echo PMA_foreignDropdown($disp_row, $foreign_field, $foreign_display, $data, $cfg['ForeignKeyMaxLimit']); ?>
            </select>
            </td>
            <?php
            unset($disp_row);
        } elseif ($cfg['LongtextDoubleTextarea'] && strstr($type, 'longtext')) {
            ?>
            <td>&nbsp;</td>
        </tr>
        <tr class="<?php echo $odd_row ? 'odd' : 'even'; ?>">
            <td colspan="5" align="right">
                <?php echo $backup_field . "\n"; ?>
                <textarea name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" rows="<?php echo ($cfg['TextareaRows']*2); ?>" cols="<?php echo ($cfg['TextareaCols']*2); ?>" dir="<?php echo $text_dir; ?>" id="field_<?php echo ($idindex); ?>_3"
                    <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>"><?php echo $special_chars; ?></textarea>
            </td>
          <?php
        } elseif (strstr($type, 'text')) {
            ?>
            <td>
                <?php echo $backup_field . "\n"; ?>
                <textarea name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" rows="<?php echo $cfg['TextareaRows']; ?>" cols="<?php echo $cfg['TextareaCols']; ?>" dir="<?php echo $text_dir; ?>" id="field_<?php echo ($idindex); ?>_3"
                    <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>"><?php echo $special_chars; ?></textarea>
            </td>
            <?php
            echo "\n";
            if (strlen($special_chars) > 32000) {
                echo '        <td>' . $strTextAreaLength . '</td>' . "\n";
            }
        } elseif ($type == 'enum') {
            $enum        = PMA_getEnumSetOptions($row_table_def['Type']);
            $enum_cnt    = count($enum);
            ?>
            <td>
                <input type="hidden" name="fields_type<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="enum" />
                <input type="hidden" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="" />
            <?php
            echo "\n" . '            ' . $backup_field;

            // show dropdown or radio depend on length
            if (strlen($row_table_def['Type']) > 20) {
                echo "\n";
                ?>
                <select name="field_<?php echo md5($field); ?><?php echo $vkey; ?>[]" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" id="field_<?php echo ($idindex); ?>_3">
                    <option value=""></option>
                <?php
                echo "\n";

                for ($j = 0; $j < $enum_cnt; $j++) {
                    // Removes automatic MySQL escape format
                    $enum_atom = str_replace('\'\'', '\'', str_replace('\\\\', '\\', $enum[$j]));
                    echo '                ';
                    //echo '<option value="' . htmlspecialchars($enum_atom) . '"';
                    echo '<option value="' . urlencode($enum_atom) . '"';
                    if ($data == $enum_atom
                        || ($data == '' && (!isset($primary_key) || $row_table_def['Null'] != 'YES')
                            && isset($row_table_def['Default']) && $enum_atom == $row_table_def['Default'])) {
                        echo ' selected="selected"';
                    }
                    echo '>' . htmlspecialchars($enum_atom) . '</option>' . "\n";
                } // end for

                ?>
                </select>
                <?php
            } else {
                echo "\n";
                for ($j = 0; $j < $enum_cnt; $j++) {
                    // Removes automatic MySQL escape format
                    $enum_atom = str_replace('\'\'', '\'', str_replace('\\\\', '\\', $enum[$j]));
                    echo '            ';
                    echo '<input type="radio" name="field_' . md5($field) . $vkey . '[]" value="' . urlencode($enum_atom) . '" id="field_' . ($idindex) . '_3_'  . $j . '" onclick="if (typeof(document.forms[\'insertForm\'].elements[\'fields_null' . str_replace('"', '\"', $vkey) . '[' . urlencode($field) . ']\']) != \'undefined\') {document.forms[\'insertForm\'].elements[\'fields_null' . str_replace('"', '\"', $vkey) . '[' . urlencode($field) .']\'].checked = false}"';
                    if ($data == $enum_atom
                        || ($data == '' && (!isset($primary_key) || $row_table_def['Null'] != 'YES')
                            && isset($row_table_def['Default']) && $enum_atom == $row_table_def['Default'])) {
                        echo ' checked="checked"';
                    }
                    echo 'tabindex="' . ($tabindex + $tabindex_for_value) . '" />';
                    echo '<label for="field_' . $idindex . '_3_' . $j . '">' . htmlspecialchars($enum_atom) . '</label>' . "\n";
                } // end for

            } // end else
            echo "\n";
            ?>
            </td>
            <?php
            echo "\n";
        } elseif ($type == 'set') {
            $set = PMA_getEnumSetOptions($row_table_def['Type']);

            if (isset($vset)) {
                unset($vset);
            }
            for ($vals = explode(',', $data); list($t, $k) = each($vals);) {
                $vset[$k] = 1;
            }
            $countset = count($set);
            $size = min(4, $countset);
            ?>
            <td>
                <?php echo $backup_field . "\n"; ?>
                <input type="hidden" name="fields_type<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="set" />
                <input type="hidden" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="" />
                <select name="field_<?php echo md5($field); ?><?php echo $vkey; ?>[]" size="<?php echo $size; ?>" multiple="multiple" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" id="field_<?php echo ($idindex); ?>_3">
            <?php
            echo "\n";
            for ($j = 0; $j < $countset; $j++) {
                echo '                ';
                //echo '<option value="'. htmlspecialchars($set[$j]) . '"';
                echo '<option value="'. urlencode($set[$j]) . '"';
                if (isset($vset[$set[$j]]) && $vset[$set[$j]]) {
                    echo ' selected="selected"';
                }
                echo '>' . htmlspecialchars($set[$j]) . '</option>' . "\n";
            } // end for
            ?>
                </select>
            </td>
            <?php
        }
        // Change by Bernard M. Piller <bernard@bmpsystems.com>
        // We don't want binary data destroyed
        elseif ($is_binary || $is_blob) {
            if (($cfg['ProtectBinary'] && $is_blob)
                || ($cfg['ProtectBinary'] == 'all' && $is_binary)) {
                echo "\n";
                ?>
            <td>
                <?php
                    echo $strBinaryDoNotEdit;
                    if (isset($data)) {
                        $data_size = PMA_formatByteDown(strlen(stripslashes($data)), 3, 1);
                        echo ' ('. $data_size [0] . ' ' . $data_size[1] . ')';
                        unset($data_size);
                    }
                    echo "\n";
                ?>
                <input type="hidden" name="fields_type<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="protected" />
                <input type="hidden" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="" />
                <?php
            } elseif ($is_blob) {
                echo "\n";
                ?>
            <td>
                <?php echo $backup_field . "\n"; ?>
                <textarea name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" rows="<?php echo $cfg['TextareaRows']; ?>" cols="<?php echo $cfg['TextareaCols']; ?>" dir="<?php echo $text_dir; ?>" id="field_<?php echo ($idindex); ?>_3"
                    <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" ><?php echo $special_chars; ?></textarea>
                <?php

            } else {
                if ($len < 4) {
                    $fieldsize = $maxlength = 4;
                } else {
                    $fieldsize = (($len > 40) ? 40 : $len);
                    $maxlength = $len;
                }
                echo "\n";
                ?>
            <td>
                <?php echo $backup_field . "\n"; ?>
                <input type="text" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="<?php echo $special_chars; ?>" size="<?php echo $fieldsize; ?>" maxlength="<?php echo $maxlength; ?>" class="textfield" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" id="field_<?php echo ($idindex); ?>_3" />
                <?php
            } // end if...elseif...else

            // Upload choice (only for BLOBs because the binary
            // attribute does not imply binary contents)
            // (displayed whatever value the ProtectBinary has)

            if ($is_upload && $is_blob) {
                echo '<br />';
                echo '<input type="file" name="fields_upload_' . urlencode($field) . $vkey . '" class="textfield" id="field_' . ($idindex) . '_3" size="10" />&nbsp;';

                // find maximum upload size, based on field type
                /**
                 * @todo with functions this is not so easy, as you can basically
                 * process any data with function like MD5
                 */
                $max_field_sizes = array(
                    'tinyblob'   =>        '256',
                    'blob'       =>      '65536',
                    'mediumblob' =>   '16777216',
                    'longblob'   => '4294967296'); // yeah, really

                $this_field_max_size = $max_upload_size; // from PHP max
                if ($this_field_max_size > $max_field_sizes[$type]) {
                   $this_field_max_size = $max_field_sizes[$type];
                }
                echo PMA_displayMaximumUploadSize($this_field_max_size) . "\n";
                // do not generate here the MAX_FILE_SIZE, because we should
                // put only one in the form to accommodate the biggest field
                if ($this_field_max_size > $biggest_max_file_size) {
                    $biggest_max_file_size = $this_field_max_size;
                }
            }

            if (!empty($cfg['UploadDir'])) {
                $files = PMA_getFileSelectOptions(PMA_userDir($cfg['UploadDir']));
                if ($files === FALSE) {
                    echo '        <font color="red">' . $strError . '</font><br />' . "\n";
                    echo '        ' . $strWebServerUploadDirectoryError . "\n";
                } elseif (!empty($files)) {
                    echo "<br />\n";
                    echo '    <i>' . $strOr . '</i>' . ' ' . $strWebServerUploadDirectory . ':<br />' . "\n";
                    echo '        <select size="1" name="fields_uploadlocal_' . urlencode($field) . $vkey . '">' . "\n";
                    echo '            <option value="" selected="selected"></option>' . "\n";
                    echo $files;
                    echo '        </select>' . "\n";
                }
            } // end if (web-server upload directory)

            echo '</td>';

        } // end elseif ( binary or blob)
        else {
            // For char or varchar, respect the maximum length (M); for other
            // types (int or float), the length is not a limit on the values that
            // can be entered, so let's be generous (20) (we could also use the
            // real limits for each numeric type)
            // 2004-04-07, it turned out that 20 was not generous enough
            // for the maxlength
            if ($is_char) {
                $fieldsize = (($len > 40) ? 40 : $len);
                $maxlength = $len;
            } else {
                $fieldsize = 20;
                $maxlength = 99;
            } // end if... else...
            ?>
            <td>
            <?php
            echo $backup_field . "\n";
            if ($is_char && ($cfg['CharEditing'] == 'textarea' || strpos($data, "\n") !== FALSE)) {
                echo "\n";
                ?>
                <textarea name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" rows="<?php echo $cfg['CharTextareaRows']; ?>" cols="<?php echo $cfg['CharTextareaCols']; ?>" dir="<?php echo $text_dir; ?>" id="field_<?php echo ($idindex); ?>_3"
                    <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" ><?php echo $special_chars; ?></textarea>
                <?php
            } else {
                ?>
                <input type="text" name="fields<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="<?php echo $special_chars; ?>" size="<?php echo $fieldsize; ?>" maxlength="<?php echo $maxlength; ?>" class="textfield" <?php echo $chg_evt_handler; ?>="return unNullify('<?php echo urlencode($field); ?>', '<?php echo $jsvkey; ?>')" tabindex="<?php echo ($tabindex + $tabindex_for_value); ?>" id="field_<?php echo ($idindex); ?>_3" />
                <?php
                if ($row_table_def['Extra'] == 'auto_increment') {
                    ?>
                    <input type="hidden" name="auto_increment<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="1" />
                    <?php
                } // end if
                if (substr($type, 0, 9) == 'timestamp') {
                    ?>
                    <input type="hidden" name="fields_type<?php echo $vkey; ?>[<?php echo urlencode($field); ?>]" value="timestamp" />
                    <?php
                }
                if ($type == 'date' || $type == 'datetime' || substr($type, 0, 9) == 'timestamp') {
                    ?>
                    <script type="text/javascript" language="javascript">
                    //<![CDATA[
                    document.write('<a title="<?php echo $strCalendar;?>" href="javascript:openCalendar(\'<?php echo PMA_generate_common_url();?>\', \'insertForm\', \'field_<?php echo ($idindex); ?>_3\', \'<?php echo (PMA_MYSQL_INT_VERSION >= 40100 && substr($type, 0, 9) == 'timestamp') ? 'datetime' : substr($type, 0, 9); ?>\')"><img class="calendar" src="<?php echo $pmaThemeImage; ?>b_calendar.png" alt="<?php echo $strCalendar; ?>"/></a>');
                    //]]>
                    </script>
                    <?php
                }
            }
            ?>
            </td>
            <?php
        }
        ?>
        </tr>
        <?php
        $odd_row = !$odd_row;
    } // end for
    $o_rows++;
    echo '  </table><br />';
} // end foreach on multi-edit
?>
    <br />

    <table border="0" cellpadding="5" cellspacing="0">
    <tr>
        <td valign="middle" nowrap="nowrap">
            <select name="submit_type" tabindex="<?php echo ($tabindex + $tabindex_for_value + 1); ?>">
<?php
if (isset($primary_key)) {
    ?>
                <option value="<?php echo $strSave; ?>"><?php echo $strSave; ?></option>
    <?php
}
    ?>
                <option value="<?php echo $strInsertAsNewRow; ?>"><?php echo $strInsertAsNewRow; ?></option>
            </select>
    <?php
echo "\n";

if (!isset($after_insert)) {
    $after_insert = 'back';
}
?>
        </td>
        <td valign="middle">
            &nbsp;&nbsp;&nbsp;<b><?php echo $strAndThen; ?></b>&nbsp;&nbsp;&nbsp;
        </td>
        <td valign="middle" nowrap="nowrap">
            <select name="after_insert">
                <option value="back" <?php echo ($after_insert == 'back' ? 'selected="selected"' : ''); ?>><?php echo $strAfterInsertBack; ?></option>
                <option value="new_insert" <?php echo ($after_insert == 'new_insert' ? 'selected="selected"' : ''); ?>><?php echo $strAfterInsertNewInsert; ?></option>
<?php
if (isset($primary_key)) {
    ?>
                <option value="same_insert" <?php echo ($after_insert == 'same_insert' ? 'selected="selected"' : ''); ?>><?php echo $strAfterInsertSame; ?></option>
    <?php
    // If we have just numeric primary key, we can also edit next
    // in 2.8.2, we were looking for `field_name` = numeric_value
    //if (preg_match('@^[\s]*`[^`]*` = [0-9]+@', $primary_key)) {
    // in 2.9.0, we are looking for `table_name`.`field_name` = numeric_value
    if (preg_match('@^[\s]*`[^`]*`[\.]`[^`]*` = [0-9]+@', $primary_key)) {
        ?>
                <option value="edit_next"><?php echo $strAfterInsertNext; ?></option>
        <?php
    }
}
?>
            </select>
        </td>
    </tr>

    <tr>
        <td>
<?php echo PMA_showHint($strUseTabKey); ?>
        </td>
        <td colspan="3" align="right" valign="middle">
            <input type="submit" value="<?php echo $strGo; ?>" tabindex="<?php echo ($tabindex + $tabindex_for_value + 6); ?>" id="buttonYes" />
            <input type="reset" value="<?php echo $strReset; ?>" tabindex="<?php echo ($tabindex + $tabindex_for_value + 7); ?>" />
        </td>
    </tr>
    </table>
    <?php if ($biggest_max_file_size > 0) {
            echo '        ' . PMA_generateHiddenMaxFileSize($biggest_max_file_size) . "\n";
          } ?>

</form>

<?php
/**
 * Displays the footer
 */
require_once './libraries/footer.inc.php';
?>
