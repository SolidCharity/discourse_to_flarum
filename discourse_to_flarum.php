<?php
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
			die("Export - Connection failed: " . $export->connect_error ."\n"); }
		else {
			echo "Export - Connected successfully\n";
			pg_set_client_encoding($export, 'UNICODE');
			printf("Current character set: %s\n", pg_client_encoding($export)); }

		$this->export = $export;


		$import = new mysqli($importhost, $importusername, $importpassword, $importDBName);
		if ($import->connect_error) {
			die("Import - Connection failed: " . $import->connect_error. "\n"); }
		else {
			echo "Import - Connected successfully\n";

			if (!$import->set_charset("utf8")) {
					printf("Error loading character set utf8: %s\n", $import->error);
					exit(); }
			else { printf("Current character set: %s\n", $import->character_set_name()); }

		$this->import = $import;
		}
	}
}

main();
function main() {
	echo "Starting\n";
	// Load
	$config_file = yaml_parse_file("migrate.yaml");
	// Read
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
	$connections->import->close();

	echo "\n---\n\nMigration completely done! :3\n\n";
}

// Get command type and execute
function execute_command($step_count, $connections, $config_part) {
	// Check if this step is enabled
	$enabled = $config_part['enabled'];
	if ($enabled == false) {
		return; }

  $old_table = $config_part['old-table'];
	$new_table = $config_part['new-table'];

	$action = $config_part['action'];

	if ($action == "COPY") {
    $old_table_name = $config_part['old-table'];
    $new_table_name = $config_part['new-table'];
		$convert_data = $config_part['columns'];

		copyItemsToExportDatabase(	$connections, $step_count, $old_table_name,
																$new_table_name, $convert_data	); }

	else if ($action == "RUN_COMMAND") {
    $command = $config_part['command'];
    runExportSqlCommand($step_count, $connections, $command); }

	else if ($action == "FIRST_AND_LAST") {
		setFirstAndLast($step_count, $connections, $config_part);
	}

	else {
		// Other action types are currently not supported
    echo "Unknown action type: $action"; }
}

// Runs funtions on both connection to move data
function copyItemsToExportDatabase($connections, $step_count, $old_table_name, $new_table_name, $convert_data) {
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
	echo "Exporting: $sql_req \n";

	// Run generated sql-command
	$result = pg_query($connections->export, $sql_req);
	if (!$result) {
		die("Error with SQL query:\n $sql_req\n"); }

	echo "\n";

	if (pg_num_rows($result)) {
		// Start copy operation if everything is okay
		$data_total = 0;
		$data_success = 0;

		// For each item in table
		while ($row = pg_fetch_assoc($result)) {
			$data_total++;

			// Trow Data in other Table_DB
			$insert_base = "INSERT INTO ".$new_table_name." ( ";

			$insert_values = "";
			$counter = 0;
			foreach ($convert_data as $key => $value) {
				if ($counter != 0) {
					$insert_values = "$insert_values, ";
				}
				$insert_values = "$insert_values $value";
				$counter++;
			}

			$insert_v = " ) VALUES (";

			$insert_list = "";
			$counter = 0;
			print_r($convert_data);
			foreach ($convert_data as $key => $value) {
				if ($counter != 0) {
					$insert_list = "$insert_list, ";
				}

				$new_data_value = getValue($key, $connections->import, $row);

				if ($key == "color") {
					$new_data_value = randomColor();
				}
				$insert_list = "$insert_list'$new_data_value'";
				$counter++;
			}

			$query = $insert_base.$insert_values.$insert_v.$insert_list." );";

			// Run generated sql-command on export database...
			echo "Importing new item... ($data_total)\n";
			$res = $connections->import->query($query);
			if ($res === false) {
				echo "Wrong SQL: " . $query . "\n Error: " . $connections->import->error . "\n";
			}

			else {
				echo "Done.\n";
				$data_success++;
			}
		}

		// If this operation has finished
		echo "\n---\n\n$data_success" . ' out of '. $data_total .' total items converted.'."\n";
		}
		else {
		echo "Something went wrong. :/";
		}
}
// runs a single non-copy$connections->import->query($sql) SQL Line
function runExportSqlCommand($step_count, $connections, $sql_command) {
	// Check connection
	if ($connections->import->connect_error) {
		die("Connection failed: " . $connections->import->connect_error); }

	// Executing sql-command
	echo "> $sql_command\n";
	$res = $connections->import->query($sql_command);

	// Check success
	if ($res === false) {
		echo "Wrong SQL: " . $sql_command . " Error: " . $connections->import->error . "\n"; }
}
// Sort of start_post_id and last_post_id
function setFirstAndLast($step_count, $connections, $config_part) {
	if ($config_part['enabled'] == False) {
		return;
	}
	$sql = "SELECT "."id, discussion_id FROM ".$config_part['from-table'];
	$result = $connections->import->query($sql);
	if (!$result) {
		die("Error with SQL query:\n $sql\n"); }

	$sorted = array();

	while ($row = $result->fetch_assoc()) {
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
		echo $sql;
		$res = $connections->import->query($sql);
		if ($res === false) {
			echo "Wrong SQL: " . $query . "\n Error: " . $connections->import->error . "\n";
		}
	}
}
// Removes any syntax elements and returns the raw key
function getKey($orginal) {
	return findFirstArg($orginal, array("?", "+", "-", "*"));
}
// Returns the new value, depends on the syntax elements
function getValue($orginal, $importDbConnection, $row) {
	$value = findValue($orginal, $importDbConnection, $row);

	// Increments the value by one if the syntax elements in the configuration want to have that
	if (endsWith($orginal, "++")) {
		$value++; }

	else if (endsWith($orginal, "--")) {
		$value--; }

	// Check if there is an expression
	$array = explode("?", $orginal);
	if (sizeof($array) == 2) {
		// The new value is "true", if the value from the old table equals to the expected value
		$value = $array[1] == $value; }

	return $value;
}
// Reads the correct value from the row and sets special flags if there are any defined in the configuration
function findValue($orginal, $importDbConnection, $row) {
	$theKey = getKey($orginal);

	if ($orginal == "rnd_color**") {
		return randomColor();
	}
	else if (endsWith($orginal, "*")) {
		return formatText($importDbConnection, $row[$theKey]);
	}
	else {
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
// Waits for input in the console
function readInputLine() {
        $handle = fopen ("php://stdin","r");
        $line = fgets($handle);
        return trim($line);
}
// Returns a random generated color
function randomColor() {
	return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
}
// Formates Strings for Flarum
function formatText($connection, $text) {
	$text = preg_replace('#\:\w+#', '', $text);
	$text = convertBBCodeToHTML($text);
	$text = str_replace("&quot;","\"",$text);
	$text = preg_replace('|[[\/\!]*?[^\[\]]*?]|si', '', $text);
	$text = trimSmileys($text);
	$text = fixUserLinks($text);
	#$text = fixCodeHighlighting($text);
	// Wrap text lines with paragraph tags
	$explodedText = explode("\n", $text);
	foreach ($explodedText as $key => $value) {
		if (strlen($value) > 1) // Only wrap in a paragraph tag if the line has actual text
			$explodedText[$key] = '<p>' . $value . '</p>';
	}
	$text = implode("\n", $explodedText);

	$wrapTag = strpos($text, '&gt;') > 0 ? "r" : "t"; // Posts with quotes need to be 'richtext'
	$text = sprintf('<%s>%s</%s>', $wrapTag, $text, $wrapTag);
	return $connection->real_escape_string($text);
}
// This function is able to convert BBCode to HTML code
function convertBBCodeToHTML($bbcode) {
	$bbcode = preg_replace('#\[b](.+)\[\/b]#', "<b>$1</b>", $bbcode);
	$bbcode = preg_replace('#\[i](.+)\[\/i]#', "<i>$1</i>", $bbcode);
	$bbcode = preg_replace('#\[u](.+)\[\/u]#', "<u>$1</u>", $bbcode);

	$bbcode = preg_replace('#\[img](.+?)\[\/img]#is', "<img src='$1'\>", $bbcode);
	$bbcode = preg_replace('#\[quote=(.+?)](.+?)\[\/quote]#is', "<QUOTE><i>&gt;</i>$2</QUOTE>", $bbcode);
	$bbcode = preg_replace('#\[code:\w+](.+?)\[\/code:\w+]#is', "<CODE class='hljs'>$1<CODE>", $bbcode);
	$bbcode = preg_replace('#\[pre](.+?)\[\/pre]#is', "<code>$1<code>", $bbcode);
	$bbcode = preg_replace('#\[\*](.+?)\[\/\*]#is', "<li>$1</li>", $bbcode);
	$bbcode = preg_replace('#\[color=\#\w+](.+?)\[\/color]#is', "$1", $bbcode);
	$bbcode = preg_replace('#\[url=(.+?)](.+?)\[\/url]#is', "<a href='$1'>$2</a>", $bbcode);
	$bbcode = preg_replace('#\[url](.+?)\[\/url]#is', "<a href='$1'>$1</a>", $bbcode);
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
			if (sizeof($lenUsername) == 0) {
			continue;
			}
			$username = substr($split, 0, $lenUsername);
			$plain = substr($split, $lenUsername);

			$result = "$result<USERMENTION id=\"-1\" username=\"$username\">@$username</USERMENTION>$plain";
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

?>
