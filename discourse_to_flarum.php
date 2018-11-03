<?php
include __DIR__ . '/vendor/autoload.php';
use s9e\TextFormatter\Bundles\Forum as TextFormatter;

//Connection Storage
class connections {
	public $export = null;
	public $import = null;

	function __construct($old_auth, $new_auth) {
		echo "Converting...\n";
		$exporthost = $old_auth['host'];
		$exportusername = $old_auth['user'];
		$exportpassword = $old_auth['password'];
		$exportDBName = $old_auth['name'];

		$importhost = $new_auth['host'];
		$importusername = $new_auth['user'];
		$importpassword = $new_auth['password'];
		$importDBName = $new_auth['name'];

		$export = pg_connect("host=/var/run/postgresql port=5432 dbname=$exportDBName user=$exportusername password=$exportpassword");
		if ($export === false) {
			die("Export - Connection failed: " . $export->connect_error ."\n");
		} else {
			echo "Export - Connected successfully\n";
			pg_set_client_encoding($export, 'UNICODE');
			printf("Current character set: %s\n", pg_client_encoding($export));
		}

		$this->export = $export;

		try {
			$import = new PDO("mysql:host=".$importhost.";dbname=".$importDBName.";charset=utf8mb4", $importusername, $importpassword,
				// workaround for PHP < 5.3.6; see https://stackoverflow.com/a/21373793
				array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"));
		} catch (PDOException $e) {
			echo "Error establishing import connection: " . $e->getMessage() . "\n";
			$import = null;
		}

		if (!$import) {
			die("Import - Connection failed\n");
		} else {
			echo "Import - Connected successfully\n";

			$this->import = $import;
		}
	}
}

main();
function main() {
	global $fl_prefix;
	echo "Starting\n";

	// init TextFormatter
	global $parser;
	$configurator = new s9e\TextFormatter\Configurator;
	$configurator->plugins->load('Litedown');
	$configurator->plugins->load('BBCodes');
	$configurator->BBCodes->addFromRepository('CODE');
	$configurator->BBCodes->addFromRepository('QUOTE');
	$configurator->BBCodes->addFromRepository('B');
	$configurator->BBCodes->addFromRepository('I');
	$configurator->BBCodes->addFromRepository('U');
	$configurator->BBCodes->addFromRepository('IMG');
	$configurator->BBCodes->addFromRepository('URL');
	$configurator->BBCodes->addFromRepository('LIST');

	// see https://github.com/flarum/core/blob/master/src/Formatter/Formatter.php
	$configurator->rootRules->enableAutoLineBreaks();
	$configurator->Escaper;
	$configurator->Autoemail;
	$configurator->Autolink;
	$configurator->tags->onDuplicate('replace');
	extract($configurator->finalize());

	// Load
	$config_file = yaml_parse_file("migrate.yaml");

	// Read
	$fl_prefix = $config_file['flarum_table_prefix'];
	$old_auth = $config_file['authentification']['old-database'];
	$new_auth = $config_file['authentification']['new-database'];

	// Run all steps for forum migration...
	echo "Run steps...\n";
	$step_count = 0;

	$connections = new connections($old_auth, $new_auth);

	foreach ($config_file['steps'] as $step) {
			$step_count++;
			execute_command($step_count, $connections, $step);
	}
	// Done

	// Close connection to the database
	pg_close($connections->export);
	unset($connections->import);
	$connections->import = null;

	echo "\n---\n\nMigration completely done! :3\n\n";
}

// Get command type and execute
function execute_command($step_count, $connections, $config_part) {
	global $fl_prefix;

	// Check if this step is enabled
	$enabled = array_key_exists('enabled', $config_part)?$config_part['enabled']:true;
	if ($enabled == false) {
		return; }

	$action = $config_part['action'];

	if ($action == "COPY") {
		$old_table_name = $config_part['old-table'];
		$new_table_name = str_replace("fl_", $fl_prefix, $config_part['new-table']);
		$convert_data = $config_part['columns'];
		$join = (array_key_exists('join', $config_part)?$config_part['join']:'');
		$where = (array_key_exists('where', $config_part)?$config_part['where']:'');
		$orderby = (array_key_exists('orderby', $config_part)?$config_part['orderby']:'');

		copyItemsToExportDatabase(
			$connections, $step_count, $old_table_name,
			$new_table_name, $join, $where, $orderby, $convert_data);
	}

	else if ($action == "RUN_COMMAND") {
		$command = str_replace("fl_", $fl_prefix, $config_part['command']);
		runExportSqlCommand($step_count, $connections, $command);
	}

	else if ($action == "FIRST_AND_LAST") {
		setFirstAndLast($step_count, $connections, $config_part);
	}

	else if ($action == "SET_PARENT_TAGS") {
		setParentIds($connections, $config_part);
	}

	else {
		// Other action types are currently not supported
		echo "Unknown action type: $action";
	}
}

// Runs funtions on both connections to move data
function copyItemsToExportDatabase($connections, $step_count, $old_table_name, $new_table_name, $join, $where, $orderby, $convert_data) {
	// Convert & copy data
	$counter = 0;

	$sql_base = "SELECT ";
	$sql_keys = "";
	foreach ($convert_data as $key => $value) {
		$theKey = getKey($key);
		if (empty($theKey)) {
			continue;
		}

		if ($counter != 0) {
			$sql_keys = "$sql_keys, ";
		}
		$sql_keys = "$sql_keys" . $theKey;
		$counter++;
	}
	$sql_back = " FROM $old_table_name";
	$sql_req = $sql_base.$sql_keys.$sql_back;
	if (!empty($join)) {
		$sql_req .= $join;
		if (!empty($where)) {
			$sql_req .= " AND ".$where;
		}
	} else if (!empty($where)) {
		$sql_req .= " WHERE ".$where;
	}
	if (!empty($orderby)) {
		$sql_req .= " ORDER BY ".$orderby;
	}
	echo "Exporting: $sql_req \n";

	// Run generated sql-command
	$result = pg_query($connections->export, $sql_req);
	if (!$result) {
		die("Error with SQL query:\n $sql_req\n");
	}

	echo "\n";

	if (pg_num_rows($result)) {
		$num_rows = pg_num_rows($result);
		$progress_mod = 1;
		if ($num_rows > 10) {
			$progress_mod = $num_rows/5;
		} 

		// Start copy operation if everything is okay
		$data_total = 0;
		$data_success = 0;

		// For each item in table
		while ($row = pg_fetch_assoc($result)) {
			$data_total++;

			// Throw Data in other Table_DB
			$insert_base = "INSERT INTO ".$new_table_name." ( ";

			$insert_values = "";
			$counter = 0;
			foreach ($convert_data as $key => $value) {
				if ($counter != 0) {
					$insert_values .= ", ";
				}
				$insert_values .= $value;
				$counter++;
			}

			$insert_v = " ) VALUES (";

			$insert_list = "";
			$params = array();
			$counter = 0;
			// print_r($convert_data);
			foreach ($convert_data as $key => $value) {
				if ($counter != 0) {
					$insert_list = "$insert_list, ";
				}

				$new_data_value = getValue($key, $connections->import, $row);

				if ($key == "color") {
					$new_data_value = "#".$new_data_value;
				}
				if ($key == "parent_id") {
					return setParentIds($connections, $convert_data, $new_table_name);
				}
				if ((strlen($new_data_value) == 0) && endsWith($value, "_id")) {
					$insert_list .= "0";
				} else if ((strlen($new_data_value) == 0) && (strpos($value, "_time") !== false)) {
					$insert_list .= "NULL";
				} else if ($value == "content") {
					$insert_list.="?";
					$params[] = $new_data_value;
				} else {
					$insert_list .= "'$new_data_value'";
				}
				$counter++;
			}

			$query = $insert_base.$insert_values.$insert_v.$insert_list." );";

			// Run generated sql-command on export database...
			if ($data_total % $progress_mod == 0) {
				echo "Importing new $new_table_name item... ($data_total)\n";
			}
			$stmt = $connections->import->prepare($query);
			if (!$stmt->execute($params)) {
				echo "Wrong SQL: " . $query . "\n Error: " .  $stmt->errorCode(). ": ". print_r($stmt->errorInfo(),true) . "\n";
				die();
			}
			else {
				// echo "Done.\n";
				$data_success++;
			}
		}

		// If this operation has finished
		echo "\n---\n\n$data_success" . ' out of '. $data_total ." total $new_table_name items converted."."\n";
	}
	else {
		echo "Something went wrong. :/";
	}
}
// runs a single non-copy$connections->import->query($sql) SQL Line
function runExportSqlCommand($step_count, $connections, $sql_command) {
	// Check connection
	if (!$connections->import) {
		die("Connection not established.");
	}

	// Executing sql-command
	echo "> $sql_command\n";
	$res = $connections->import->query($sql_command);

	// Check success
	if ($res === false) {
		echo "Wrong SQL: " . $sql_command . " Error: " . $connections->import->error . "\n";
		die();
	}
}

// Sort of start_post_id and last_post_id
function setFirstAndLast($step_count, $connections, $config_part) {
	global $fl_prefix;
	if ($config_part['enabled'] == False) {
		return;
	}
	$sql = "SELECT "."id, discussion_id FROM ".str_replace("fl_", $fl_prefix, $config_part['from-table']);
	$stmt = $connections->import->query($sql);
	if (!$stmt) {
		die("Error with SQL query:\n $sql\n");
	}

	$sorted = array();

	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		if ( array_key_exists ((string)$row['discussion_id'], $sorted) ) {
			if ($row['id'] < $sorted[(string)$row['discussion_id']]['low']) {
				$sorted[(string)$row['discussion_id']]['low'] = $row['id'];
			}
			else if ($row['id'] > $sorted[(string)$row['discussion_id']]['high']) {
				$sorted[(string)$row['discussion_id']]['high'] = $row['id'];
			}
			else {
				continue;
			}
		}
		else {
			$sorted[(string)$row['discussion_id']] = array();
			$sorted[(string)$row['discussion_id']]['low'] = $row['id'];
			$sorted[(string)$row['discussion_id']]['high'] = $row['id'];
		}
	}
	foreach ($sorted as $disc => $post) {
		$sql = "UPDATE ".(string)$config_part['table']." SET start_post_id = ".(string)$post['low'].", last_post_id = ".(string)$post['high']." WHERE id = ".(string)$disc;
		echo $sql."\n";
		$res = $connections->import->query($sql);
		if ($res === false) {
			echo "Wrong SQL: " . $query . "\n Error: " . $connections->import->error . "\n";
			die();
		}
	}
}
// Removes any syntax elements and returns the raw key
function getKey($original) {
	return findFirstArg($original, array("?", "+", "-", "*"));
}
// Returns the new value, depends on the syntax elements
function getValue($original, $importDbConnection, $row) {
	$value = findValue($original, $importDbConnection, $row);

	// Increments the value by one if the syntax elements in the configuration want to have that
	if (endsWith($original, "++")) {
		$value++;
	}

	else if (endsWith($original, "--")) {
		$value--;
	}

	// Check if there is an expression
	$array = explode("?", $original);
	if (sizeof($array) == 2) {
		// The new value is "true", if the value from the old table equals to the expected value
		$value = $array[1] == $value;
	}

	return $value;
}
// Reads the correct value from the row and sets special flags if there are any defined in the configuration
function findValue($original, $importDbConnection, $row) {
	$theKey = getKey($original);

	if (endsWith($original, "*")) {
		return formatTextToXml($importDbConnection, $row[$theKey]);
	}
	else {
		if (!array_key_exists($theKey, $row) && strpos($theKey, '.') !== false) {
			$theKey = substr($theKey, strpos($theKey, '.')+1);
		}

		return addslashes($row[$theKey]);
	}
}
// Splits the given string by all characters in the array and returns the first argument
function findFirstArg($str, $array) {
	foreach ($array as $split) {
		$str = explode($split, $str)[0];
	}
	return $str;
}
// Starts-with method like in other programming languages
function startsWith($input, $check) {
	return (substr($input, 0, strlen($check)) === $required);
}
// Ends-with method like in other programming languages
function endsWith($input, $check) {
	return substr($input, -strlen($check)) === $check;
}
// Format Strings for Flarum
function formatText($connection, $text) {
	$text = preg_replace('#\:\w+#', '', $text);
	$text = str_replace("&quot;","\"",$text);
	$text = preg_replace('|[[\/\!]*?[^\[\]]*?]|si', '', $text);
	$text = trimSmileys($text);
	$text = fixUserLinks($text);
	#$text = fixCodeHighlighting($text);
	// Wrap text lines with paragraph tags
	$explodedText = explode("\n", $text);
	foreach ($explodedText as $key => $value) {
		if (strlen($value) > 1) {// Only wrap in a paragraph tag if the line has actual text
			$explodedText[$key] = '<p>' . $value . '</p>';
		}
	}
	$text = implode("\n", $explodedText);

	$wrapTag = strpos($text, '&gt;') > 0 ? "r" : "t"; // Posts with quotes need to be 'richtext'
	$wrapTag = "r"; // To make links work
	$text = sprintf('<%s>%s</%s>', $wrapTag, $text, $wrapTag);
	return $text;
}

// convert messages from Markup and/or BBCode into Flarum-compatible XML format
function formatTextToXML($connection, $text) {
	global $parser;

	if (strpos($text, "`") === false && strpos($text, "<pre>") === false && strpos($text, "\n    ") === false) {
		if (strpos($text, "<") !== false && strpos($text, "</") !== false) {
			// this is HTML
			return formatText($connection, $text);
		}
	}

	$text = str_replace("\n<pre>", "\n```", $text);
	$text = str_replace("</pre>\n", "```\n", $text);
	$text = $parser->parse($text);
	$text = trimSmileys($text);
	$text = fixUserLinks($text);

	return $text;
}

// This function is able to convert BBCode to HTML code
function convertBBCodeToHTML($bbcode) {
	$bbcode = preg_replace('#\[b](.+)\[\/b]#', "<b>$1</b>", $bbcode);
	$bbcode = preg_replace('#\[i](.+)\[\/i]#', "<i>$1</i>", $bbcode);
	$bbcode = preg_replace('#\[u](.+)\[\/u]#', "<u>$1</u>", $bbcode);

	$bbcode = preg_replace('#\[img](.+?)\[\/img]#is', "<img src='$1'\>", $bbcode);
	$bbcode = preg_replace('#\[quote=(.+?)](.+?)\[\/quote]#is', "<BLOCKQUOTE><i>&gt;</i>$2</BLOCKQUOTE>", $bbcode);
	$bbcode = preg_replace('#\[code:\w+](.+?)\[\/code:\w+]#is', "<CODE class='hljs'>$1<CODE>", $bbcode);
	$bbcode = preg_replace('#\[pre](.+?)\[\/pre]#is', "<code>$1<code>", $bbcode);
	$bbcode = preg_replace('#\[\*](.+?)\[\/\*]#is', "<li>$1</li>", $bbcode);
	$bbcode = preg_replace('#\[color=\#\w+](.+?)\[\/color]#is', "$1", $bbcode);
	$bbcode = preg_replace('#\<a href\=\"(.*?)\" .*? .*?\>.*?<\/a\>#', ' <URL url="$1">$1</URL> ', $bbcode);
	$bbcode = preg_replace('#\<a href\=\"(.*?)\"\>.*?<\/a\>#', ' <URL url="$1">$1</URL> ', $bbcode);
	$bbcode = preg_replace('#\[url=(.+?)](.+?)\[\/url]#is', ' <URL url="$1">$1</URL> ', $bbcode);
	$bbcode = preg_replace('#\[url](.+?)\[\/url]#is', ' <URL url="$1">$1</URL> ', $bbcode);
	$bbcode = preg_replace('#\[list](.+?)\[\/list]#is', "<ul>$1</ul>", $bbcode);

	$bbcode = preg_replace('#\[size=200](.+?)\[\/size]#is', "<h1>$1</h1>", $bbcode);
	$bbcode = preg_replace('#\[size=170](.+?)\[\/size]#is', "<h2>$1</h2>", $bbcode);
	$bbcode = preg_replace('#\[size=150](.+?)\[\/size]#is', "<h3>$1</h3>", $bbcode);
	$bbcode = preg_replace('#\[size=120](.+?)\[\/size]#is', "<h4>$1</h4>", $bbcode);
	$bbcode = preg_replace('#\[size=85](.+?)\[\/size]#is', "<h5>$1</h5>", $bbcode);

	return $bbcode;
}
// Changes the format of the smileys
function trimSmileys($postText) {
	$startStr = "<!--";
	$endStr = 'alt="';

	$startStr1 = '" title';
	$endStr1 = " -->";

	$emoticonsCount = substr_count($postText, '<img src="{SMILIES_PATH}');

	for ($i=0; $i < $emoticonsCount; $i++) {
		$startPos = strpos($postText, $startStr);
		$endPos = strpos($postText, $endStr);

		$postText = substr_replace($postText, NULL, $startPos, $endPos-$startPos+strlen($endStr));

		$startPos1 = strpos($postText, $startStr1);
		$endPos1 = strpos($postText, $endStr1);

		$postText = substr_replace($postText, NULL, $startPos1, $endPos1-$startPos1+strlen($endStr1));
	}

	return $postText;
}
// Tries to fix the old format () and convert it to the new one
function fixUserLinks($post) {
	try {
		$result = "";
		$count = 0;
		foreach (explode("@", $post) as $split) {
			$count++;
			if($count == 1) {
				$result = $split;
				continue;
			}

			$lenUsername = getLengthOfUsername($split);
			if ($lenUsername == 0) {
				$result .= '@ '.$split;
				continue;
			}
			$username = substr($split, 0, $lenUsername);
			$plain = substr($split, $lenUsername);

			$result .= "<USERMENTION id=\"-1\" username=\"$username\">@$username</USERMENTION>$plain";
		}
		return $result;
	}
	catch(Exception $ex) {
		echo "Warning: An error occurred while trying to fix user links";
		return $post;
	}

}
// Returns the length of the user-name by the given text
function getLengthOfUsername($username) {
	$count = 0;
	$length = strlen($username);

	//Count until there is an invalid character in the text
	for ($i=0; $i<$length; $i++) {
		$chr = $username[$i];
		if ($chr >= 'a' && $chr <= 'z' || $chr >= 'A' && $chr <= 'Z' || $chr >= '0' && $chr <= '9' || $chr == '_') {
			$count++;
		} else {
			break;
		}
	}
	return $count;
}
// This function will replace the code highlighting from the old forum format to the new one
function fixCodeHighlighting($post) {
	// For single line markdowns
	$post = preg_replace("/<code(.*?)>/is", "<C><s>`</s>", $post);
	$post = str_replace("</code>", "<e>`</e></C>", $post);

	// For code blocks with multiple line
	$array = explode("```", $post);
	$count = 0;
	$result = "";
	foreach ($array as $split) {
		try {
			if ($count == 0 || $count + 1 == sizeof($array)) {
				$result = "$result$split";
				continue;
			}
			$result = "$result<CODE><s>```</s>$split<e>```</e></CODE>";
		} finally {
			$count++;
		}
	}
	return $result;
}
// Sets Discussion Tag Parents to all tags
function setParentIds($connections, $config_part) {
	global $fl_prefix;
	$from = $config_part['from'];
	$into = $config_part['into'];

	$dump = array();

	$sql_base = "SELECT id, parent_id ";
	$sql_back = "FROM ".$fl_prefix."tags ORDER BY id DESC";
	$sql_req = $sql_base.$sql_back;
	echo "Exporting: $sql_req \n";

	$result_tags = $connections->import->query($sql_req);
	if ($result_tags === false) {
		die("Error with SQL query:\n $sql_req\n");
	}
	while ($tag = $result_tags->fetch(PDO::FETCH_ASSOC)) {
		if ($tag['parent_id'] == null) {
			continue;
		}
		$sql = "SELECT discussion_id FROM ".$fl_prefix."discussions_tags WHERE tag_id = ".$tag['id'];
		$result_disctags = $connections->import->query($sql);
		if ($result_disctags === false) {
			die("error with SQL query:\n $sql");
		}
		while ($disctags = $result_disctags->fetch_assoc()) {
			$new = "INSERT INTO ".$fl_prefix."discussions_tags ( discussion_id, tag_id ) VALUES ( ".$disctags['discussion_id'].", ".$tag['parent_id'].")";
			$result_ = $connections->import->query($new);
		}

	}



}

?>
